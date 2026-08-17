<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$work = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'linkvault-analytics-cache-' . bin2hex(random_bytes(6));
$databasePath = $work . '.sqlite';
$cacheDirectory = $work . '-cache';
$logPath = $work . '.log';

function analytics_cache_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$config = array_merge(require $root . '/config.php', [
    'database_path' => $databasePath,
    'application_log_path' => $logPath,
    'analytics_report_cache_directory' => $cacheDirectory,
    'analytics_report_cache_seconds' => 60,
    'analytics_materialize_max_rows' => 10000,
]);
require $root . '/app/bootstrap.php';
require $root . '/app/AnalyticsReportService.php';

try {
    $setup = new PDO('sqlite:' . $databasePath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $setup->exec('PRAGMA journal_mode = WAL');
    $migrations = glob($root . '/migrations/[0-9][0-9][0-9]_*.sql') ?: [];
    sort($migrations, SORT_STRING);
    foreach ($migrations as $migration) {
        $version = (int)substr(basename($migration), 0, 3);
        $setup->exec(linkvault_verified_migration_sql($migration, $version));
        $setup->exec('PRAGMA user_version = ' . $version);
    }
    $today = gmdate('Y-m-d');
    $setup->exec("INSERT INTO links (slug, target_url, title, created_at, updated_at) VALUES ('cache01', 'https://example.test/', 'Cache', '{$today}T00:00:00Z', '{$today}T00:00:00Z')");
    $insert = $setup->prepare(<<<'SQL'
        INSERT INTO visitor_daily_stats (
            link_id, accessed_on, country_code, device_type, browser, operating_system,
            referrer_domain, visitor_kind, request_kind, campaign_name, campaign_source,
            campaign_medium, campaign_content, clicks
        ) VALUES (1, :day, 'CN', 'desktop', 'Chrome', 'Windows', 'direct',
                  'suspected_human', 'redirect_get', '', '', '', '', 5)
    SQL);
    $insert->execute(['day' => $today]);
    $setup = null;

    $pdo = database($config);
    $request = (new AnalyticsReportService($pdo, $config))->normalizeRequest([
        'range' => 'custom', 'start' => $today, 'end' => $today, 'timezone' => 'UTC',
    ]);
    $first = (new AnalyticsReportService($pdo, $config))->dashboard($request);
    analytics_cache_assert((int)$first['totals']['proxy_requests'] === 5, 'Initial analytics total is incorrect.');
    $cacheFiles = glob($cacheDirectory . DIRECTORY_SEPARATOR . '*.json') ?: [];
    analytics_cache_assert(count($cacheFiles) === 1, 'Analytics dashboard cache was not written.');
    $cached = (new AnalyticsReportService($pdo, $config))->dashboard($request);
    analytics_cache_assert($cached == $first, 'Analytics dashboard cache changed the response.');

    $pdo->exec('UPDATE visitor_daily_stats SET clicks = 9 WHERE link_id = 1');
    $updated = (new AnalyticsReportService($pdo, $config))->dashboard($request);
    analytics_cache_assert(
        (int)$updated['totals']['proxy_requests'] === 9,
        'A database write did not invalidate the analytics dashboard cache.'
    );

    $oldDatabase = getenv('LINKVAULT_DATABASE_PATH');
    $oldLog = getenv('LINKVAULT_LOG_PATH');
    putenv('LINKVAULT_DATABASE_PATH=' . $databasePath);
    putenv('LINKVAULT_LOG_PATH=' . $logPath);
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/bin/backfill-analytics-rollups.php');
    $output = [];
    $exitCode = 0;
    exec($command, $output, $exitCode);
    putenv($oldDatabase === false ? 'LINKVAULT_DATABASE_PATH' : 'LINKVAULT_DATABASE_PATH=' . $oldDatabase);
    putenv($oldLog === false ? 'LINKVAULT_LOG_PATH' : 'LINKVAULT_LOG_PATH=' . $oldLog);
    analytics_cache_assert($exitCode === 0, 'Analytics Rollup backfill command failed.');
    analytics_cache_assert(
        $pdo->query("SELECT status FROM analytics_rollup_state WHERE id = 1")->fetchColumn() === 'ready',
        'Analytics Rollup did not become ready.'
    );
    $rolledUp = (new AnalyticsReportService($pdo, array_merge($config, ['analytics_report_cache_seconds' => 0])))
        ->dashboard($request);
    analytics_cache_assert(
        (int)$rolledUp['totals']['proxy_requests'] === 9,
        'Ready Rollup changed the analytics total.'
    );

    fwrite(STDOUT, 'Analytics report cache and Rollup tests passed.' . PHP_EOL);
} finally {
    foreach (glob($cacheDirectory . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
        @unlink($path);
    }
    if (is_dir($cacheDirectory)) {
        @rmdir($cacheDirectory);
    }
    foreach ([$databasePath, $databasePath . '-wal', $databasePath . '-shm', $logPath] as $path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
