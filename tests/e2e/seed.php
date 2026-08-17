<?php

declare(strict_types=1);

$databasePath = (string)(getenv('LINKVAULT_DATABASE_PATH') ?: '');
if ($databasePath === '') {
    throw new RuntimeException('LINKVAULT_DATABASE_PATH is required.');
}
$pdo = new PDO('sqlite:' . $databasePath, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$now = gmdate('Y-m-d\TH:i:s\Z');

$domain = $pdo->prepare(<<<'SQL'
INSERT INTO short_domains (
    hostname, verification_token, verified_at, is_enabled, brand_name, brand_tagline,
    brand_theme, brand_color, logo_url, favicon_url, invalid_page_title, invalid_page_message,
    created_at, updated_at
) VALUES (
    'brand.e2e.test', 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee', :now, 1,
    'E2E Brand', 'A real branded domain', 'emerald', '#006B4F', '', '',
    'Unavailable', 'This branded link is unavailable.', :now, :now
)
SQL);
$domain->execute(['now' => $now]);

$insertLink = $pdo->prepare(<<<'SQL'
INSERT INTO links (slug, target_url, title, created_at, updated_at)
VALUES (:slug, :target_url, :title, :now, :now)
SQL);
$insertLink->execute([
    'slug' => 'e2e-target-health',
    'target_url' => 'https://broken.e2e.test/unavailable',
    'title' => 'E2E unhealthy target',
    'now' => $now,
]);
$linkId = (int)$pdo->lastInsertId();

$health = $pdo->prepare(<<<'SQL'
INSERT INTO target_health (
    link_id, target_url_hash, state, reason, checked_at, next_check_at, http_status,
    redirect_state, consecutive_failures, redirect_chain_json
) VALUES (:link_id, :target_hash, 'anomaly', 'http_error', :now, :now, 503, 'none', 3, '[]')
SQL);
$health->execute([
    'link_id' => $linkId,
    'target_hash' => hash('sha256', 'https://broken.e2e.test/unavailable'),
    'now' => $now,
]);

$eventId = 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';
$outbox = $pdo->prepare(<<<'SQL'
INSERT INTO webhook_outbox (
    event_id, event_type, link_id, dedupe_key, payload_json, status, attempts,
    available_at, last_error, created_at, replay_count
) VALUES (:event_id, 'link.target_unhealthy', :link_id, :dedupe_key, :payload, 'dead', 5,
    :now, 'HTTP 503', :now, 0)
SQL);
$outbox->execute([
    'event_id' => $eventId,
    'link_id' => $linkId,
    'dedupe_key' => 'e2e-dead-webhook',
    'payload' => json_encode([
        'event_id' => $eventId,
        'event_type' => 'link.target_unhealthy',
        'link_id' => $linkId,
    ], JSON_THROW_ON_ERROR),
    'now' => $now,
]);
$attempt = $pdo->prepare(<<<'SQL'
INSERT INTO webhook_delivery_attempts (event_id, attempt_number, attempted_at, http_status, duration_ms, error)
VALUES (:event_id, 5, :attempted_at, 503, 120, 'HTTP 503')
SQL);
$attempt->execute(['event_id' => $eventId, 'attempted_at' => $now]);
