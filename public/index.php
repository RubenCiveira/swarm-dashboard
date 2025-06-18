<?php
require_once '../vendor/autoload.php';
require_once '../src/Database.php';
require_once '../src/AppManager.php';
require_once '../src/DatabaseManager.php';

use Slim\Factory\AppFactory;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

// Initialize database
$database = new Database();
$appManager = new AppManager($database);
$databaseManager = new DatabaseManager($database);

$app = AppFactory::create();
$scriptName = $_SERVER['SCRIPT_NAME']; // Devuelve algo como "/midashboard/index.php"
$basePath = str_replace('/index.php', '', $scriptName); // "/midashboard"
$app->setBasePath($basePath);
$app->addBodyParsingMiddleware();
$app->addErrorMiddleware(true, true, true);

// CORS middleware
$app->add(function (Request $request, $handler) {
    $response = $handler->handle($request);
    return $response
        ->withHeader('Access-Control-Allow-Origin', '*')
        ->withHeader('Access-Control-Allow-Headers', 'X-Requested-With, Content-Type, Accept, Origin, Authorization')
        ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, PATCH, OPTIONS');
});

// Serve static files
$app->get('/', function (Request $request, Response $response) {
    $html = file_get_contents(__DIR__ . '/dashboard.html');
    $response->getBody()->write($html);
    return $response->withHeader('Content-Type', 'text/html');
});

// API Routes for Apps
$app->get('/api/apps', function (Request $request, Response $response) use ($appManager) {
    $apps = $appManager->getAllApps();
    $response->getBody()->write(json_encode($apps));
    return $response->withHeader('Content-Type', 'application/json');
});

$app->post('/api/apps', function (Request $request, Response $response) use ($appManager) {
    $data = $request->getParsedBody();
    
    $result = $appManager->createApp([
        'name' => $data['name'],
        'repository' => $data['repository'],
        'hostname' => $data['hostname'],
        'database_id' => $data['database_id'] ?? null,
        'env_content' => $data['env_content'] ?? ''
    ]);
    
    if ($result['success']) {
        $response->getBody()->write(json_encode($result));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(201);
    } else {
        $response->getBody()->write(json_encode($result));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
    }
});

$app->get('/api/apps/{id}', function (Request $request, Response $response, $args) use ($appManager) {
    $app = $appManager->getApp($args['id']);
    if ($app) {
        $response->getBody()->write(json_encode($app));
        return $response->withHeader('Content-Type', 'application/json');
    } else {
        return $response->withStatus(404);
    }
});

$app->put('/api/apps/{id}', function (Request $request, Response $response, $args) use ($appManager) {
    $data = $request->getParsedBody();
    $result = $appManager->updateApp($args['id'], $data);
    
    $response->getBody()->write(json_encode($result));
    return $response->withHeader('Content-Type', 'application/json');
});

$app->delete('/api/apps/{id}', function (Request $request, Response $response, $args) use ($appManager) {
    $result = $appManager->deleteApp($args['id']);
    $response->getBody()->write(json_encode($result));
    return $response->withHeader('Content-Type', 'application/json');
});

$app->post('/api/apps/{id}/deploy', function (Request $request, Response $response, $args) use ($appManager) {
    $result = $appManager->deployApp($args['id']);
    $response->getBody()->write(json_encode($result));
    return $response->withHeader('Content-Type', 'application/json');
});

$app->get('/api/apps/{id}/logs', function (Request $request, Response $response, $args) use ($appManager) {
    $logs = $appManager->getDeploymentLogs($args['id']);
    $response->getBody()->write(json_encode(['logs' => $logs]));
    return $response->withHeader('Content-Type', 'application/json');
});

// API Routes for Databases
$app->get('/api/databases', function (Request $request, Response $response) use ($databaseManager) {
    $databases = $databaseManager->getAllDatabases();
    $response->getBody()->write(json_encode($databases));
    return $response->withHeader('Content-Type', 'application/json');
});

$app->post('/api/databases', function (Request $request, Response $response) use ($databaseManager) {
    $data = $request->getParsedBody();
    $result = $databaseManager->createDatabase($data);
    
    $status = $result['success'] ? 201 : 400;
    $response->getBody()->write(json_encode($result));
    return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
});

$app->get('/api/databases/{id}', function (Request $request, Response $response, $args) use ($databaseManager) {
    $database = $databaseManager->getDatabase($args['id']);
    if ($database) {
        // No devolver la contraseña por seguridad
        unset($database['db_password']);
        $response->getBody()->write(json_encode($database));
        return $response->withHeader('Content-Type', 'application/json');
    } else {
        return $response->withStatus(404);
    }
});

$app->put('/api/databases/{id}', function (Request $request, Response $response, $args) use ($databaseManager) {
    $data = $request->getParsedBody();
    $result = $databaseManager->updateDatabase($args['id'], $data);
    
    $response->getBody()->write(json_encode($result));
    return $response->withHeader('Content-Type', 'application/json');
});

$app->delete('/api/databases/{id}', function (Request $request, Response $response, $args) use ($databaseManager) {
    $result = $databaseManager->deleteDatabase($args['id']);
    $response->getBody()->write(json_encode($result));
    return $response->withHeader('Content-Type', 'application/json');
});

$app->post('/api/databases/{id}/test', function (Request $request, Response $response, $args) use ($databaseManager) {
    $result = $databaseManager->testConnection($args['id']);
    $response->getBody()->write(json_encode($result));
    return $response->withHeader('Content-Type', 'application/json');
});

$app->post('/api/databases/{id}/setup', function (Request $request, Response $response, $args) use ($databaseManager) {
    $result = $databaseManager->createDatabaseAndUser($args['id']);
    $response->getBody()->write(json_encode($result));
    return $response->withHeader('Content-Type', 'application/json');
});

// Incluir GitCredentialManager
require_once '../src/GitCredentialManager.php';
$gitCredentialManager = new GitCredentialManager($database);

// Rutas para credenciales Git
$app->get('/api/git-credentials', function (Request $request, Response $response) use ($gitCredentialManager) {
    $credentials = $gitCredentialManager->getAllCredentials();
    $response->getBody()->write(json_encode($credentials));
    return $response->withHeader('Content-Type', 'application/json');
});

$app->post('/api/git-credentials', function (Request $request, Response $response) use ($gitCredentialManager) {
    $data = $request->getParsedBody();
    $result = $gitCredentialManager->createCredential($data);
    
    $status = $result['success'] ? 201 : 400;
    $response->getBody()->write(json_encode($result));
    return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
});

$app->get('/api/git-credentials/{id}', function (Request $request, Response $response, $args) use ($gitCredentialManager) {
    $credential = $gitCredentialManager->getCredential($args['id']);
    if ($credential) {
        // No devolver el token por seguridad
        unset($credential['token']);
        $response->getBody()->write(json_encode($credential));
        return $response->withHeader('Content-Type', 'application/json');
    } else {
        return $response->withStatus(404);
    }
});

$app->put('/api/git-credentials/{id}', function (Request $request, Response $response, $args) use ($gitCredentialManager) {
    $data = $request->getParsedBody();
    $result = $gitCredentialManager->updateCredential($args['id'], $data);
    
    $response->getBody()->write(json_encode($result));
    return $response->withHeader('Content-Type', 'application/json');
});

$app->delete('/api/git-credentials/{id}', function (Request $request, Response $response, $args) use ($gitCredentialManager) {
    $result = $gitCredentialManager->deleteCredential($args['id']);
    $response->getBody()->write(json_encode($result));
    return $response->withHeader('Content-Type', 'application/json');
});

$app->run();
?>
