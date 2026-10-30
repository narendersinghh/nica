<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Migrations are PHP files in database/migrations named
 * YYYY_MM_DD_HHMMSS_description.php returning ['up' => fn(Database), 'down' => fn(Database)].
 */
final class Migrator
{
    public function __construct(private Database $db, private string $dir)
    {
    }

    /** @return string[] names of migrations run */
    public function migrate(): array
    {
        $this->ensureTable();
        $done = $this->ran();
        $batch = (int) $this->db->scalar('SELECT COALESCE(MAX(batch), 0) FROM migrations') + 1;
        $run = [];
        foreach ($this->files() as $name => $file) {
            if (isset($done[$name])) {
                continue;
            }
            $migration = require $file;
            // MySQL DDL auto-commits, so each migration is applied and recorded one at a time.
            ($migration['up'])($this->db);
            $this->db->insert('migrations', ['migration' => $name, 'batch' => $batch]);
            $run[] = $name;
        }
        return $run;
    }

    /** @return string[] names rolled back */
    public function rollback(): array
    {
        $this->ensureTable();
        $batch = (int) $this->db->scalar('SELECT COALESCE(MAX(batch), 0) FROM migrations');
        if ($batch === 0) {
            return [];
        }
        $files = $this->files();
        $rolled = [];
        foreach ($this->db->select('SELECT migration FROM migrations WHERE batch = ? ORDER BY id DESC', [$batch]) as $row) {
            $name = $row['migration'];
            if (isset($files[$name])) {
                $migration = require $files[$name];
                if (isset($migration['down'])) {
                    ($migration['down'])($this->db);
                }
            }
            $this->db->delete('migrations', ['migration' => $name]);
            $rolled[] = $name;
        }
        return $rolled;
    }

    /** @return array<string,bool> name => ran */
    public function status(): array
    {
        $this->ensureTable();
        $done = $this->ran();
        $out = [];
        foreach (array_keys($this->files()) as $name) {
            $out[$name] = isset($done[$name]);
        }
        return $out;
    }

    private function ensureTable(): void
    {
        $this->db->statement('CREATE TABLE IF NOT EXISTS migrations (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            migration VARCHAR(191) NOT NULL UNIQUE,
            batch INT UNSIGNED NOT NULL,
            ran_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    private function ran(): array
    {
        return array_fill_keys(array_column($this->db->select('SELECT migration FROM migrations'), 'migration'), true);
    }

    /** @return array<string,string> name => path, in order */
    private function files(): array
    {
        $files = [];
        foreach (glob($this->dir . '/*.php') ?: [] as $path) {
            $files[basename($path, '.php')] = $path;
        }
        ksort($files);
        return $files;
    }
}
