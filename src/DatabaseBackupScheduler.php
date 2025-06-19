<?php

class DatabaseBackupScheduler
{
    public function __construct(private Database $db, private string $backupDir = __DIR__ . '/../backups') {}

    public function run(): void
    {
        $pdo = $this->db->getConnection();
        $stmt = $pdo->query("SELECT * FROM databases WHERE status = 'active'");
        $databases = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($databases as $dbConfig) {
            $this->backupDatabase($dbConfig);
        }
    }

    private function backupDatabase(array $config): void
    {
        $dbName = $config['db_name'];
        $type = $config['db_type'];
        $timestamp = date('Ymd_His');
        $backupPath = "{$this->backupDir}/{$dbName}";
        $backupFile = "{$backupPath}/{$timestamp}.sql";

        if (!is_dir($backupPath)) {
            mkdir($backupPath, 0777, true);
        }

        $success = false;

        switch ($type) {
            case 'mysql':
                $cmd = sprintf(
                    'mysqldump -h %s -P %d -u%s -p%s %s > %s',
                    escapeshellarg($config['db_host']),
                    (int) $config['db_port'],
                    escapeshellarg($config['db_username']),
                    escapeshellarg($config['db_password']),
                    escapeshellarg($dbName),
                    escapeshellarg($backupFile)
                );
                $success = $this->runCommand($cmd);
                break;

            case 'pgsql':
                putenv("PGPASSWORD={$config['db_password']}");
                $cmd = sprintf(
                    'pg_dump -h %s -p %d -U %s -F p %s > %s',
                    escapeshellarg($config['db_host']),
                    (int) $config['db_port'],
                    escapeshellarg($config['db_username']),
                    escapeshellarg($dbName),
                    escapeshellarg($backupFile)
                );
                $success = $this->runCommand($cmd);
                break;

            case 'sqlite':
                $sourcePath = $config['db_host']; // suponemos que es la ruta al archivo .sqlite
                $success = copy($sourcePath, $backupFile);
                break;
        }

        if ($success) {
            $this->pruneBackups($backupPath, (int) $config['quantity_backups']);
        }
    }

    private function runCommand(string $cmd): bool
    {
        exec($cmd, $output, $resultCode);
        return $resultCode === 0;
    }

    private function pruneBackups(string $dir, int $max): void
    {
        $files = glob("{$dir}/*.sql");
        usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));

        foreach (array_slice($files, $max) as $oldFile) {
            unlink($oldFile);
        }
    }
}
