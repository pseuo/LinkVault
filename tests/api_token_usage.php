<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/app/bootstrap.php';
require $root . '/app/ApiTokenService.php';

function api_token_usage_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec(<<<'SQL'
    CREATE TABLE api_tokens (
        id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, token_prefix TEXT NOT NULL,
        token_hash TEXT NOT NULL UNIQUE, scopes TEXT NOT NULL, created_at TEXT NOT NULL,
        expires_at TEXT, last_used_at TEXT, use_count INTEGER NOT NULL DEFAULT 0,
        revoked_at TEXT, rotated_from_id INTEGER, rotation_expires_at TEXT,
        quota_requests INTEGER, quota_window_seconds INTEGER, allowed_cidrs TEXT NOT NULL DEFAULT ''
    );
    CREATE TABLE api_token_usage (
        id INTEGER PRIMARY KEY AUTOINCREMENT, token_id INTEGER, token_name TEXT NOT NULL,
        token_prefix TEXT NOT NULL, used_at TEXT NOT NULL, outcome TEXT NOT NULL,
        endpoint TEXT NOT NULL, request_id TEXT
    );
    CREATE TABLE api_token_alerts (
        token_id INTEGER NOT NULL, alert_type TEXT NOT NULL, occurrence_count INTEGER NOT NULL DEFAULT 1,
        first_seen_at TEXT NOT NULL, last_seen_at TEXT NOT NULL, last_endpoint TEXT NOT NULL,
        last_client_ip TEXT NOT NULL, PRIMARY KEY (token_id, alert_type)
    );
SQL);

$service = new ApiTokenService($pdo);
$created = $service->create('usage test', null, ['links:create'], 5, 60, '192.0.2.0/24,2001:db8::/32');
$identified = $service->authenticate($created['token']);
api_token_usage_assert(is_array($identified) && $identified['accepted'] === true, 'Token identification failed.');
api_token_usage_assert($identified['quota_requests'] === 5 && $identified['quota_window_seconds'] === 60, 'Token quota was not stored.');
api_token_usage_assert(ApiTokenService::clientAllowed('192.0.2.10', $identified['allowed_cidrs']), 'Allowed IPv4 CIDR was rejected.');
api_token_usage_assert(!ApiTokenService::clientAllowed('198.51.100.10', $identified['allowed_cidrs']), 'Disallowed IPv4 address was accepted.');
api_token_usage_assert(ApiTokenService::clientAllowed('2001:db8::1', $identified['allowed_cidrs']), 'Allowed IPv6 CIDR was rejected.');
$service->recordAlert((int)$identified['id'], 'cidr_denied', '/api/shorten', '198.51.100.10');
$service->recordAlert((int)$identified['id'], 'cidr_denied', '/api/links', '198.51.100.11');
api_token_usage_assert((int)$service->alerts()[0]['occurrence_count'] === 2, 'Token anomaly alert was not aggregated.');
api_token_usage_assert(
    (int)$pdo->query('SELECT COUNT(*) FROM api_token_usage')->fetchColumn() === 0,
    'Token identification wrote usage before rate and scope checks.'
);
$service->recordManagedUsage($identified, '/api/shorten', 'accepted', 2);
api_token_usage_assert(
    (int)$pdo->query('SELECT use_count FROM api_tokens')->fetchColumn() === 1,
    'Accepted token usage was not counted.'
);

$pdo->exec("UPDATE api_tokens SET revoked_at = '2026-01-01T00:00:00Z'");
$rejected = $service->authenticate($created['token']);
api_token_usage_assert(is_array($rejected) && $rejected['outcome'] === 'revoked', 'Revoked token was accepted.');
for ($index = 0; $index < 4; $index++) {
    $service->authenticate($created['token']);
}
api_token_usage_assert(
    (int)$pdo->query("SELECT COUNT(*) FROM api_token_usage WHERE outcome <> 'accepted'")->fetchColumn() === 0,
    'Rejected token authentication wrote usage records.'
);

fwrite(STDOUT, "API token usage tests passed.\n");
