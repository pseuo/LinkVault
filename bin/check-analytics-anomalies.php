<?php

declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');

$root = dirname(__DIR__);
$config = require $root . '/config.php';
require $root . '/app/bootstrap.php';
require $root . '/app/AnalyticsAnomalyService.php';
require $root . '/app/WebhookClient.php';
require $root . '/app/NotificationClaimService.php';

$claims = [];
try {
    $pdo = database($config, 5000, true);
    $service = new AnalyticsAnomalyService($pdo, $config);
    $anomalies = $service->detect();
    $pending = $service->pending($anomalies);
    $webhook = trim((string)($config['alert_webhook_url'] ?? ''));
    if (!$pending) {
        $service->synchronize($anomalies, []);
        fwrite(STDOUT, 'No analytics anomaly notification is due.' . PHP_EOL);
        exit;
    }
    if ($webhook === '') {
        $service->synchronize($anomalies, []);
        fwrite(STDOUT, 'Analytics anomalies detected; alert webhook is not configured.' . PHP_EOL);
        exit;
    }
    $claimService = new NotificationClaimService($pdo);
    $cooldown = max(300, (int)($config['analytics_anomaly_cooldown_seconds'] ?? 21600));
    $bucket = (string)floor(time() / $cooldown);
    $claimedPending = [];
    foreach ($pending as $anomaly) {
        $type = (string)$anomaly['type'];
        $dedupeKey = $type . ':' . $bucket;
        if ($claimService->claim('analytics_anomaly', $dedupeKey)) {
            $claims[$dedupeKey] = true;
            $claimedPending[] = $anomaly;
        }
    }
    if (!$claimedPending) {
        fwrite(STDOUT, 'Analytics anomaly notifications are already claimed.' . PHP_EOL);
        exit;
    }
    $pending = $claimedPending;
    $payload = json_encode([
        'event' => 'linkvault_analytics_anomaly',
        'occurred_at' => gmdate('c'),
        'service' => (string)($config['base_url'] ?? ''),
        'anomalies' => array_map(static fn (array $anomaly): array => [
            'type' => $anomaly['type'],
            'title' => $anomaly['title'],
            'context' => $anomaly['context'],
        ], $pending),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $token = (string)($config['alert_webhook_bearer_token'] ?? '');
    $status = (new WebhookClient())->postJson($webhook, $payload, $token);
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException("Analytics alert webhook returned HTTP {$status}.");
    }
    $notifiedTypes = array_map(static fn (array $anomaly): string => (string)$anomaly['type'], $pending);
    $service->synchronize($anomalies, $notifiedTypes);
    foreach (array_keys($claims) as $dedupeKey) {
        $claimService->complete('analytics_anomaly', $dedupeKey);
    }
    audit_event($pdo, $config, 'system', 'analytics_anomaly_notification', 'success', 'analytics', null, [
        'types' => $notifiedTypes,
    ]);
    fwrite(STDOUT, 'Analytics anomaly notification sent: ' . implode(', ', $notifiedTypes) . PHP_EOL);
} catch (Throwable $exception) {
    if (isset($claimService)) {
        foreach (array_keys($claims) as $dedupeKey) {
            try {
                $claimService->release('analytics_anomaly', $dedupeKey, $exception->getMessage());
            } catch (Throwable) {
            }
        }
    }
    log_event($config, 'analytics_anomaly_check_failed', [
        'error' => limit_text($exception->getMessage(), 300),
    ]);
    fwrite(STDERR, 'Analytics anomaly check failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
