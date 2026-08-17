<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$config = require $root . '/config.php';
require $root . '/app/bootstrap.php';
require $root . '/app/AnalyticsReportService.php';
require $root . '/app/AnalyticsExportJobService.php';

try {
    $pdo = database($config);
    $reports = new AnalyticsReportService($pdo, $config);
    $jobs = new AnalyticsExportJobService($pdo, $config, $reports);
    $result = $jobs->process((int)($config['analytics_export_worker_batch_size'] ?? 5));
    fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Analytics export worker failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
