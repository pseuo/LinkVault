<?php

declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');

$root = dirname(__DIR__);
$config = require $root . '/config.php';
require $root . '/app/bootstrap.php';
require $root . '/app/LinkService.php';
require_once $root . '/app/WebhookClient.php';
require_once $root . '/app/NotificationClaimService.php';

$requestId = 'maintenance-' . bin2hex(random_bytes(8));
$arguments = is_array($_SERVER['argv'] ?? null) ? $_SERVER['argv'] : [];
$webhook = trim((string)($config['maintenance_webhook_url'] ?? ''));
$claimKey = null;
if ($webhook === '') {
    fwrite(STDOUT, 'Maintenance webhook is not configured; notification skipped.' . PHP_EOL);
    exit(0);
}
try {
    $pdo = database($config, 5000, true);
    $claimService = new NotificationClaimService($pdo);
    $claimKey = in_array('--force', $arguments, true)
        ? gmdate('Y-m-d') . ':force:' . bin2hex(random_bytes(8))
        : gmdate('Y-m-d');
    if (!$claimService->claim('maintenance_daily', $claimKey, 900)) {
        fwrite(STDOUT, 'Today\'s maintenance notification was already sent.' . PHP_EOL);
        exit(0);
    }

    $thresholds = linkvault_maintenance_thresholds($config);
    $expiringDays = $thresholds['expiring_days'];
    $staleDays = $thresholds['stale_days'];
    $quotaPercent = $thresholds['quota_percent'];
    $evaluatedAt = utc_timestamp();
    $summary = (new LinkService($pdo))->maintenanceSummary(
        $expiringDays,
        $staleDays,
        $quotaPercent,
        20,
        $evaluatedAt
    );
    $summary['backup'] = linkvault_backup_maintenance_summary($config);
    $payload = json_encode([
        'service' => 'linkvault',
        'event' => 'link_maintenance_daily',
        'time' => gmdate('c'),
        'evaluated_at' => $evaluatedAt,
        'base_url' => (string)($config['base_url'] ?? ''),
        'thresholds' => [
            'expiring_days' => $expiringDays,
            'stale_days' => $staleDays,
            'quota_percent' => $quotaPercent,
        ],
        'summary' => $summary,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $bearerToken = (string)($config['maintenance_webhook_bearer_token'] ?? '');
    $status = (new WebhookClient())->postJson($webhook, $payload, $bearerToken);
    $counts = array_map(static fn (array $category): int => (int)$category['count'], $summary);
    if ($status < 200 || $status >= 300) {
        audit_event($pdo, $config, 'system', 'maintenance_notification', 'failure', 'webhook', null, [
            'http_status' => $status,
            'counts' => $counts,
        ]);
        throw new RuntimeException("Maintenance webhook returned HTTP {$status}.");
    }
    audit_event($pdo, $config, 'system', 'maintenance_notification', 'success', 'webhook', null, [
        'http_status' => $status,
        'counts' => $counts,
    ]);
    $claimService->complete('maintenance_daily', $claimKey);
    fwrite(STDOUT, 'Daily maintenance notification sent.' . PHP_EOL);
} catch (Throwable $exception) {
    if (isset($claimService) && is_string($claimKey)) {
        try {
            $claimService->release('maintenance_daily', $claimKey, $exception->getMessage());
        } catch (Throwable) {
        }
    }
    log_event($config, 'maintenance_notification_failed', [
        'error' => limit_text($exception->getMessage(), 300),
    ]);
    fwrite(STDERR, 'Maintenance notification failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
