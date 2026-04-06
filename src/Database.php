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
            '1.2.0' => $this->v1_2_0(),
            '2.0.0' => $this->v2_0_0(),
            '2.1.0' => $this->v2_1_0(),
            '2.1.1' => $this->v2_1_1(),
            '2.1.2' => $this->v2_1_2(),
            '2.2.0' => $this->v2_2_0(),
            '2.3.0' => $this->v2_3_0(),
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
            database_id INTEGER,d
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

    private function v2_0_0(): string
    {
          return "
    PRAGMA foreign_keys=off;

    BEGIN TRANSACTION;

    CREATE TABLE apps_new (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL UNIQUE,
        repository TEXT NOT NULL,
        hostname TEXT NOT NULL,
        directory TEXT NOT NULL,
        database_id INTEGER,
        config_maps TEXT,
        git_credential_id INTEGER,
        custom_git_token TEXT,
        status TEXT DEFAULT 'inactive',
        last_deployment DATETIME,
        log_type TEXT DEFAULT NULL,
        log_path TEXT DEFAULT NULL,
        trace_type TEXT DEFAULT NULL,
        trace_path TEXT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (git_credential_id) REFERENCES git_credentials(id),
        FOREIGN KEY (database_id) REFERENCES databases(id)
    );

    INSERT INTO apps_new (
        id, name, repository, hostname, directory, database_id,
        git_credential_id, custom_git_token, status, last_deployment,
        log_type, log_path, trace_type, trace_path, created_at, updated_at
    )
    SELECT 
        id, name, repository, hostname, directory, database_id,
        git_credential_id, custom_git_token, status, last_deployment,
        log_type, log_path, trace_type, trace_path, created_at, updated_at
    FROM apps;

    DROP TABLE apps;
    ALTER TABLE apps_new RENAME TO apps;

    COMMIT;

    PRAGMA foreign_keys=on;
    ";
    }

    private function v2_1_0(): string
    {
        return <<<SQL
            ALTER TABLE apps ADD COLUMN cron_path TEXT DEFAULT NULL;
            SQL;
    }
    private function v2_1_1(): string
    {
        return <<<SQL
            ALTER TABLE apps ADD COLUMN cron_period TEXT DEFAULT NULL;
            SQL;
    }
    private function v2_1_2(): string
    {
        return <<<SQL
            CREATE TABLE cron_executions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                app_name TEXT NOT NULL,
                timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
                status TEXT NOT NULL,  -- Puede ser "success", "failure", "error", etc.
                response_time INTEGER,  -- Tiempo de respuesta en milisegundos
                response TEXT           -- Cuerpo de la respuesta o código de estado (opcional)
            );
            SQL;
    }

    private function v2_2_0(): string
    {
        return <<<SQL
            CREATE TABLE IF NOT EXISTS api_tokens (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                token      TEXT NOT NULL UNIQUE,
                email      TEXT NOT NULL,
                name       TEXT NOT NULL,
                label      TEXT,
                expires_at TEXT,
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            );

            CREATE TABLE IF NOT EXISTS login_requests (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                code       TEXT NOT NULL UNIQUE,
                token_id   INTEGER,
                approved   INTEGER NOT NULL DEFAULT 0,
                expires_at TEXT NOT NULL,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                FOREIGN KEY (token_id) REFERENCES api_tokens(id)
            );

            CREATE INDEX IF NOT EXISTS idx_api_tokens_token ON api_tokens(token);
            CREATE INDEX IF NOT EXISTS idx_api_tokens_email ON api_tokens(email);
            CREATE INDEX IF NOT EXISTS idx_login_requests_code ON login_requests(code);
            SQL;
    }

    private function v2_3_0(): string
    {
        return <<<SQL
            CREATE TABLE IF NOT EXISTS workspaces (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                name        TEXT NOT NULL,
                description TEXT,
                parent_id   INTEGER DEFAULT NULL,
                icon        TEXT DEFAULT '📁',
                color       TEXT DEFAULT '#38bdf8',
                sort_order  INTEGER DEFAULT 0,
                created_at  TEXT NOT NULL DEFAULT (datetime('now')),
                updated_at  TEXT NOT NULL DEFAULT (datetime('now')),
                FOREIGN KEY (parent_id) REFERENCES workspaces(id)
            );

            ALTER TABLE apps ADD COLUMN workspace_id INTEGER DEFAULT NULL REFERENCES workspaces(id);

            CREATE INDEX IF NOT EXISTS idx_workspaces_parent ON workspaces(parent_id);
            CREATE INDEX IF NOT EXISTS idx_apps_workspace ON apps(workspace_id);
            SQL;
    }

}
