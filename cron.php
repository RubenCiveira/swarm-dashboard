<?php
require_once './vendor/autoload.php';
require_once './src/Database.php';
require_once './src/AppManager.php';

use Cron\CronExpression;

function logCronExecution(PDO $db, $appName, $status, $responseTime = null, $response = null) {
    $query = "INSERT INTO cron_executions (app_name, status, response_time, response)
              VALUES (:app_name, :status, :response_time, :response)";

    $stmt = $db->prepare($query);
    $stmt->bindParam(':app_name', $appName);
    $stmt->bindParam(':status', $status);
    $stmt->bindParam(':response_time', $responseTime);
    $stmt->bindParam(':response', $response);

    $stmt->execute();
}

function getLastExecution(PDO $db,$appName) {
    $query = "SELECT * FROM cron_executions WHERE app_name = :app_name ORDER BY timestamp DESC LIMIT 1";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':app_name', $appName);
    $stmt->execute();
    
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// Función para realizar el ping y obtener la respuesta
function pingApp($url) {
    // Puedes usar cURL o file_get_contents para hacer el ping HTTP
    // Aquí usamos cURL como ejemplo
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 1);
    $response = curl_exec($ch);
    $httpStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'status' => $httpStatus === 200 ? 'success' : 'failure',
        'response_time' => curl_getinfo($ch, CURLINFO_TOTAL_TIME),
        'response' => $response,
    ];
}

$on = '';
$database = new Database();
$pdo = $database->getPdo();

$manager = new AppManager($database);
$applications = $manager->getAllApps();

foreach ($applications as $app) {
    $cronPath = $app['cron_path']; // La expresión cron
    $cronPeriod = $app['cron_period']; // La expresión cron
    $appName = $app['name'];
    if( $cronPath && $cronPeriod ) {
        // Obtener la última ejecución de esta aplicación
        $lastExecution = getLastExecution($pdo, $appName);

        // Si no hay registros anteriores, consideramos la primera ejecución ahora
        $lastExecutionTime = $lastExecution ? strtotime($lastExecution['timestamp']) : time();

        // Calcular la siguiente fecha de ejecución usando CronExpression
        $cron = new CronExpression($cronPeriod);
        $nextExecutionTime = $cron->getNextRunDate(date('Y-m-d H:i:s', $lastExecutionTime))->getTimestamp();

        echo " - Has it " . $cronPeriod . " and ".$lastExecution."\n";
        // Si la próxima ejecución es menor o igual a la hora actual, ejecutar el cron
        if (!$lastExecution || $nextExecutionTime <= time()) {
            echo " - Run it on ".'http://' . $app['hostname']. '.civeira.net/' . $cronPath."\n";
            // Realizar el ping HTTP
            $url = 'http://' . $app['hostname']. '.civeira.net/' . $cronPath; // La URL de la aplicación para hacer el ping
            $pingResult = pingApp($url);

            print_r( $pingResult );

            // Registrar la ejecución
            logCronExecution($pdo, $appName, $pingResult['status'], $pingResult['response_time'], $pingResult['response']);
            
            // Opcionalmente, puedes actualizar la última ejecución para esta aplicación
            // O simplemente dejar que la próxima ejecución la calcule automáticamente
        } else {
            echo " - Wait until " . date('Y-m-d H:m:s', $nextExecutionTime). " <= ".date('Y-m-d H:m:s', time()).".\n";
        }
    } else {
        echo " - Nothing to see here\n";
    }
}