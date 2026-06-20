<?php

declare(strict_types=1);

namespace App\Core\Db;

use PDO;

/**
 * Runs .sql files from /database/migrations in filename order, tracking
 * what's already applied in a `migrations` table so it's safe to re-run.
 */
final class Migrator
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $migrationsPath,
    ) {
    }

    public function run(): array
    {
        $this->ensureMigrationsTable();
        $applied = $this->appliedMigrations();
        $ran = [];

        foreach ($this->migrationFiles() as $file) {
            $name = basename($file);
            if (in_array($name, $applied, true)) {
                continue;
            }

            $sql = file_get_contents($file);
            if ($sql === false) {
                throw new \RuntimeException("Unable to read migration file: {$file}");
            }

            try {
                // DDL statements (CREATE TABLE, etc.) trigger an implicit commit in
                // MySQL/MariaDB, so wrapping them in an explicit transaction here
                // would leave commit()/rollBack() with nothing active to act on.
                foreach ($this->splitStatements($sql) as $statement) {
                    $this->pdo->exec($statement);
                }
                $stmt = $this->pdo->prepare('INSERT INTO migrations (migration) VALUES (:name)');
                $stmt->execute(['name' => $name]);
                $ran[] = $name;
            } catch (\Throwable $e) {
                throw new \RuntimeException("Migration failed: {$name} - {$e->getMessage()}", previous: $e);
            }
        }

        return $ran;
    }

    private function ensureMigrationsTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS migrations (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                migration VARCHAR(255) NOT NULL UNIQUE,
                applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    private function appliedMigrations(): array
    {
        $stmt = $this->pdo->query('SELECT migration FROM migrations');
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    private function migrationFiles(): array
    {
        $files = glob(rtrim($this->migrationsPath, '/') . '/*.sql') ?: [];
        sort($files);
        return $files;
    }

    private function splitStatements(string $sql): array
    {
        $statements = array_filter(
            array_map('trim', explode(";\n", str_replace("\r\n", "\n", $sql))),
            static fn (string $s) => $s !== ''
        );

        return array_values($statements);
    }
}
