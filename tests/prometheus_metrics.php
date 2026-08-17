<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/PrometheusMetrics.php';

$pdo = new PDO('sqlite::memory:');
$pdo->exec('CREATE TABLE links (clicks INTEGER NOT NULL)');
$pdo->exec('INSERT INTO links (clicks) VALUES (7), (5)');
$pdo->exec('CREATE TABLE analytics_export_jobs (status TEXT NOT NULL)');
$pdo->exec("INSERT INTO analytics_export_jobs (status) VALUES ('pending'), ('running'), ('completed')");
$now = time();
$output = PrometheusMetrics::render($pdo, [
    'write_lock' => ['average_wait_ms' => 12, 'max_wait_ms' => 30, 'failure_count' => 2],
    'lifecycle_webhook' => ['pending' => 3, 'dead' => 1],
    'local_backup' => ['completed_at' => $now - 60],
    'remote_backup' => [],
    'analytics' => ['consumer_lag_seconds' => 9, 'backlog_bytes' => 128],
    'target_health' => ['processed' => 4, 'issues' => 1, 'backlog' => 2],
    'synthetic_monitor' => ['probes' => [['id' => 'canary', 'latency_ms' => 44]]],
]);

foreach ([
    'linkvault_requests_total{route="redirect"} 12',
    'linkvault_redirect_latency_seconds{source="canary"} 0.044',
    'linkvault_sqlite_lock_wait_seconds{stat="average"} 0.012',
    'linkvault_sqlite_lock_wait_seconds{stat="maximum"} 0.03',
    'linkvault_queue_backlog{queue="analytics_exports"} 2',
    'linkvault_webhook_dead_letters 1',
    'linkvault_analytics_lag_seconds 9',
    'linkvault_target_check_failure_ratio 0.25',
] as $expected) {
    if (!str_contains($output, $expected)) {
        throw new RuntimeException('Missing Prometheus metric: ' . $expected);
    }
}

fwrite(STDOUT, "Prometheus metrics tests passed.\n");
