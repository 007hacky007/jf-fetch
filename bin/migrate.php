#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Infra\Config;
use App\Infra\Db;
use PDO;
use RuntimeException;

$root = dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
} else {
    spl_autoload_register(static function (string $class) use ($root): void {
        $prefix = 'App\\';
        if (str_starts_with($class, $prefix) === false) {
            return;
        }

        $relative = substr($class, strlen($prefix));
        $path = $root . '/app/' . str_replace('\\', '/', $relative) . '.php';
        if (file_exists($path)) {
            require_once $path;
        }
    });
}

Config::boot($root . '/config');

$pdo = Db::connection();
ensureSchemaTable($pdo);

$migrationsDir = $root . '/database/migrations';
if (!is_dir($migrationsDir)) {
    fwrite(STDERR, "Migrations directory not found: {$migrationsDir}\n");
    exit(1);
}

$files = glob($migrationsDir . '/*.sql');
sort($files);

if ($files === []) {
    fwrite(STDOUT, "No migrations to run.\n");
    exit(0);
}

foreach ($files as $file) {
    $name = basename($file);
    if (migrationApplied($pdo, $name)) {
        continue;
    }

    applyMigration($pdo, $file, $name);
    recordMigration($pdo, $name);
    fwrite(STDOUT, "Applied migration: {$name}\n");
}

fwrite(STDOUT, "Migrations complete.\n");

/**
 * Ensures the schema_migrations bookkeeping table exists.
 */
function ensureSchemaTable(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS schema_migrations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            migration VARCHAR(255) NOT NULL UNIQUE,
            applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )'
    );
}

/**
 * Checks whether the migration has already been applied.
 */
function migrationApplied(PDO $pdo, string $migration): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE migration = :migration LIMIT 1');
    $stmt->execute(['migration' => $migration]);

    return $stmt->fetchColumn() !== false;
}

/**
 * Executes the SQL contained in the migration file within a transaction.
 */
function applyMigration(PDO $pdo, string $path, string $name): void
{
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException('Unable to read migration file: ' . $name);
    }

    Db::transaction(static function () use ($pdo, $sql, $name): void {
        $statements = array_filter(array_map('trim', preg_split('/;\s*\n/', $sql)));
        if ($statements === []) {
            return;
        }

        foreach ($statements as $statement) {
            if ($statement === '') {
                continue;
            }

            $pdo->exec($statement);
        }
    });
}

/**
 * Records the applied migration in the schema_migrations table.
 */
function recordMigration(PDO $pdo, string $migration): void
{
    $stmt = $pdo->prepare('INSERT INTO schema_migrations (migration) VALUES (:migration)');
    $stmt->execute(['migration' => $migration]);
}
