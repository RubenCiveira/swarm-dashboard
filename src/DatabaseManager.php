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
