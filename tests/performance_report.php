<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$fixtures = __DIR__ . '/fixtures';
$command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/bin/performance-report.php')
    . ' --endpoint-log=' . escapeshellarg($fixtures . '/performance-endpoint.log')
    . ' --static-log=' . escapeshellarg($fixtures . '/performance-static.log')
    . ' --app-log=' . escapeshellarg($fixtures . '/performance-application.log')
    . ' --window-seconds=2147483647';
$lines = [];
$exitCode = 0;
exec($command, $lines, $exitCode);
if ($exitCode !== 0) {
    throw new RuntimeException('Performance report command failed.');
}
$report = json_decode(implode("\n", $lines), true, 32, JSON_THROW_ON_ERROR);
if ((float)($report['routes']['redirect']['p50_ms'] ?? 0) !== 200.0
    || (float)($report['routes']['redirect']['p95_ms'] ?? 0) !== 900.0
    || ($report['routes']['redirect']['server_errors'] ?? null) !== 1
    || ($report['static_assets']['validation_hit_ratio'] ?? null) !== 0.5
    || ($report['static_assets']['encoded_ratio'] ?? null) !== 0.5
    || ($report['sqlite']['slow_queries'] ?? null) !== 1
    || ($report['sqlite']['lock_wait_failures'] ?? null) !== 1) {
    throw new RuntimeException('Performance report metrics are incorrect.');
}
fwrite(STDOUT, 'Performance report tests passed.' . PHP_EOL);
