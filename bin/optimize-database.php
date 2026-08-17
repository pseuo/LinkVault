<?php

declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');

$root = dirname(__DIR__);
$config = require $root . '/config.php';
require $root . '/app/bootstrap.php';

try {
    $startedAt = hrtime(true);
    $pdo = database($config, 30000, true);
    $before = (int)$pdo->query('PRAGMA freelist_count')->fetchColumn();
    $pdo->exec('PRAGMA optimize');
    $after = (int)$pdo->query('PRAGMA freelist_count')->fetchColumn();
    $durationMs = max(0, (int)round((hrtime(true) - $startedAt) / 1_000_000));
    log_event($config, 'sqlite_optimize_completed', [
        'duration_ms' => $durationMs,
        'freelist_pages_before' => $before,
        'freelist_pages_after' => $after,
    ]);
    fwrite(STDOUT, "SQLite optimize completed in {$durationMs} ms; free pages {$before} -> {$after}." . PHP_EOL);
} catch (Throwable $exception) {
    log_event($config, 'sqlite_optimize_failed', ['error' => limit_text($exception->getMessage(), 300)]);
    fwrite(STDERR, 'SQLite optimize failed. Check the application log.' . PHP_EOL);
    exit(1);
}
