<?php

declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');

$root = dirname(__DIR__);
$config = require $root . '/config.php';
require $root . '/app/bootstrap.php';
require $root . '/app/AnalyticsService.php';

try {
    $pdo = database($config, 5000, true);
    $result = (new AnalyticsService($pdo))->ingestFile(
        (string)$config['analytics_log_path'],
        (string)$config['analytics_state_path'],
        max(1, (int)$config['analytics_batch_max_lines'])
    );
    log_event($config, 'analytics_aggregation_completed', $result);
    fwrite(STDOUT, "Analytics aggregation completed: {$result['accepted']} visits aggregated, {$result['skipped']} skipped." . PHP_EOL);
} catch (Throwable $exception) {
    try {
        linkvault_record_analytics_failure(
            (string)($config['analytics_state_path'] ?? ''),
            limit_text($exception->getMessage(), 300)
        );
    } catch (Throwable $markerException) {
        log_event($config, 'analytics_failure_marker_failed', [
            'error' => limit_text($markerException->getMessage(), 300),
        ]);
    }
    log_event($config, 'analytics_aggregation_failed', ['error' => limit_text($exception->getMessage(), 300)]);
    fwrite(STDERR, 'Analytics aggregation failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
