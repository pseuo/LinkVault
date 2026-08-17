<?php

declare(strict_types=1);

$config = require dirname(__DIR__) . '/config.php';
require dirname(__DIR__) . '/app/bootstrap.php';
require dirname(__DIR__) . '/app/LifecycleWebhookService.php';

require_secure_configuration($config);
$pdo = database($config);

try {
    $thresholds = linkvault_maintenance_thresholds($config);
    $queued = LifecycleWebhookService::enqueueExpiring($pdo, $config, (int)$thresholds['expiring_days']);
    $result = (new LifecycleWebhookService($pdo, $config))->dispatch(50);
    $result['expiring_queued'] = $queued;
    log_event($config, 'lifecycle_webhook_dispatch_completed', $result);
    fwrite(STDOUT, 'Lifecycle webhook dispatch: ' . json_encode($result, JSON_THROW_ON_ERROR) . PHP_EOL);
} catch (Throwable $exception) {
    log_event($config, 'lifecycle_webhook_dispatch_failed', ['error' => limit_text($exception->getMessage(), 300)]);
    fwrite(STDERR, 'Lifecycle webhook dispatch failed.' . PHP_EOL);
    exit(1);
}
