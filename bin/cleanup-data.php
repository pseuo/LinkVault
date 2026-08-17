<?php

declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');

$root = dirname(__DIR__);
$config = require $root . '/config.php';
require $root . '/app/bootstrap.php';
require $root . '/app/LinkService.php';
require $root . '/app/ApiTokenService.php';

$requestId = 'data-cleanup-' . bin2hex(random_bytes(8));
try {
    $idempotencyRetention = (int)($config['idempotency_retention_seconds'] ?? 86400);
    $auditRetention = (int)($config['audit_retention_days'] ?? 180);
    $apiTokenUsageRetention = (int)($config['api_token_usage_retention_days'] ?? 90);
    $apiTokenRetention = (int)($config['api_token_retention_days'] ?? 180);
    $webhookRetention = (int)($config['lifecycle_webhook_retention_days'] ?? 180);
    $webhookAttemptRetention = (int)($config['lifecycle_webhook_attempt_retention_days'] ?? 90);
    $batchSize = (int)($config['maintenance_batch_size'] ?? 500);
    $pdo = database($config, 5000, true);
    $result = (new LinkService($pdo))->cleanupOperationalData(
        $idempotencyRetention,
        $auditRetention,
        $webhookRetention,
        $webhookAttemptRetention,
        $batchSize
    );
    $apiTokenService = new ApiTokenService($pdo);
    $result['api_token_usage'] = $apiTokenService->enforceUsageRetention(
        $apiTokenUsageRetention,
        $batchSize
    );
    $result['api_tokens'] = $apiTokenService->enforceTokenRetention($apiTokenRetention, $batchSize);
    audit_event($pdo, $config, 'system', 'operational_data_cleanup', 'success', 'database', null, [
        'idempotency_retention_seconds' => $idempotencyRetention,
        'audit_retention_days' => $auditRetention,
        'api_token_usage_retention_days' => $apiTokenUsageRetention,
        'api_token_retention_days' => $apiTokenRetention,
        'lifecycle_webhook_retention_days' => $webhookRetention,
        'lifecycle_webhook_attempt_retention_days' => $webhookAttemptRetention,
        'batch_size' => $batchSize,
        'deleted' => $result,
    ]);
    $deleted = array_sum($result);
    fwrite(STDOUT, "Operational data cleanup completed: {$deleted} rows deleted." . PHP_EOL);
} catch (Throwable $exception) {
    audit_event(null, $config, 'system', 'operational_data_cleanup', 'failure', 'database', null, [
        'reason' => limit_text($exception->getMessage(), 200),
    ]);
    log_event($config, 'operational_data_cleanup_failed', [
        'error' => limit_text($exception->getMessage(), 300),
    ]);
    fwrite(STDERR, 'Operational data cleanup failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
