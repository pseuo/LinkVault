<?php

declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');

$root = dirname(__DIR__);
$config = require $root . '/config.php';
require $root . '/lib/database_schema.php';
$databasePath = (string)($config['database_path'] ?? '');

function migration_files(string $root): array
{
    $paths = glob($root . '/migrations/[0-9][0-9][0-9]_*.sql') ?: [];
    $migrations = [];
    foreach ($paths as $path) {
        if (!preg_match('/^(\d{3})_[A-Za-z0-9_-]+\.sql$/', basename($path), $matches)) {
            continue;
        }
        $version = (int)$matches[1];
        if (isset($migrations[$version])) {
            throw new RuntimeException("Duplicate migration version {$version}.");
        }
        linkvault_verified_migration_sql($path, $version);
        $migrations[$version] = $path;
    }
    ksort($migrations, SORT_NUMERIC);

    if (array_keys($migrations) !== range(1, LINKVAULT_SCHEMA_VERSION)) {
        throw new RuntimeException('Migration files must be contiguous and match the application schema version.');
    }

    return $migrations;
}

function add_column_if_missing(PDO $pdo, string $table, string $column, string $definition): void
{
    foreach ($pdo->query('PRAGMA table_info(' . linkvault_quote_identifier($table) . ')') as $existing) {
        if ((string)$existing['name'] === $column) {
            return;
        }
    }

    $pdo->exec(
        'ALTER TABLE ' . linkvault_quote_identifier($table)
        . ' ADD COLUMN ' . linkvault_quote_identifier($column) . ' ' . $definition
    );
}

function prepare_migration(PDO $pdo, int $version): void
{
    if ($version !== 2) {
        return;
    }

    add_column_if_missing($pdo, 'links', 'is_active', 'INTEGER NOT NULL DEFAULT 1');
    add_column_if_missing($pdo, 'links', 'expires_at', 'TEXT DEFAULT NULL');
    add_column_if_missing($pdo, 'links', 'deleted_at', 'TEXT DEFAULT NULL');
}

try {
    if ($databasePath === '') {
        throw new RuntimeException('LINKVAULT_DATABASE_PATH is empty.');
    }
    if (!extension_loaded('pdo_sqlite')) {
        throw new RuntimeException('The pdo_sqlite extension is required.');
    }

    $databaseDir = dirname($databasePath);
    if (!is_dir($databaseDir) && !mkdir($databaseDir, 0775, true) && !is_dir($databaseDir)) {
        throw new RuntimeException('Cannot create the database directory.');
    }

    $migrations = migration_files($root);
    $pdo = new PDO('sqlite:' . $databasePath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 30,
    ]);
    $pdo->exec('PRAGMA busy_timeout = 30000');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA synchronous = NORMAL');
    $pdo->exec('PRAGMA foreign_keys = ON');

    $currentVersion = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
    if ($currentVersion < 0 || $currentVersion > LINKVAULT_SCHEMA_VERSION) {
        throw new RuntimeException(
            "Database schema version {$currentVersion} is newer than supported version " . LINKVAULT_SCHEMA_VERSION . '.'
        );
    }

    $pdo->exec('BEGIN EXCLUSIVE');
    try {
        $lockedVersion = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
        if ($lockedVersion !== $currentVersion) {
            throw new RuntimeException('Database schema version changed while waiting for the migration lock.');
        }

        for ($version = $currentVersion + 1; $version <= LINKVAULT_SCHEMA_VERSION; $version++) {
            $sql = linkvault_verified_migration_sql($migrations[$version], $version);

            prepare_migration($pdo, $version);
            $pdo->exec($sql);
            $pdo->exec('PRAGMA user_version = ' . $version);
        }

        $problems = linkvault_schema_problems($pdo);
        if ($problems) {
            throw new RuntimeException('Schema validation failed: ' . implode('; ', $problems));
        }
        $pdo->exec('COMMIT');
    } catch (Throwable $exception) {
        try {
            $pdo->exec('ROLLBACK');
        } catch (Throwable) {
        }
        throw $exception;
    }

    fwrite(STDOUT, 'Database is at schema version ' . LINKVAULT_SCHEMA_VERSION . ": {$databasePath}" . PHP_EOL);
} catch (Throwable $exception) {
    error_log('LinkVault migration failed: ' . $exception->getMessage());
    fwrite(STDERR, 'Migration failed. Check the PHP error log for details.' . PHP_EOL);
    exit(1);
}
