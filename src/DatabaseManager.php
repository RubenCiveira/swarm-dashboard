<?php

class DatabaseManager {
    private $db;
    
    public function __construct(Database $database) {
        $this->db = $database->getPdo();
    }
    
    public function getAllDatabases() {
        $stmt = $this->db->query("
            SELECT id, name, db_name, db_type, db_host, db_port, db_username, description, status, created_at 
            FROM databases 
            ORDER BY name ASC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function getDatabase($id) {
        $stmt = $this->db->prepare("SELECT * FROM databases WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    public function createDatabase($data) {
        try {
            if (empty($data['name']) || empty($data['db_name']) || empty($data['db_type']) || 
                empty($data['db_username']) || empty($data['db_password'])) {
                return ['success' => false, 'message' => 'Faltan campos obligatorios'];
            }
            
            // Establecer puerto por defecto según el tipo
            $defaultPort = $data['db_type'] === 'pgsql' ? 5432 : 3306;
            $port = !empty($data['db_port']) ? (int)$data['db_port'] : $defaultPort;
            
            // Encriptar la contraseña
            $encryptedPassword = $this->encryptPassword($data['db_password']);
            
            $stmt = $this->db->prepare("
                INSERT INTO databases (name, db_name, db_type, db_host, db_port, db_username, db_password, description) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $data['name'],
                $data['db_name'],
                $data['db_type'],
                $data['db_host'] ?? 'localhost',
                $port,
                $data['db_username'],
                $encryptedPassword,
                $data['description'] ?? ''
            ]);
            
            return [
                'success' => true, 
                'message' => 'Base de datos creada exitosamente',
                'database_id' => $this->db->lastInsertId()
            ];
            
        } catch (PDOException $e) {
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }
    
    public function updateDatabase($id, $data) {
        try {
            $encryptedPassword = !empty($data['db_password']) ? $this->encryptPassword($data['db_password']) : null;
            
            $defaultPort = $data['db_type'] === 'pgsql' ? 5432 : 3306;
            $port = !empty($data['db_port']) ? (int)$data['db_port'] : $defaultPort;
            
            if ($encryptedPassword) {
                $stmt = $this->db->prepare("
                    UPDATE databases 
                    SET name = ?, db_name = ?, db_type = ?, db_host = ?, db_port = ?, 
                        db_username = ?, db_password = ?, description = ?, updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ");
                $stmt->execute([
                    $data['name'],
                    $data['db_name'],
                    $data['db_type'],
                    $data['db_host'],
                    $port,
                    $data['db_username'],
                    $encryptedPassword,
                    $data['description'],
                    $id
                ]);
            } else {
                $stmt = $this->db->prepare("
                    UPDATE databases 
                    SET name = ?, db_name = ?, db_type = ?, db_host = ?, db_port = ?, 
                        db_username = ?, description = ?, updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ");
                $stmt->execute([
                    $data['name'],
                    $data['db_name'],
                    $data['db_type'],
                    $data['db_host'],
                    $port,
                    $data['db_username'],
                    $data['description'],
                    $id
                ]);
            }
            
            return ['success' => true, 'message' => 'Base de datos actualizada exitosamente'];
            
        } catch (PDOException $e) {
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }
    
    public function deleteDatabase($id) {
        try {
            // Verificar si hay aplicaciones usando esta base de datos
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM apps WHERE database_id = ?");
            $stmt->execute([$id]);
            $count = $stmt->fetchColumn();
            
            if ($count > 0) {
                return ['success' => false, 'message' => "No se puede eliminar: $count aplicación(es) están usando esta base de datos"];
            }
            
            $stmt = $this->db->prepare("DELETE FROM databases WHERE id = ?");
            $stmt->execute([$id]);
            
            return ['success' => true, 'message' => 'Base de datos eliminada exitosamente'];
            
        } catch (PDOException $e) {
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }
    
    public function testConnection($id) {
        $database = $this->getDatabase($id);
        if (!$database) {
            return ['success' => false, 'message' => 'Base de datos no encontrada'];
        }
        
        try {
            $password = $this->decryptPassword($database['db_password']);
            
            if ($database['db_type'] === 'pgsql') {
                $dsn = "pgsql:host={$database['db_host']};port={$database['db_port']};dbname=postgres";
                $pdo = new PDO($dsn, $database['db_username'], $password);
            } else {
                $dsn = "mysql:host={$database['db_host']};port={$database['db_port']}";
                $pdo = new PDO($dsn, $database['db_username'], $password);
            }
            
            // Actualizar estado a activo
            $stmt = $this->db->prepare("UPDATE databases SET status = 'active' WHERE id = ?");
            $stmt->execute([$id]);
            
            return ['success' => true, 'message' => 'Conexión exitosa'];
            
        } catch (PDOException $e) {
            // Actualizar estado a error
            $stmt = $this->db->prepare("UPDATE databases SET status = 'error' WHERE id = ?");
            $stmt->execute([$id]);
            
            return ['success' => false, 'message' => 'Error de conexión: ' . $e->getMessage()];
        }
    }
    
    public function createDatabaseAndUser($id) {
        $database = $this->getDatabase($id);
        if (!$database) {
            return ['success' => false, 'message' => 'Base de datos no encontrada'];
        }
        
        $password = $this->decryptPassword($database['db_password']);
        $logContent = "Configurando base de datos: {$database['name']}\n";
        
        try {
            if ($database['db_type'] === 'pgsql') {
                $result = $this->setupPostgreSQL($database, $password);
            } else {
                $result = $this->setupMariaDB($database, $password);
            }
            
            if ($result['success']) {
                $stmt = $this->db->prepare("UPDATE databases SET status = 'active' WHERE id = ?");
                $stmt->execute([$id]);
            }
            
            return $result;
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }
    
    private function setupPostgreSQL($database, $password) {
        $logContent = "Configurando PostgreSQL...\n";
        
        try {
            // Conectar como superusuario (postgres)
            $dsn = "pgsql:host={$database['db_host']};port={$database['db_port']};dbname=postgres";
            $pdo = new PDO($dsn, ConfigManager::get('PG_SQL_USER'), ConfigManager::get('PG_SQL_USER'));
            
            // Crear base de datos si no existe
            $stmt = $pdo->prepare("SELECT 1 FROM pg_database WHERE datname = ?");
            $stmt->execute([$database['db_name']]);
            if (!$stmt->fetch()) {
                $pdo->exec("CREATE DATABASE \"{$database['db_name']}\"");
                $logContent .= "Base de datos '{$database['db_name']}' creada\n";
            }
            
            // Crear usuario si no existe
            $stmt = $pdo->prepare("SELECT 1 FROM pg_roles WHERE rolname = ?");
            $stmt->execute([$database['db_username']]);
            if (!$stmt->fetch()) {
                $pdo->exec("CREATE USER \"{$database['db_username']}\" WITH PASSWORD '{$password}'");
                $logContent .= "Usuario '{$database['db_username']}' creado\n";
            }
            
            // Otorgar permisos
            $pdo->exec("GRANT ALL PRIVILEGES ON DATABASE \"{$database['db_name']}\" TO \"{$database['db_username']}\"");
            $logContent .= "Permisos otorgados\n";
            
            return ['success' => true, 'message' => 'PostgreSQL configurado exitosamente', 'logs' => $logContent];
            
        } catch (PDOException $e) {
            return ['success' => false, 'message' => 'Error PostgreSQL: ' . $e->getMessage()];
        }
    }
    
    private function setupMariaDB($database, $password) {
        $logContent = "Configurando MariaDB/MySQL...\n";
        
        try {
            // Conectar como root
            $dsn = "mysql:host={$database['db_host']};port={$database['db_port']}";
            $pdo = new PDO($dsn, ConfigManager::get('MARIA_DB_USER'), ConfigManager::get('MARIA_DB_PASS'));
            
            // Crear base de datos si no existe
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$database['db_name']}`");
            $logContent .= "Base de datos '{$database['db_name']}' verificada/creada\n";
            
            // Crear usuario si no existe
            $pdo->exec("CREATE USER IF NOT EXISTS '{$database['db_username']}'@'localhost' IDENTIFIED BY '{$password}'");
            $logContent .= "Usuario '{$database['db_username']}' verificado/creado\n";
            
            // Otorgar permisos
            $pdo->exec("GRANT ALL PRIVILEGES ON `{$database['db_name']}`.* TO '{$database['db_username']}'@'localhost'");
            $pdo->exec("FLUSH PRIVILEGES");
            $logContent .= "Permisos otorgados\n";
            
            return ['success' => true, 'message' => 'MariaDB/MySQL configurado exitosamente', 'logs' => $logContent];
            
        } catch (PDOException $e) {
            return ['success' => false, 'message' => 'Error MariaDB/MySQL: ' . $e->getMessage()];
        }
    }
    
    public function dropDatabaseAndUser($id) {
        $database = $this->getDatabase($id);
        if (!$database) {
            return ['success' => false, 'message' => 'Base de datos no encontrada'];
        }
        
        $password = $this->decryptPassword($database['db_password']);
        $logContent = "Eliminando base de datos y usuario: {$database['name']}\n";
        
        try {
            if ($database['db_type'] === 'pgsql') {
                $result = $this->dropPostgreSQL($database, $password);
            } else {
                $result = $this->dropMariaDB($database, $password);
            }
            
            if ($result['success']) {
                $stmt = $this->db->prepare("UPDATE databases SET status = 'inactive' WHERE id = ?");
                $stmt->execute([$id]);
            }
            
            return $result;
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    private function dropPostgreSQL($database, $password) {
        $logContent = "Eliminando PostgreSQL...\n";
        
        try {
            // Conectar como superusuario (postgres)
            $dsn = "pgsql:host={$database['db_host']};port={$database['db_port']};dbname=postgres";
            $pdo = new PDO($dsn, ConfigManager::get('PG_SQL_USER'), ConfigManager::get('PG_SQL_PASS'));
            
            // Terminar conexiones activas a la base de datos
            $pdo->exec("SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '{$database['db_name']}' AND pid <> pg_backend_pid()");
            $logContent .= "Conexiones activas terminadas\n";
            
            // Eliminar base de datos
            $pdo->exec("DROP DATABASE IF EXISTS \"{$database['db_name']}\"");
            $logContent .= "Base de datos '{$database['db_name']}' eliminada\n";
            
            // Eliminar usuario
            $pdo->exec("DROP USER IF EXISTS \"{$database['db_username']}\"");
            $logContent .= "Usuario '{$database['db_username']}' eliminado\n";
            
            return ['success' => true, 'message' => 'PostgreSQL eliminado exitosamente', 'logs' => $logContent];
            
        } catch (PDOException $e) {
            return ['success' => false, 'message' => 'Error PostgreSQL: ' . $e->getMessage()];
        }
    }

    private function dropMariaDB($database, $password) {
        $logContent = "Eliminando MariaDB/MySQL...\n";
        
        try {
            // Conectar como root
            $dsn = "mysql:host={$database['db_host']};port={$database['db_port']}";
            $pdo = new PDO($dsn, ConfigManager::get('MARIA_DB_USER'), ConfigManager::get('MARIA_DB_PASS'));
            
            // Eliminar base de datos
            $pdo->exec("DROP DATABASE IF EXISTS `{$database['db_name']}`");
            $logContent .= "Base de datos '{$database['db_name']}' eliminada\n";
            
            // Eliminar usuario
            $pdo->exec("DROP USER IF EXISTS '{$database['db_username']}'@'localhost'");
            $pdo->exec("FLUSH PRIVILEGES");
            $logContent .= "Usuario '{$database['db_username']}' eliminado\n";
            
            return ['success' => true, 'message' => 'MariaDB/MySQL eliminado exitosamente', 'logs' => $logContent];
            
        } catch (PDOException $e) {
            return ['success' => false, 'message' => 'Error MariaDB/MySQL: ' . $e->getMessage()];
        }
    }

    public function createBackup($id) {
        $database = $this->getDatabase($id);
        if (!$database) {
            return ['success' => false, 'message' => 'Base de datos no encontrada'];
        }
        
        $password = $this->decryptPassword($database['db_password']);
        $timestamp = date('Y-m-d_H-i-s');
        $backupDir = __DIR__ . '/../data/backups';
        
        // Crear directorio de backups si no existe
        if (!file_exists($backupDir)) {
            mkdir($backupDir, 0755, true);
        }
        
        $backupFile = "{$backupDir}/{$database['db_name']}_{$timestamp}.sql";
        $logContent = "Creando backup de: {$database['name']}\n";
        
        try {
            if ($database['db_type'] === 'pgsql') {
                $result = $this->backupPostgreSQL($database, $password, $backupFile);
            } else {
                $result = $this->backupMariaDB($database, $password, $backupFile);
            }
            
            if ($result['success']) {
                // Comprimir el backup
                $compressedFile = $backupFile . '.gz';
                if (function_exists('gzencode')) {
                    $data = file_get_contents($backupFile);
                    file_put_contents($compressedFile, gzencode($data, 9));
                    unlink($backupFile); // Eliminar archivo sin comprimir
                    $result['backup_file'] = basename($compressedFile);
                    $result['backup_size'] = $this->formatBytes(filesize($compressedFile));
                } else {
                    $result['backup_file'] = basename($backupFile);
                    $result['backup_size'] = $this->formatBytes(filesize($backupFile));
                }
            }
            
            return $result;
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    private function backupPostgreSQL($database, $password, $backupFile) {
        $logContent = "Creando backup PostgreSQL...\n";
        
        try {
            // Usar pg_dump para crear el backup
            $pgDumpCmd = "PGPASSWORD='{$password}' pg_dump -h {$database['db_host']} -p {$database['db_port']} -U {$database['db_username']} -d {$database['db_name']} --no-password --clean --if-exists > {$backupFile} 2>&1";
            
            $output = shell_exec($pgDumpCmd);
            $logContent .= "Comando ejecutado: pg_dump\n";
            
            if (file_exists($backupFile) && filesize($backupFile) > 0) {
                $logContent .= "Backup creado exitosamente\n";
                return ['success' => true, 'message' => 'Backup PostgreSQL creado exitosamente', 'logs' => $logContent];
            } else {
                return ['success' => false, 'message' => 'Error creando backup PostgreSQL: ' . $output];
            }
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Error PostgreSQL backup: ' . $e->getMessage()];
        }
    }

    private function backupMariaDB($database, $password, $backupFile) {
        $logContent = "Creando backup MariaDB/MySQL...\n";
        
        try {
            // Usar mysqldump para crear el backup
            $mysqldumpCmd = "mysqldump -h {$database['db_host']} -P {$database['db_port']} -u {$database['db_username']} -p'{$password}' --single-transaction --routines --triggers {$database['db_name']} > {$backupFile} 2>&1";
            
            $output = shell_exec($mysqldumpCmd);
            $logContent .= "Comando ejecutado: mysqldump\n";
            
            if (file_exists($backupFile) && filesize($backupFile) > 0) {
                $logContent .= "Backup creado exitosamente\n";
                return ['success' => true, 'message' => 'Backup MariaDB/MySQL creado exitosamente', 'logs' => $logContent];
            } else {
                return ['success' => false, 'message' => 'Error creando backup MariaDB/MySQL: ' . $output];
            }
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Error MariaDB/MySQL backup: ' . $e->getMessage()];
        }
    }

    public function restoreBackup($id, $backupFileName) {
        $database = $this->getDatabase($id);
        if (!$database) {
            return ['success' => false, 'message' => 'Base de datos no encontrada'];
        }
        
        $backupDir = __DIR__ . '/../data/backups';
        $backupFile = $backupDir . '/' . $backupFileName;
        
        if (!file_exists($backupFile)) {
            return ['success' => false, 'message' => 'Archivo de backup no encontrado'];
        }
        
        $password = $this->decryptPassword($database['db_password']);
        $logContent = "Restaurando backup: {$backupFileName}\n";
        
        try {
            // Descomprimir si es necesario
            $sqlFile = $backupFile;
            if (pathinfo($backupFile, PATHINFO_EXTENSION) === 'gz') {
                $sqlFile = str_replace('.gz', '', $backupFile);
                $compressedData = file_get_contents($backupFile);
                $decompressedData = gzdecode($compressedData);
                file_put_contents($sqlFile, $decompressedData);
                $logContent .= "Archivo descomprimido\n";
            }
            
            if ($database['db_type'] === 'pgsql') {
                $result = $this->restorePostgreSQL($database, $password, $sqlFile);
            } else {
                $result = $this->restoreMariaDB($database, $password, $sqlFile);
            }
            
            // Limpiar archivo temporal si se descomprimió
            if ($sqlFile !== $backupFile && file_exists($sqlFile)) {
                unlink($sqlFile);
            }
            
            return $result;
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    private function restorePostgreSQL($database, $password, $sqlFile) {
        $logContent = "Restaurando PostgreSQL...\n";
        
        try {
            // Usar psql para restaurar el backup
            $psqlCmd = "PGPASSWORD='{$password}' psql -h {$database['db_host']} -p {$database['db_port']} -U {$database['db_username']} -d {$database['db_name']} -f {$sqlFile} 2>&1";
            
            $output = shell_exec($psqlCmd);
            $logContent .= "Comando ejecutado: psql\n";
            $logContent .= "Salida: " . substr($output, 0, 500) . "\n";
            
            return ['success' => true, 'message' => 'Backup PostgreSQL restaurado exitosamente', 'logs' => $logContent];
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Error PostgreSQL restore: ' . $e->getMessage()];
        }
    }

    private function restoreMariaDB($database, $password, $sqlFile) {
        $logContent = "Restaurando MariaDB/MySQL...\n";
        
        try {
            // Usar mysql para restaurar el backup
            $mysqlCmd = "mysql -h {$database['db_host']} -P {$database['db_port']} -u {$database['db_username']} -p'{$password}' {$database['db_name']} < {$sqlFile} 2>&1";
            
            $output = shell_exec($mysqlCmd);
            $logContent .= "Comando ejecutado: mysql\n";
            $logContent .= "Salida: " . substr($output, 0, 500) . "\n";
            
            return ['success' => true, 'message' => 'Backup MariaDB/MySQL restaurado exitosamente', 'logs' => $logContent];
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Error MariaDB/MySQL restore: ' . $e->getMessage()];
        }
    }

    public function listBackups($id = null) {
        $backupDir = __DIR__ . '/../data/backups';
        
        if (!file_exists($backupDir)) {
            return [];
        }
        
        $backups = [];
        $files = scandir($backupDir);
        
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            
            $filePath = $backupDir . '/' . $file;
            if (!is_file($filePath)) continue;
            
            // Extraer información del nombre del archivo
            $parts = explode('_', $file);
            if (count($parts) < 2) continue;
            
            $dbName = $parts[0];
            $timestamp = str_replace(['.sql', '.gz'], '', implode('_', array_slice($parts, 1)));
            
            // Si se especifica un ID, filtrar por nombre de BD
            if ($id !== null) {
                $database = $this->getDatabase($id);
                if (!$database || $database['db_name'] !== $dbName) {
                    continue;
                }
            }
            
            $backups[] = [
                'filename' => $file,
                'database_name' => $dbName,
                'timestamp' => $timestamp,
                'date' => $this->parseBackupDate($timestamp),
                'size' => $this->formatBytes(filesize($filePath)),
                'compressed' => pathinfo($file, PATHINFO_EXTENSION) === 'gz'
            ];
        }
        
        // Ordenar por fecha descendente
        usort($backups, function($a, $b) {
            return strtotime($b['date']) - strtotime($a['date']);
        });
        
        return $backups;
    }

    public function deleteBackup($backupFileName) {
        $backupDir = __DIR__ . '/../data/backups';
        $backupFile = $backupDir . '/' . $backupFileName;
        
        if (!file_exists($backupFile)) {
            return ['success' => false, 'message' => 'Archivo de backup no encontrado'];
        }
        
        try {
            unlink($backupFile);
            return ['success' => true, 'message' => 'Backup eliminado exitosamente'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Error eliminando backup: ' . $e->getMessage()];
        }
    }

    private function formatBytes($size, $precision = 2) {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        for ($i = 0; $size > 1024 && $i < count($units) - 1; $i++) {
            $size /= 1024;
        }
        
        return round($size, $precision) . ' ' . $units[$i];
    }

    private function parseBackupDate($timestamp) {
        // Convertir formato Y-m-d_H-i-s a fecha legible
        $dateTime = DateTime::createFromFormat('Y-m-d_H-i-s', $timestamp);
        return $dateTime ? $dateTime->format('Y-m-d H:i:s') : $timestamp;
    }
    
    private function encryptPassword($password) {
        $key = hash('sha256', 'cicd-db-secret-key', true);
        $iv = openssl_random_pseudo_bytes(16);
        $encrypted = openssl_encrypt($password, 'AES-256-CBC', $key, 0, $iv);
        return base64_encode($iv . $encrypted);
    }
    
    public function decryptPassword($encryptedPassword) {
        $key = hash('sha256', 'cicd-db-secret-key', true);
        $data = base64_decode($encryptedPassword);
        $iv = substr($data, 0, 16);
        $encrypted = substr($data, 16);
        return openssl_decrypt($encrypted, 'AES-256-CBC', $key, 0, $iv);
    }
}
?>
