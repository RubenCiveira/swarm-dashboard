<?php
require_once 'ConfigManager.php';
require_once 'GitCredentialManager.php';
require_once 'DatabaseManager.php';

class AppManager {
    private $db;
    private $gitCredentialManager;
    private $databaseManager;
    
    public function __construct(Database $database) {
        $this->db = $database->getPdo();
        $this->gitCredentialManager = new GitCredentialManager($database);
        $this->databaseManager = new DatabaseManager($database);
        
        // Crear directorio de datos si no existe
        if (!file_exists(__DIR__ . '/../data')) {
            mkdir(__DIR__ . '/../data', 0755, true);
        }
    }
    
    public function getAllApps() {
        $stmt = $this->db->query("
            SELECT a.*, d.name as database_name, d.db_type, d.status as db_status
            FROM apps a 
            LEFT JOIN databases d ON a.database_id = d.id 
            ORDER BY a.created_at DESC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function getApp($id) {
        $stmt = $this->db->prepare("
            SELECT a.*, d.name as database_name 
            FROM apps a 
            LEFT JOIN databases d ON a.database_id = d.id 
            WHERE a.id = ?
        ");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    public function createApp($data) {
        try {
            // Validar datos
            if (empty($data['name']) || empty($data['repository']) || empty($data['hostname'])) {
                return ['success' => false, 'message' => 'Faltan campos obligatorios'];
            }
            
            // Calcular directorio automáticamente basado en hostname
            $directory = ConfigManager::getDirectoryForHostname($data['hostname']);
        
            $stmt = $this->db->prepare("
                INSERT INTO apps (name, repository, hostname, directory, database_id, config_maps, git_credential_id, custom_git_token, log_type, log_path, trace_type, trace_path) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            // Encriptar token personalizado si existe
            $customToken = null;
            if (!empty($data['custom_git_token'])) {
                $customToken = $this->gitCredentialManager->encryptToken($data['custom_git_token']);
            }
            $stmt->execute([
                $data['name'],
                $data['repository'],
                $data['hostname'],
                $directory,
                $data['database_id'] ?? null,
                $data['config_maps'] ?? '',
                $data['git_credential_id'] ?? null,
                $customToken,
                $data['log_type'] ?? null,
                $data['log_path'] ?? null,
                $data['trace_type'] ?? null,
                $data['trace_path'] ?? null,
            ]);
            
            $appId = $this->db->lastInsertId();
            
            // Si se especificó una base de datos, verificar/configurar automáticamente
            if (!empty($data['database_id'])) {
                $dbResult = $this->databaseManager->testConnection($data['database_id']);
                if (!$dbResult['success']) {
                    // Intentar configurar la base de datos automáticamente
                    $setupResult = $this->databaseManager->createDatabaseAndUser($data['database_id']);
                    // No fallar la creación de la app si la BD falla, solo registrar
                }
            }
            
            return [
                'success' => true, 
                'message' => 'Aplicación creada exitosamente',
                'app_id' => $appId,
                'directory' => $directory
            ];
            
        } catch (PDOException $e) {
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }
    
    public function updateApp($id, $data) {
        try {
            // Calcular directorio automáticamente basado en hostname
            $directory = ConfigManager::getDirectoryForHostname($data['hostname']);
            
            $stmt = $this->db->prepare("
                UPDATE apps 
                SET name = ?, repository = ?, hostname = ?, directory = ?, 
                    database_id = ?, config_maps = ?, git_credential_id = ?, custom_git_token = ?, log_type = ?, log_path = ?, trace_type = ?, trace_path = ?, updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $customToken = null;
            if (!empty($data['custom_git_token'])) {
                $customToken = $this->gitCredentialManager->encryptToken($data['custom_git_token']);
            }
            $stmt->execute([
                $data['name'],
                $data['repository'],
                $data['hostname'],
                $directory,
                $data['database_id'] ?? null,
                $data['config_maps'],
                $data['git_credential_id'] ?? null,
                $customToken,
                $data['log_type'] ?? null,
                $data['log_path'] ?? null,
                $data['trace_type'] ?? null,
                $data['trace_path'] ?? null,
                $id
            ]);
            
            return ['success' => true, 'message' => 'Aplicación actualizada exitosamente'];
            
        } catch (PDOException $e) {
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }
    
    public function deleteApp($id) {
        try {
            // Eliminar logs primero
            $stmt = $this->db->prepare("DELETE FROM deployment_logs WHERE app_id = ?");
            $stmt->execute([$id]);
            
            // Eliminar aplicación
            $stmt = $this->db->prepare("DELETE FROM apps WHERE id = ?");
            $stmt->execute([$id]);
            
            return ['success' => true, 'message' => 'Aplicación eliminada exitosamente'];
            
        } catch (PDOException $e) {
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }
    
    /**
     * Procesa las variables de plantilla en el contenido del .env
     */
    private function processEnvTemplate($envContent, $database) {
        if (!$database) {
            return $envContent;
        }
        
        // Desencriptar contraseña
        $dbPassword = $this->databaseManager->decryptPassword($database['db_password']);
        
        // Definir las variables de reemplazo
        $replacements = [
            '%DB_HOST%' => $database['db_host'],
            '%DB_PORT%' => $database['db_port'],
            '%DB_NAME%' => $database['db_name'],
            '%DB_USER%' => $database['db_username'],
            '%DB_PASS%' => $dbPassword,
            '%DB_TYPE%' => $database['db_type'] === 'pgsql' ? 'pgsql' : 'mysql',
            '%APP_NAME%' => '', // Se llenará en deployApp
            '%APP_URL%' => '', // Se llenará en deployApp
            '%APP_ENV%' => 'production'
        ];
        
        // Reemplazar las variables
        foreach ($replacements as $placeholder => $value) {
            $envContent = str_replace($placeholder, $value, $envContent);
        }
        
        return $envContent;
    }
    
    public function cleanApp($id) {
        $app = $this->getApp($id);
        if (!$app) {
            return ['success' => false, 'message' => 'Aplicación no encontrada'];
        }
        $logContent = "Iniciando borrado para {$app['name']}\n";
        if (file_exists($app['directory'])) {
            if ($this->deleteDirectory($app['directory'])) {
                $logContent .= "Directorio borrado: {$app['directory']}\n";
            } else {
                $logContent .= "⚠️ Error borrando el directorio: {$app['directory']}\n";
            }
        }
        return [
            'success' => true, 
            'message' => 'Despliegue completado exitosamente',
            'logs' => $logContent
        ];
    }

    public function deployApp($id) {
        $app = $this->getApp($id);
        if (!$app) {
            return ['success' => false, 'message' => 'Aplicación no encontrada'];
        }
        
        $logContent = "Iniciando despliegue para {$app['name']}\n";
        
        try {
            // Obtener configuración de PHP
            $phpExecutable = ConfigManager::getPhpExecutable();
            $logContent .= "Usando PHP: {$phpExecutable}\n";
            
            // Verificar que PHP esté disponible
            $phpVersion = shell_exec("{$phpExecutable} -v 2>&1");
            if (strpos($phpVersion, 'PHP') === false) {
                throw new Exception("PHP no encontrado en: {$phpExecutable}");
            }
            $logContent .= "Versión de PHP: " . trim(explode("\n", $phpVersion)[0]) . "\n";
            
            // Obtener información de la base de datos si está configurada
            $database = null;
            if (!empty($app['database_id'])) {
                $database = $this->databaseManager->getDatabase($app['database_id']);
                
                if ($database) {
                    $logContent .= "\n=== CONFIGURACIÓN DE BASE DE DATOS ===\n";
                    $logContent .= "Base de datos asociada: {$database['name']} ({$database['db_type']})\n";
                    
                    // Verificar conexión primero
                    $testResult = $this->databaseManager->testConnection($app['database_id']);
                    if (!$testResult['success']) {
                        $logContent .= "⚠️  Conexión fallida, intentando configurar base de datos...\n";
                        
                        // Intentar configurar la base de datos
                        $setupResult = $this->databaseManager->createDatabaseAndUser($app['database_id']);
                        if ($setupResult['success']) {
                            $logContent .= "✅ Base de datos configurada exitosamente\n";
                            if (isset($setupResult['logs'])) {
                                $logContent .= $setupResult['logs'];
                            }
                        } else {
                            $logContent .= "❌ Error configurando base de datos: " . $setupResult['message'] . "\n";
                            $logContent .= "⚠️  Continuando con el despliegue sin base de datos...\n";
                        }
                    } else {
                        $logContent .= "✅ Conexión a base de datos verificada\n";
                    }
                    
                    $logContent .= "=== FIN CONFIGURACIÓN BASE DE DATOS ===\n\n";
                }
            }
            
            // Obtener credenciales para el repositorio
            $credential = $this->gitCredentialManager->getCredentialForApp($id);
            [$envGit, $gitUrl] = $this->gitCredentialManager->buildGitUrl($app['repository'], $credential);

            if ($credential) {
                $logContent .= "Usando credenciales con $envGit para repositorio privado $gitUrl\n";
            }
            
            // Crear directorio si no existe
            if (!file_exists($app['directory'])) {
                mkdir($app['directory'], 0755, true);
                $logContent .= "Directorio creado: {$app['directory']}\n";
            }
            
            // Clonar o actualizar repositorio
            if (file_exists($app['directory'] . '/.git')) {
                // Actualizar repositorio existente - resetear cambios locales primero
                $logContent .= "Reseteando cambios locales y actualizando repositorio...\n";

                // Primero obtener la información del remoto
                $output = shell_exec("cd {$app['directory']} && ".$envGit . " git fetch origin 2>&1");
                $logContent .= "Obteniendo información del remoto:\n$output\n";

                // Resetear cambios locales y actualizar
                $output = shell_exec("cd {$app['directory']} && ".$envGit . " git reset --hard origin/HEAD 2>&1 && ".$envGit . " git pull origin 2>&1");
                $logContent .= "Reseteando y actualizando repositorio:\n$output\n";

                // Verificar el estado final
                $status = shell_exec("cd {$app['directory']} && ".$envGit . " git status --porcelain 2>&1");
                if (!$status || empty(trim($status))) {
                    $logContent .= "✅ Repositorio limpio y actualizado\n";
                } else {
                    $logContent .= "⚠️  Estado del repositorio:\n$status\n";
                }
            } else {
                // Clonar repositorio
                $output = shell_exec($envGit . " git clone {$gitUrl} {$app['directory']} 2>&1");
                $logContent .= "Clonando repositorio:\n$output\n";
            }
            
            // Procesar archivo .env con variables de plantilla
            $this->deployConfigMaps($app, $database);
            if (file_exists($app['directory'] . '/composer.json')) {
                require_once '../src/deployer/DeployComposer.php';
                $logContent .= deployWithComposer($app);
            } else if( file_exists($app['directory'] . '/angular.json')) {
                require_once '../src/deployer/DeployAngular.php';
                $logContent .= deployWithAngular($app);
            }
            $this->deployConfigMaps($app, $database);
            
            // Establecer permisos
            
            // Actualizar estado de la aplicación
            $stmt = $this->db->prepare("
                UPDATE apps 
                SET status = 'active', last_deployment = CURRENT_TIMESTAMP 
                WHERE id = ?
            ");
            $stmt->execute([$id]);
            
            $logContent .= "Despliegue completado exitosamente\n";
            
            // Guardar log
            $this->saveDeploymentLog($id, $logContent);
            
            return [
                'success' => true, 
                'message' => 'Despliegue completado exitosamente',
                'logs' => $logContent
            ];
            
        } catch (Exception $e) {
            $logContent .= "Error durante el despliegue: " . $e->getMessage() . "\n";
            $this->saveDeploymentLog($id, $logContent);
            
            return [
                'success' => false, 
                'message' => 'Error durante el despliegue: ' . $e->getMessage(),
                'logs' => $logContent
            ];
        }
    }

    private function deployConfigMaps($app, $database): string {
        $logContent = "";
        if (!empty($app['config_maps'])) {
            $files = json_decode($app['config_maps'], true);
            foreach($files as $filename => $filecontent) {

                $processedEnvContent = $this->processEnvTemplate($filecontent, $database);
                
                // Añadir variables adicionales de la aplicación
                $appReplacements = [
                    '%APP_NAME%' => $app['name'],
                    '%APP_URL%' => "https://{$app['hostname']}"
                ];
                
                foreach ($appReplacements as $placeholder => $value) {
                    $processedEnvContent = str_replace($placeholder, $value, $processedEnvContent);
                }
                $on = dirname($app['directory'] . '/' . $filename );
                if( !is_dir($on) ) {
                    mkdir($on, 0777, true);
                }
                file_put_contents($app['directory'] . '/' . $filename, $processedEnvContent);
                $logContent .= "Archivo $filename creado con variables procesadas\n";
                
                // Mostrar las variables que fueron reemplazadas
                preg_match_all('/%[A-Z_]+%/', $filecontent, $matches);
                if (!empty($matches[0])) {
                    $logContent .= "Variables de plantilla {$filename} en procesadas: " . implode(', ', array_unique($matches[0])) . "\n";
                }
            }
        }
        shell_exec("chown -R www-data:www-data {$app['directory']} 2>/dev/null");
        shell_exec("chmod -R 755 {$app['directory']}");
        $logContent .= "Permisos establecidos\n";
        return $logContent;
    }
    
    private function saveDeploymentLog($appId, $logContent) {
        $stmt = $this->db->prepare("INSERT INTO deployment_logs (app_id, log_content) VALUES (?, ?)");
        $stmt->execute([$appId, $logContent]);
    }
    
    public function getDeploymentLogs($appId, $limit = 10) {
        $stmt = $this->db->prepare("
            SELECT log_content, deployment_date 
            FROM deployment_logs 
            WHERE app_id = ? 
            ORDER BY deployment_date DESC 
            LIMIT ?
        ");
        $stmt->execute([$appId, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function deleteDirectory(string $dir): bool {
        if (!is_dir($dir)) return false;
        $items = array_diff(scandir($dir), ['.', '..']);
        foreach ($items as $item) {
            $path = "$dir/$item";
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }
        return rmdir($dir);
    }
}
?>
