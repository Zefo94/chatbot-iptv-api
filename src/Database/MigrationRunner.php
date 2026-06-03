<?php

namespace App\Database;

use PDO;

/**
 * Runs pending SQL migrations automatically on each boot.
 *
 * How it works:
 *  1. Creates a `db_migrations` tracking table if it doesn't exist.
 *  2. Scans database/migrations/*.sql sorted by filename.
 *  3. Runs any file not yet recorded in db_migrations.
 *  4. Records each successful migration so it never runs twice.
 *
 * Add a new migration: create database/migrations/003_description.sql
 * It will run automatically on the next request after git pull.
 */
class MigrationRunner
{
    private PDO $db;
    private string $migrationsPath;

    public function __construct()
    {
        $this->db             = Connection::getInstance();
        $this->migrationsPath = dirname(__DIR__, 2) . '/database/migrations';
    }

    public function run(): void
    {
        $this->ensureTrackingTable();

        $applied = $this->appliedMigrations();
        $files   = $this->pendingFiles($applied);

        foreach ($files as $file) {
            $this->applyMigration($file);
        }
    }

    private function ensureTrackingTable(): void
    {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS `db_migrations` (
                `id`         INT AUTO_INCREMENT PRIMARY KEY,
                `filename`   VARCHAR(255) NOT NULL UNIQUE,
                `applied_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    private function appliedMigrations(): array
    {
        return $this->db
            ->query("SELECT filename FROM db_migrations")
            ->fetchAll(PDO::FETCH_COLUMN);
    }

    private function pendingFiles(array $applied): array
    {
        if (!is_dir($this->migrationsPath)) {
            return [];
        }

        $files = glob($this->migrationsPath . '/*.sql');
        sort($files);

        return array_filter($files, function (string $path) use ($applied): bool {
            return !in_array(basename($path), $applied, true);
        });
    }

    private function applyMigration(string $filePath): void
    {
        $sql      = file_get_contents($filePath);
        $filename = basename($filePath);

        // Split on semicolons to run each statement separately
        $statements = array_filter(
            array_map('trim', explode(';', $sql)),
            fn(string $s): bool => $s !== ''
        );

        $this->db->beginTransaction();
        try {
            foreach ($statements as $statement) {
                try {
                    $this->db->exec($statement);
                } catch (\PDOException $e) {
                    // Non-fatal: column/key already exists, or doesn't exist to drop
                    // MySQL codes: 1060 duplicate column, 1061 duplicate key, 1091 can't drop
                    $code = (int)$e->getCode();
                    $msg  = $e->getMessage();
                    $isNonFatal = in_array($code, [1060, 1061, 1091], true)
                        || str_contains($msg, 'Duplicate column')
                        || str_contains($msg, 'Duplicate key')
                        || str_contains($msg, "Can't DROP");
                    if (!$isNonFatal) {
                        throw $e;
                    }
                }
            }

            $this->db->prepare(
                "INSERT INTO db_migrations (filename) VALUES (?)"
            )->execute([$filename]);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw new \RuntimeException(
                "Migration '{$filename}' failed: " . $e->getMessage()
            );
        }
    }
}
