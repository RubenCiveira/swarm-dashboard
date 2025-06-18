<?php

class Database {
    private $pdo;
    
    public function __construct() {
        $this->pdo = new PDO('sqlite:' . __DIR__ . '/../data/apps.db');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->initDatabase();
    }
    
    private function initDatabase() {
        // Verificar si necesitamos migrar una base de datos existente
        $needsMigration = $this->needsMigration();
        
        if ($needsMigration) {
            $this->migrateDatabase();
        } else {
            // Crear tablas desde cero con estructura completa
            $this->createFreshDatabase();
        }
    }

    private function needsMigration() {
        try {
            // Verificar si existe la tabla apps pero sin las nuevas columnas
            $result = $this->pdo->query("PRAGMA table_info(apps)");
            $columns = $result->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($columns)) {
                return false; // No existe la tabla, crear desde cero
            }
            
            // Verificar si faltan las columnas de bases de datos
            $columnNames = array_column($columns, 'name');
            return !in_array('database_id', $columnNames) || 
                   !$this->tableExists('databases');
            
        } catch (Exception $e) {
            return false; // Error, crear desde cero
        }
    }

    private function tableExists($tableName) {
        try {
            $result = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='{$tableName}'");
            return $result->fetch() !== false;
        } catch (Exception $e) {
            return false;
        }
    }

    private function migrateDatabase() {
        // Crear tabla de bases de datos
        $sql = "
        CREATE TABLE IF NOT EXISTS databases (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE,
            db_name TEXT NOT NULL,
            db_type TEXT NOT NULL,
            db_host TEXT DEFAULT 'localhost',
            db_port INTEGER DEFAULT NULL,
            db_username TEXT NOT NULL,
            db_password TEXT NOT NULL,
            description TEXT,
            status TEXT DEFAULT 'inactive',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        
        CREATE TABLE IF NOT EXISTS git_credentials (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE,
            provider TEXT NOT NULL,
            username TEXT,
            token TEXT NOT NULL,
            description TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        
        CREATE TABLE IF NOT EXISTS deployment_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            app_id INTEGER,
            log_content TEXT,
            deployment_date DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (app_id) REFERENCES apps (id)
        );
        ";
        
        $this->pdo->exec($sql);
        
        // Añadir columnas faltantes solo si no existen
        try {
            $this->pdo->exec("ALTER TABLE apps ADD COLUMN database_id INTEGER REFERENCES databases(id)");
        } catch (Exception $e) {
            // Columna ya existe, continuar
        }
        
        try {
            $this->pdo->exec("ALTER TABLE apps ADD COLUMN git_credential_id INTEGER REFERENCES git_credentials(id)");
        } catch (Exception $e) {
            // Columna ya existe, continuar
        }
        
        try {
            $this->pdo->exec("ALTER TABLE apps ADD COLUMN custom_git_token TEXT");
        } catch (Exception $e) {
            // Columna ya existe, continuar
        }

        // Eliminar columna database_name si existe (migrar a database_id)
        try {
            // SQLite no soporta DROP COLUMN directamente, pero podemos ignorar la columna antigua
        } catch (Exception $e) {
            // Continuar
        }
        
        // Crear índices
        $this->createIndexes();
    }

    private function createFreshDatabase() {
        $sql = "
        CREATE TABLE IF NOT EXISTS databases (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE,
            db_name TEXT NOT NULL,
            db_type TEXT NOT NULL,
            db_host TEXT DEFAULT 'localhost',
            db_port INTEGER DEFAULT NULL,
            db_username TEXT NOT NULL,
            db_password TEXT NOT NULL,
            description TEXT,
            status TEXT DEFAULT 'inactive',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        
        CREATE TABLE IF NOT EXISTS apps (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE,
            repository TEXT NOT NULL,
            hostname TEXT NOT NULL,
            directory TEXT NOT NULL,
            database_id INTEGER,
            env_content TEXT,
            git_credential_id INTEGER,
            custom_git_token TEXT,
            status TEXT DEFAULT 'inactive',
            last_deployment DATETIME,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (git_credential_id) REFERENCES git_credentials(id),
            FOREIGN KEY (database_id) REFERENCES databases(id)
        );
        
        CREATE TABLE IF NOT EXISTS git_credentials (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE,
            provider TEXT NOT NULL,
            username TEXT,
            token TEXT NOT NULL,
            description TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        
        CREATE TABLE IF NOT EXISTS deployment_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            app_id INTEGER,
            log_content TEXT,
            deployment_date DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (app_id) REFERENCES apps (id)
        );
        ";
        
        $this->pdo->exec($sql);
        
        // Crear índices
        $this->createIndexes();
    }

    private function createIndexes() {
        $indexes = "
        CREATE INDEX IF NOT EXISTS idx_apps_git_credential ON apps(git_credential_id);
        CREATE INDEX IF NOT EXISTS idx_apps_database ON apps(database_id);
        CREATE INDEX IF NOT EXISTS idx_apps_hostname ON apps(hostname);
        CREATE INDEX IF NOT EXISTS idx_apps_status ON apps(status);
        CREATE INDEX IF NOT EXISTS idx_git_credentials_provider ON git_credentials(provider);
        CREATE INDEX IF NOT EXISTS idx_databases_type ON databases(db_type);
        CREATE INDEX IF NOT EXISTS idx_databases_status ON databases(status);
        CREATE INDEX IF NOT EXISTS idx_deployment_logs_app ON deployment_logs(app_id);
        CREATE INDEX IF NOT EXISTS idx_deployment_logs_date ON deployment_logs(deployment_date);
        ";
        
        $this->pdo->exec($indexes);
    }
    
    public function getPdo() {
        return $this->pdo;
    }
}
?>
