<?php

class Database
{
    private \PDO $pdo;

    // Array de migraciones version => SQL
    private array $migrations = [];

    public function __construct(private string $dbPath = __DIR__ . '/../swarm.sqlite')
    {
        $this->pdo = new \PDO('sqlite:' . $this->dbPath);
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $this->createVersionTableIfNeeded();
        $this->setMigrations([
            '1.0.0' => $this->v1_0_0(),
            '1.1.0' => $this->v1_1_0(),
            '1.2.0' => $this->v1_2_0()
        ]);
        $this->applyMigrations();
    }

    public function getPdo()
    {
        return $this->pdo;
    }

    private function createVersionTableIfNeeded(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS versions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                version TEXT NOT NULL,
                applied_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
    }

    public function setMigrations(array $migrations): void
    {
        $this->migrations = $migrations;
    }

    public function applyMigrations(): void
    {
        $currentVersion = $this->getCurrentVersion();

        foreach ($this->migrations as $version => $sql) {
            if (version_compare($version, $currentVersion, '>')) {
                $this->pdo->exec($sql);
                $this->recordMigration($version);
            }
        }
    }

    private function getCurrentVersion(): string
    {
        $stmt = $this->pdo->query("SELECT version FROM versions ORDER BY id DESC LIMIT 1");
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $result['version'] ?? '0.0.0';
    }

    private function recordMigration(string $version): void
    {
        $stmt = $this->pdo->prepare("INSERT INTO versions (version) VALUES (:version)");
        $stmt->execute(['version' => $version]);
    }

    public function getConnection(): \PDO
    {
        return $this->pdo;
    }


    private function v1_0_0()
    {
        return "
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
    }
    private function v1_1_0(): string
    {
        return "ALTER TABLE databases ADD COLUMN quantity_backups INTEGER DEFAULT 0;";
    }

    private function v1_2_0(): string
    {
        return " ALTER TABLE apps ADD COLUMN log_type TEXT DEFAULT NULL;
        ALTER TABLE apps ADD COLUMN log_path TEXT DEFAULT NULL;
        ALTER TABLE apps ADD COLUMN trace_type TEXT DEFAULT NULL;
        ALTER TABLE apps ADD COLUMN trace_path TEXT DEFAULT NULL;";
    }
}
