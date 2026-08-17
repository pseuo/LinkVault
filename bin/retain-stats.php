<?php

declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');

$root = dirname(__DIR__);
$config = require $root . '/config.php';
require $root . '/app/bootstrap.php';
require $root . '/app/LinkService.php';
require $root . '/app/AnalyticsService.php';

$requestId = 'stats-retention-' . bin2hex(random_bytes(8));
try {
    $retentionDays = (int)($config['daily_stats_retention_days'] ?? 365);
    $mode = (string)($config['daily_stats_retention_mode'] ?? 'archive');
    $archiveRetentionDays = (int)($config['daily_stats_archive_retention_days'] ?? 1095);
    $batchSize = (int)($config['maintenance_batch_size'] ?? 500);
    if ($retentionDays < 1 || $retentionDays > 36500
        || $archiveRetentionDays < $retentionDays || $archiveRetentionDays > 36500
        || $batchSize < 10 || $batchSize > 5000
        || !in_array($mode, ['archive', 'delete'], true)) {
        throw new InvalidArgumentException('Daily statistics retention configuration is invalid.');
    }
    $pdo = database($config, 5000, true);
    $result = (new LinkService($pdo))->enforceDailyStatsRetention(
        $retentionDays,
        $mode,
        $archiveRetentionDays,
        $batchSize
    );
    $analyticsHourlyDays = (int)($config['analytics_hourly_retention_days'] ?? 90);
    $analyticsRetentionDays = (int)($config['analytics_retention_days'] ?? 365);
    $analyticsRetention = (new AnalyticsService($pdo))->rollupAndRetain(
        $analyticsHourlyDays,
        $analyticsRetentionDays,
        $batchSize
    );
    audit_event($pdo, $config, 'system', 'daily_stats_retention', 'success', 'statistics', null, [
        'mode' => $mode,
        'retention_days' => $retentionDays,
        'processed' => $result['processed'],
        'archived' => $result['archived'],
        'deleted' => $result['deleted'],
        'archive_retention_days' => $archiveRetentionDays,
        'archive_deleted' => $result['archive_deleted'],
        'cutoff' => $result['cutoff'],
        'cumulative_clicks_pruned' => false,
        'analytics_retention_days' => $analyticsRetentionDays,
        'analytics_hourly_retention_days' => $analyticsHourlyDays,
        'analytics_hourly_rows_rolled_up' => $analyticsRetention['hourly_rows_rolled_up'],
        'analytics_hourly_rows_deleted' => $analyticsRetention['hourly_rows_deleted'],
        'analytics_daily_rows_deleted' => $analyticsRetention['daily_rows_deleted'],
    ]);
    fwrite(STDOUT, "Daily statistics retention completed: {$result['processed']} rows processed." . PHP_EOL);
} catch (Throwable $exception) {
    audit_event(null, $config, 'system', 'daily_stats_retention', 'failure', 'statistics', null, [
        'reason' => limit_text($exception->getMessage(), 200),
    ]);
    log_event($config, 'daily_stats_retention_failed', ['error' => limit_text($exception->getMessage(), 300)]);
    fwrite(STDERR, 'Daily statistics retention failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
