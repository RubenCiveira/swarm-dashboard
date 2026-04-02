<?php

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

class ApiTokenMiddleware implements MiddlewareInterface
{
    public function __construct(private \PDO $pdo) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $authorization = $request->getHeaderLine('Authorization');

        if (!str_starts_with($authorization, 'Bearer ')) {
            return $handler->handle($request);
        }

        $token = substr($authorization, 7);
        $row = $this->findToken($token);

        if ($row === null) {
            return $this->unauthorized('Token inválido');
        }

        if ($row['expires_at'] !== null && $row['expires_at'] < date('Y-m-d H:i:s')) {
            return $this->unauthorized('Token expirado');
        }

        $request = $request->withAttribute('api_user', [
            'email' => $row['email'],
            'name'  => $row['name'],
            'token_id' => $row['id'],
        ]);

        return $handler->handle($request);
    }

    private function findToken(string $token): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, email, name, expires_at FROM api_tokens WHERE token = :token LIMIT 1'
        );
        $stmt->execute(['token' => $token]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function unauthorized(string $message): ResponseInterface
    {
        $response = new Response();
        $response->getBody()->write(json_encode(['error' => $message]));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(401);
    }
}
