<?php

declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');

require dirname(__DIR__) . '/app/WebhookClient.php';

$unit = limit_unit_name((string)($argv[1] ?? 'linkvault-unknown.service'));
$webhook = trim((string)(getenv('LINKVAULT_ALERT_WEBHOOK_URL') ?: ''));
$bearerToken = (string)(getenv('LINKVAULT_ALERT_BEARER_TOKEN') ?: '');
if ($webhook === '') {
    fwrite(STDERR, 'LINKVAULT_ALERT_WEBHOOK_URL is required.' . PHP_EOL);
    exit(1);
}

$payload = json_encode([
    'service' => 'linkvault',
    'event' => 'systemd_unit_failed',
    'unit' => $unit,
    'host' => gethostname() ?: 'unknown',
    'time' => gmdate('c'),
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
try {
    $status = (new WebhookClient())->postJson($webhook, $payload, $bearerToken);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Alert webhook failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
if ($status < 200 || $status >= 300) {
    fwrite(STDERR, "Alert webhook returned HTTP {$status}." . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, "Failure alert sent for {$unit}." . PHP_EOL);

function limit_unit_name(string $value): string
{
    $value = preg_replace('/[^A-Za-z0-9@_.:-]/', '', $value) ?? '';
    return substr($value, 0, 200) ?: 'linkvault-unknown.service';
}
