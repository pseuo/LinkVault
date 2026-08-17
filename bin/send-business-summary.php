<?php

declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');

$root = dirname(__DIR__);
$config = require $root . '/config.php';
require $root . '/app/bootstrap.php';
require $root . '/app/LinkService.php';
require $root . '/app/AnalyticsReportService.php';
require $root . '/app/AnalyticsAnomalyService.php';
require $root . '/app/BusinessSummaryService.php';
require $root . '/app/WebhookClient.php';
require $root . '/app/NotificationClaimService.php';

$arguments = is_array($_SERVER['argv'] ?? null) ? $_SERVER['argv'] : [];
$frequency = (string)($arguments[1] ?? '');
if (!in_array($frequency, ['weekly', 'monthly'], true)) {
    fwrite(STDERR, "Usage: php bin/send-business-summary.php <weekly|monthly> [--force]\n");
    exit(2);
}
$webhook = trim((string)($config['business_summary_' . $frequency . '_webhook_url'] ?? ''));
$recipients = (array)($config['business_summary_' . $frequency . '_email_recipients'] ?? []);
if ($webhook === '' && $recipients === []) {
    fwrite(STDOUT, "No {$frequency} business-summary subscribers are configured; delivery skipped.\n");
    exit(0);
}

$claimKey = null;
try {
    $pdo = database($config, 5000, true);
    $summary = (new BusinessSummaryService($pdo, $config))->build($frequency);
    $claimKey = in_array('--force', $arguments, true) ? bin2hex(random_bytes(8)) : $summary['period']['end'];
    $claims = new NotificationClaimService($pdo);
    $claimType = 'business_summary_' . $frequency;
    if (!$claims->claim($claimType, $claimKey, 900)) {
        fwrite(STDOUT, ucfirst($frequency) . " business summary was already sent.\n");
        exit(0);
    }
    $payload = json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    if ($webhook !== '') {
        $status = (new WebhookClient())->postJson(
            $webhook,
            $payload,
            (string)($config['business_summary_' . $frequency . '_webhook_bearer_token'] ?? '')
        );
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException("Business-summary webhook returned HTTP {$status}.");
        }
    }
    if ($recipients !== []) {
        $subject = 'LinkVault ' . ($frequency === 'weekly' ? 'weekly' : 'monthly')
            . ' summary: ' . $summary['period']['start'] . ' to ' . $summary['period']['end'];
        $headers = ['Content-Type: text/plain; charset=UTF-8'];
        $from = (string)($config['business_summary_email_from'] ?? '');
        if ($from !== '') {
            $headers[] = 'From: ' . $from;
        }
        foreach ($recipients as $recipient) {
            if (!mail($recipient, $subject, business_summary_email_body($summary), implode("\r\n", $headers))) {
                throw new RuntimeException("Business-summary email could not be sent to {$recipient}.");
            }
        }
    }
    $claims->complete($claimType, $claimKey);
    audit_event($pdo, $config, 'system', 'business_summary_delivery', 'success', 'analytics', null, [
        'frequency' => $frequency,
        'period_end' => $summary['period']['end'],
        'webhook' => $webhook !== '',
        'email_recipients' => count($recipients),
    ]);
    fwrite(STDOUT, ucfirst($frequency) . " business summary sent.\n");
} catch (Throwable $exception) {
    if (isset($claims, $claimType, $claimKey) && is_string($claimKey)) {
        try {
            $claims->release($claimType, $claimKey, $exception->getMessage());
        } catch (Throwable) {
        }
    }
    log_event($config, 'business_summary_delivery_failed', ['error' => limit_text($exception->getMessage(), 300)]);
    fwrite(STDERR, 'Business-summary delivery failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

/** @param array<string, mixed> $summary */
function business_summary_email_body(array $summary): string
{
    $totals = (array)$summary['analytics']['totals'];
    $deltas = (array)$summary['analytics']['deltas'];
    $health = (array)$summary['link_health'];
    $lines = [
        'LinkVault business summary',
        'Period: ' . $summary['period']['start'] . ' to ' . $summary['period']['end'],
        '',
        'New links: ' . $summary['new_links']['current'] . ' (' . signed_number((int)$summary['new_links']['delta']) . ')',
        'Requests: ' . ($totals['proxy_requests'] ?? 0) . ' (' . signed_number((int)($deltas['proxy_requests'] ?? 0)) . ')',
        'Suspected human: ' . ($totals['suspected_human_requests'] ?? 0),
        'Automated: ' . ((int)($totals['bot_requests'] ?? 0) + (int)($totals['scanner_requests'] ?? 0)),
        '',
        'Actionable links: invalid ' . ($health['invalid'] ?? 0)
            . ', unhealthy targets ' . ($health['target_health'] ?? 0)
            . ', expiring ' . ($health['expiring'] ?? 0),
        'Active anomalies: ' . count((array)$summary['anomalies']),
        'Top links:',
    ];
    foreach ((array)$summary['analytics']['top_links'] as $link) {
        $lines[] = '- ' . ($link['slug'] ?: '(no slug)') . ': ' . $link['requests'];
    }
    return implode("\n", $lines) . "\n";
}

function signed_number(int $value): string
{
    return ($value >= 0 ? '+' : '') . $value;
}
