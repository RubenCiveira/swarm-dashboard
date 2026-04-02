<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class AuthController
{
    public function __construct(private \PDO $pdo) {}

    // POST /api/auth/token
    // Requiere sesión web activa. Genera un token de API y lo devuelve una sola vez.
    public function createToken(Request $request, Response $response): Response
    {
        if (!$this->hasWebSession()) {
            $response->getBody()->write(json_encode(['error' => 'Se requiere sesión web activa']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(401);
        }

        $data  = $request->getParsedBody() ?? [];
        $label = $data['label'] ?? null;
        $expiresAt = null;
        if (!empty($data['expires_in_days']) && is_numeric($data['expires_in_days'])) {
            $expiresAt = date('Y-m-d H:i:s', strtotime('+' . (int)$data['expires_in_days'] . ' days'));
        }

        $token = $this->generateSecureToken();
        $email = $_SESSION['user']['email'];
        $name  = $_SESSION['user']['name'];

        $stmt = $this->pdo->prepare(
            'INSERT INTO api_tokens (token, email, name, label, expires_at) VALUES (:token, :email, :name, :label, :expires_at)'
        );
        $stmt->execute([
            'token'      => $token,
            'email'      => $email,
            'name'       => $name,
            'label'      => $label,
            'expires_at' => $expiresAt,
        ]);
        $id = $this->pdo->lastInsertId();

        $response->getBody()->write(json_encode([
            'id'         => (int)$id,
            'token'      => $token,
            'label'      => $label,
            'expires_at' => $expiresAt,
        ]));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(201);
    }

    // GET /api/auth/tokens
    // Lista los tokens del usuario autenticado (sesión web o token de API).
    public function listTokens(Request $request, Response $response): Response
    {
        $email = $this->resolveEmail($request);
        if ($email === null) {
            $response->getBody()->write(json_encode(['error' => 'No autenticado']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(401);
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, label, expires_at, created_at FROM api_tokens WHERE email = :email ORDER BY id DESC'
        );
        $stmt->execute(['email' => $email]);
        $tokens = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $response->getBody()->write(json_encode($tokens));
        return $response->withHeader('Content-Type', 'application/json');
    }

    // DELETE /api/auth/tokens/{id}
    // Revoca un token del usuario autenticado.
    public function deleteToken(Request $request, Response $response, array $args): Response
    {
        $email = $this->resolveEmail($request);
        if ($email === null) {
            $response->getBody()->write(json_encode(['error' => 'No autenticado']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(401);
        }

        $stmt = $this->pdo->prepare(
            'DELETE FROM api_tokens WHERE id = :id AND email = :email'
        );
        $stmt->execute(['id' => (int)$args['id'], 'email' => $email]);

        if ($stmt->rowCount() === 0) {
            $response->getBody()->write(json_encode(['error' => 'Token no encontrado']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        $response->getBody()->write(json_encode(['success' => true]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    // GET /api/auth/login-request
    // El CLI llama aquí para iniciar el flujo de login. Devuelve código + URL de aprobación.
    public function createLoginRequest(Request $request, Response $response): Response
    {
        $code      = $this->generateCode();
        $expiresAt = date('Y-m-d H:i:s', strtotime('+5 minutes'));

        $stmt = $this->pdo->prepare(
            'INSERT INTO login_requests (code, expires_at) VALUES (:code, :expires_at)'
        );
        $stmt->execute(['code' => $code, 'expires_at' => $expiresAt]);

        $scheme   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $basePath = rtrim($_SERVER['SCRIPT_NAME'] ? str_replace('/index.php', '', $_SERVER['SCRIPT_NAME']) : '', '/');
        $url      = $scheme . '://' . $host . $basePath . '/auth/approve/' . $code;

        $response->getBody()->write(json_encode([
            'code'       => $code,
            'url'        => $url,
            'expires_in' => 300,
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    // POST /api/auth/login-request/{code}/poll
    // El CLI sondea este endpoint hasta obtener el token.
    public function pollLoginRequest(Request $request, Response $response, array $args): Response
    {
        $code = $args['code'];

        $stmt = $this->pdo->prepare(
            'SELECT lr.approved, lr.expires_at, lr.token_id, t.token, t.email, t.name
             FROM login_requests lr
             LEFT JOIN api_tokens t ON t.id = lr.token_id
             WHERE lr.code = :code LIMIT 1'
        );
        $stmt->execute(['code' => $code]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            $response->getBody()->write(json_encode(['error' => 'Código no encontrado']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        if ($row['expires_at'] < date('Y-m-d H:i:s')) {
            $response->getBody()->write(json_encode(['error' => 'Código expirado']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(410);
        }

        if (!$row['approved']) {
            $response->getBody()->write(json_encode(['status' => 'pending']));
            return $response->withHeader('Content-Type', 'application/json');
        }

        $response->getBody()->write(json_encode([
            'status' => 'approved',
            'token'  => $row['token'],
            'email'  => $row['email'],
            'name'   => $row['name'],
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    // POST /api/auth/login-request/{code}/approve
    // La página web llama aquí cuando el usuario pulsa "Autorizar CLI".
    // Requiere sesión web activa.
    public function approveLoginRequest(Request $request, Response $response, array $args): Response
    {
        if (!$this->hasWebSession()) {
            $response->getBody()->write(json_encode(['error' => 'Se requiere sesión web activa']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(401);
        }

        $code = $args['code'];

        $stmt = $this->pdo->prepare(
            'SELECT id, approved, expires_at FROM login_requests WHERE code = :code LIMIT 1'
        );
        $stmt->execute(['code' => $code]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            $response->getBody()->write(json_encode(['error' => 'Código no encontrado']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        if ($row['expires_at'] < date('Y-m-d H:i:s')) {
            $response->getBody()->write(json_encode(['error' => 'Código expirado']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(410);
        }

        if ($row['approved']) {
            $response->getBody()->write(json_encode(['success' => true, 'already_approved' => true]));
            return $response->withHeader('Content-Type', 'application/json');
        }

        // Generar token y asociarlo al login_request
        $token = $this->generateSecureToken();
        $email = $_SESSION['user']['email'];
        $name  = $_SESSION['user']['name'];

        $stmtToken = $this->pdo->prepare(
            'INSERT INTO api_tokens (token, email, name, label) VALUES (:token, :email, :name, :label)'
        );
        $stmtToken->execute([
            'token' => $token,
            'email' => $email,
            'name'  => $name,
            'label' => 'CLI login',
        ]);
        $tokenId = $this->pdo->lastInsertId();

        $stmtApprove = $this->pdo->prepare(
            'UPDATE login_requests SET approved = 1, token_id = :token_id WHERE id = :id'
        );
        $stmtApprove->execute(['token_id' => $tokenId, 'id' => $row['id']]);

        $response->getBody()->write(json_encode(['success' => true]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    // GET /auth/approve/{code}
    // Página HTML que muestra al usuario el formulario de aprobación.
    public function approvePage(Request $request, Response $response, array $args): Response
    {
        if (!$this->hasWebSession()) {
            $basePath = rtrim(str_replace('/index.php', '', $_SERVER['SCRIPT_NAME'] ?? ''), '/');
            return $response->withHeader('Location', $basePath . '/')->withStatus(302);
        }

        $code = htmlspecialchars($args['code'], ENT_QUOTES, 'UTF-8');
        $name = htmlspecialchars($_SESSION['user']['name'], ENT_QUOTES, 'UTF-8');
        $email = htmlspecialchars($_SESSION['user']['email'], ENT_QUOTES, 'UTF-8');

        $html = file_get_contents(__DIR__ . '/../templates/auth-approve.html');
        $html = str_replace(['{{code}}', '{{name}}', '{{email}}'], [$code, $name, $email], $html);

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html');
    }

    // --- Helpers ---

    private function hasWebSession(): bool
    {
        return !empty($_SESSION['user']['email']);
    }

    private function resolveEmail(Request $request): ?string
    {
        // Primero buscar en atributo inyectado por ApiTokenMiddleware
        $apiUser = $request->getAttribute('api_user');
        if ($apiUser !== null) {
            return $apiUser['email'];
        }
        // Fallback a sesión web
        return $_SESSION['user']['email'] ?? null;
    }

    private function generateSecureToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    private function generateCode(): string
    {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code  = '';
        for ($i = 0; $i < 8; $i++) {
            $code .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $code;
    }
}
