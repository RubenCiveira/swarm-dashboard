<?php

class GitCredentialManager {
    private $db;
    
    public function __construct(Database $database) {
        $this->db = $database->getPdo();
    }
    
    public function getAllCredentials() {
        $stmt = $this->db->query("
            SELECT id, name, provider, username, description, created_at 
            FROM git_credentials 
            ORDER BY name ASC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function getCredential($id) {
        $stmt = $this->db->prepare("SELECT * FROM git_credentials WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    public function createCredential($data) {
        try {
            if (empty($data['name']) || empty($data['token']) || empty($data['provider'])) {
                return ['success' => false, 'message' => 'Faltan campos obligatorios'];
            }
            
            // Encriptar el token antes de guardarlo
            $encryptedToken = $this->encryptToken($data['token']);
            
            $stmt = $this->db->prepare("
                INSERT INTO git_credentials (name, provider, username, token, description) 
                VALUES (?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $data['name'],
                $data['provider'],
                $data['username'] ?? null,
                $encryptedToken,
                $data['description'] ?? ''
            ]);
            
            return [
                'success' => true, 
                'message' => 'Credencial creada exitosamente',
                'credential_id' => $this->db->lastInsertId()
            ];
            
        } catch (PDOException $e) {
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }
    
    public function updateCredential($id, $data) {
        try {
            $encryptedToken = !empty($data['token']) ? $this->encryptToken($data['token']) : null;
            
            if ($encryptedToken) {
                $stmt = $this->db->prepare("
                    UPDATE git_credentials 
                    SET name = ?, provider = ?, username = ?, token = ?, description = ?, updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ");
                $stmt->execute([
                    $data['name'],
                    $data['provider'],
                    $data['username'],
                    $encryptedToken,
                    $data['description'],
                    $id
                ]);
            } else {
                $stmt = $this->db->prepare("
                    UPDATE git_credentials 
                    SET name = ?, provider = ?, username = ?, description = ?, updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ");
                $stmt->execute([
                    $data['name'],
                    $data['provider'],
                    $data['username'],
                    $data['description'],
                    $id
                ]);
            }
            
            return ['success' => true, 'message' => 'Credencial actualizada exitosamente'];
            
        } catch (PDOException $e) {
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }
    
    public function deleteCredential($id) {
        try {
            // Verificar si hay aplicaciones usando esta credencial
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM apps WHERE git_credential_id = ?");
            $stmt->execute([$id]);
            $count = $stmt->fetchColumn();
            
            if ($count > 0) {
                return ['success' => false, 'message' => "No se puede eliminar: $count aplicación(es) están usando esta credencial"];
            }
            
            $stmt = $this->db->prepare("DELETE FROM git_credentials WHERE id = ?");
            $stmt->execute([$id]);
            
            return ['success' => true, 'message' => 'Credencial eliminada exitosamente'];
            
        } catch (PDOException $e) {
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }
    
    public function getCredentialForApp($appId) {
        $stmt = $this->db->prepare("
            SELECT a.custom_git_token, gc.* 
            FROM apps a 
            LEFT JOIN git_credentials gc ON a.git_credential_id = gc.id 
            WHERE a.id = ?
        ");
        $stmt->execute([$appId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$result) {
            return null;
        }
        
        // Si tiene token personalizado, usarlo
        if (!empty($result['custom_git_token'])) {
            return [
                'token' => $this->decryptToken($result['custom_git_token']),
                'username' => null,
                'provider' => 'custom'
            ];
        }
        
        // Si tiene credencial asociada, usarla
        if (!empty($result['token'])) {
            return [
                'token' => $this->decryptToken($result['token']),
                'username' => $result['username'],
                'provider' => $result['provider']
            ];
        }
        
        return null;
    }
    
    public function buildGitUrl($repository, $credential) {
        if (!$credential) {
            return $repository; // Repositorio público
        }

        $parsedUrl = parse_url($repository);
        $host = $parsedUrl['host'];
        $credentialUrl = "https://oauth2:{$credential['token']}@{$host}";
        // if ($credential['provider'] === 'github' || $credential['provider'] === 'custom') {
            //     // Para GitHub: https://token@github.com/user/repo.git
        //    $credentialUrl = "https://oauth2:{$credential['token']}@{$host}";
        //}
        // } elseif ($credential['provider'] === 'gitlab') {
        //     // Para GitLab: https://oauth2:token@gitlab.com/user/repo.git
        //     $credentialUrl = "https://oauth2:{$credential['token']}@{$host}{$path}";
        // }
        
        $tmpHome = sys_get_temp_dir() . '/git-home-' . uniqid();
        mkdir($tmpHome, 0700);
        
        $gitConfigDir = "{$tmpHome}/.git-credentials";
        $gitConfigFile = "{$tmpHome}/.gitconfig";
        
        // Guardar las credenciales en el formato de Git
        file_put_contents($gitConfigDir, $credentialUrl);
        
        // Crear la configuración para usar el helper `store` apuntando al archivo
        file_put_contents($gitConfigFile, <<<EOT
        [credential]
            helper = store
        EOT);
        // Limpieza
        register_shutdown_function(function () use ($gitConfigDir, $gitConfigFile, $tmpHome) {
            @unlink($gitConfigDir);
            @unlink($gitConfigFile);
            @rmdir($tmpHome);
        });

        $env = [
            'HOME' => $tmpHome,
        ];
        $envString = '';
        foreach ($env as $k => $v) {
            $envString .= "$k=" . escapeshellarg($v) . ' ';
        }
        return [$envString, $repository];
    }
    
    public function encryptToken($token) {
        // Usar una clave de encriptación simple (en producción usar algo más robusto)
        $key = hash('sha256', 'cicd-manager-secret-key', true);
        $iv = openssl_random_pseudo_bytes(16);
        $encrypted = openssl_encrypt($token, 'AES-256-CBC', $key, 0, $iv);
        return base64_encode($iv . $encrypted);
    }
    
    private function decryptToken($encryptedToken) {
        $key = hash('sha256', 'cicd-manager-secret-key', true);
        $data = base64_decode($encryptedToken);
        $iv = substr($data, 0, 16);
        $encrypted = substr($data, 16);
        return openssl_decrypt($encrypted, 'AES-256-CBC', $key, 0, $iv);
    }
}
?>
