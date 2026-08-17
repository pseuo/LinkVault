<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$databasePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'linkvault-db-monitor-' . bin2hex(random_bytes(6)) . '.sqlite';
$logPath = $databasePath . '.log';
$config = array_merge(require $root . '/config.php', [
    'database_path' => $databasePath,
    'application_log_path' => $logPath,
    'sqlite_cache_size_kib' => 4096,
    'sqlite_slow_query_ms' => 1,
]);
require $root . '/app/bootstrap.php';

function database_performance_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

try {
    $setup = new PDO('sqlite:' . $databasePath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $setup->exec('CREATE TABLE monitor_test (id INTEGER PRIMARY KEY, value TEXT NOT NULL)');
    $setup->exec('PRAGMA user_version = ' . LINKVAULT_SCHEMA_VERSION);
    $setup = null;
    $monitored = database($config, 30);
    database_performance_assert(
        (int)$monitored->query('PRAGMA cache_size')->fetchColumn() === -4096,
        'Configured SQLite cache_size was not applied.'
    );
    $monitored->exec('PRAGMA optimize');

    $monitored->query(<<<'SQL'
        WITH RECURSIVE counter(value) AS (
            SELECT 1 UNION ALL SELECT value + 1 FROM counter WHERE value < 100000
        ) SELECT SUM(value) FROM counter
    SQL)->fetchColumn();

    $locker = new PDO('sqlite:' . $databasePath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $locker->exec('PRAGMA journal_mode = WAL');
    $locker->exec('BEGIN IMMEDIATE');
    $statement = $monitored->prepare('INSERT INTO monitor_test (value) VALUES (:value)');
    $locked = false;
    try {
        $statement->execute(['value' => 'secret-value-must-not-be-logged']);
    } catch (PDOException $exception) {
        $locked = is_sqlite_busy($exception);
    } finally {
        $locker->exec('ROLLBACK');
    }
    database_performance_assert($locked, 'SQLite lock contention was not observed.');

    $log = is_file($logPath) ? file_get_contents($logPath) : false;
    database_performance_assert(is_string($log), 'Database performance log was not created.');
    database_performance_assert(str_contains($log, 'sqlite_slow_query'), 'Slow query event was not logged.');
    database_performance_assert(str_contains($log, 'sqlite_lock_wait'), 'Lock wait event was not logged.');
    database_performance_assert(!str_contains($log, 'secret-value-must-not-be-logged'), 'SQL parameter leaked into logs.');

    fwrite(STDOUT, 'Database performance tests passed.' . PHP_EOL);
} finally {
    foreach ([$databasePath, $databasePath . '-wal', $databasePath . '-shm', $logPath] as $path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
