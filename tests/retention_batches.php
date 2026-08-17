<?php

declare(strict_types=1);

$config = [];
require dirname(__DIR__) . '/app/bootstrap.php';
require dirname(__DIR__) . '/app/LinkService.php';
require dirname(__DIR__) . '/app/AnalyticsService.php';

function retention_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

try {
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec(<<<'SQL'
        CREATE TABLE links (id INTEGER PRIMARY KEY, slug TEXT NOT NULL, title TEXT NOT NULL);
        CREATE TABLE link_daily_stats (
            link_id INTEGER NOT NULL, accessed_on TEXT NOT NULL, clicks INTEGER NOT NULL,
            PRIMARY KEY (link_id, accessed_on)
        );
        CREATE TABLE link_daily_stats_archive (
            link_id INTEGER NOT NULL, slug TEXT NOT NULL, title TEXT NOT NULL, accessed_on TEXT NOT NULL,
            clicks INTEGER NOT NULL, archived_at TEXT NOT NULL, PRIMARY KEY (link_id, accessed_on)
        ) WITHOUT ROWID;
        CREATE TABLE idempotency_requests (
            operation TEXT NOT NULL, key_hash TEXT NOT NULL, expires_at INTEGER NOT NULL,
            PRIMARY KEY (operation, key_hash)
        ) WITHOUT ROWID;
        CREATE TABLE create_requests (request_id TEXT PRIMARY KEY, created_at TEXT NOT NULL);
        CREATE TABLE audit_events (id INTEGER PRIMARY KEY, created_at TEXT NOT NULL);
        CREATE TABLE bulk_operations (id TEXT PRIMARY KEY, retain_until INTEGER NOT NULL);
        CREATE TABLE webhook_outbox (
            event_id TEXT PRIMARY KEY, status TEXT NOT NULL, created_at TEXT NOT NULL, delivered_at TEXT
        );
        CREATE TABLE webhook_delivery_attempts (
            id INTEGER PRIMARY KEY, event_id TEXT NOT NULL, attempt_number INTEGER NOT NULL,
            attempted_at TEXT NOT NULL, http_status INTEGER, duration_ms INTEGER NOT NULL, error TEXT,
            FOREIGN KEY (event_id) REFERENCES webhook_outbox(event_id) ON DELETE CASCADE
        );
    SQL);
    $pdo->exec("INSERT INTO links VALUES (1, 'batch', 'Batch')");
    $hotDate = gmdate('Y-m-d', time() - 20 * 86400);
    for ($id = 1; $id <= 7; $id++) {
        $pdo->exec("INSERT INTO links VALUES ({$id} + 1, 'batch{$id}', 'Batch {$id}')");
        $pdo->exec("INSERT INTO link_daily_stats VALUES ({$id} + 1, " . $pdo->quote($hotDate) . ', 1)');
    }
    $pdo->exec("INSERT INTO link_daily_stats_archive VALUES (1, 'batch', 'Batch', '2000-01-01', 1, '2000-01-02T00:00:00Z')");
    $stats = (new LinkService($pdo))->enforceDailyStatsRetention(10, 'archive', 30, 3);
    retention_assert($stats['processed'] === 7 && $stats['archive_deleted'] === 1, 'Daily statistics were not retained across batches.');
    retention_assert((int)$pdo->query('SELECT COUNT(*) FROM link_daily_stats_archive')->fetchColumn() === 7, 'Daily archive contents are incorrect.');

    $now = time();
    for ($id = 1; $id <= 7; $id++) {
        $hash = str_pad((string)$id, 64, '0', STR_PAD_LEFT);
        $pdo->exec("INSERT INTO idempotency_requests VALUES ('test', '{$hash}', " . ($now - 1) . ')');
        $pdo->exec("INSERT INTO audit_events VALUES ({$id}, '2000-01-01T00:00:00Z')");
    }
    $pdo->exec("INSERT INTO webhook_outbox VALUES ('dead', 'dead', '2000-01-01T00:00:00Z', NULL)");
    $pdo->exec("INSERT INTO webhook_outbox VALUES ('pending', 'pending', '2000-01-01T00:00:00Z', NULL)");
    $pdo->exec("INSERT INTO webhook_delivery_attempts VALUES (1, 'dead', 1, '2000-01-01T00:00:00Z', 500, 1, 'failed')");
    $pdo->exec("INSERT INTO webhook_delivery_attempts VALUES (2, 'pending', 1, '2000-01-01T00:00:00Z', 500, 1, 'failed')");
    $cleanup = (new LinkService($pdo))->cleanupOperationalData(60, 1, 1, 1, 3);
    retention_assert($cleanup['idempotency_requests'] === 7 && $cleanup['audit_events'] === 7, 'Operational rows were not deleted across batches.');
    retention_assert($cleanup['webhook_outbox'] === 1, 'Dead webhook retention is incorrect.');
    retention_assert((int)$pdo->query("SELECT COUNT(*) FROM webhook_outbox WHERE status = 'pending'")->fetchColumn() === 1, 'Pending webhook was deleted.');
    retention_assert((int)$pdo->query('SELECT COUNT(*) FROM webhook_delivery_attempts')->fetchColumn() === 0, 'Webhook attempt retention is incorrect.');
    $recordAttempt = new ReflectionMethod(LifecycleWebhookService::class, 'recordAttempt');
    $webhookService = new LifecycleWebhookService($pdo, []);
    for ($attempt = 1; $attempt <= 25; $attempt++) {
        $recordAttempt->invoke($webhookService, 'pending', $attempt, 500, 1, 'failed');
    }
    retention_assert((int)$pdo->query('SELECT COUNT(*) FROM webhook_delivery_attempts')->fetchColumn() === 20, 'Per-event webhook attempt cap was not enforced.');

    $dimensionColumns = <<<'SQL'
        link_id INTEGER NOT NULL, accessed_time TEXT NOT NULL, country_code TEXT NOT NULL,
        device_type TEXT NOT NULL, browser TEXT NOT NULL, operating_system TEXT NOT NULL,
        referrer_domain TEXT NOT NULL, visitor_kind TEXT NOT NULL, request_kind TEXT NOT NULL,
        campaign_name TEXT NOT NULL, campaign_source TEXT NOT NULL, campaign_medium TEXT NOT NULL,
        campaign_content TEXT NOT NULL, clicks INTEGER NOT NULL
    SQL;
    $pdo->exec('CREATE TABLE visitor_hourly_stats (' . str_replace('accessed_time', 'accessed_hour', $dimensionColumns)
        . ', PRIMARY KEY (link_id, accessed_hour, country_code, device_type, browser, operating_system, referrer_domain, visitor_kind, request_kind, campaign_name, campaign_source, campaign_medium, campaign_content)) WITHOUT ROWID');
    $pdo->exec('CREATE TABLE visitor_daily_stats (' . str_replace('accessed_time', 'accessed_on', $dimensionColumns)
        . ', PRIMARY KEY (link_id, accessed_on, country_code, device_type, browser, operating_system, referrer_domain, visitor_kind, request_kind, campaign_name, campaign_source, campaign_medium, campaign_content)) WITHOUT ROWID');
    $pdo->exec('CREATE TABLE analytics_daily_dimensions (' . str_replace('accessed_time', 'accessed_on', $dimensionColumns)
        . ', PRIMARY KEY (link_id, accessed_on, country_code, device_type, browser, operating_system, referrer_domain, visitor_kind, request_kind, campaign_name, campaign_source, campaign_medium, campaign_content)) WITHOUT ROWID');
    $analyticsDate = gmdate('Y-m-d', time() - 20 * 86400);
    for ($hour = 0; $hour < 7; $hour++) {
        $accessedHour = sprintf('%sT%02d:00:00Z', $analyticsDate, $hour);
        $pdo->exec("INSERT INTO visitor_hourly_stats VALUES (1, " . $pdo->quote($accessedHour)
            . ", 'ZZ', 'other', 'Other', 'Other', 'direct', 'unknown', 'redirect_get', '', '', '', '', 1)");
    }
    $analytics = (new AnalyticsService($pdo))->rollupAndRetain(10, 30, 3);
    retention_assert($analytics['hourly_rows_rolled_up'] === 7, 'Analytics rows were not rolled up across batches.');
    retention_assert((int)$pdo->query('SELECT SUM(clicks) FROM visitor_daily_stats')->fetchColumn() === 7, 'Batched analytics rollup lost clicks.');

    fwrite(STDOUT, 'Retention batch tests passed.' . PHP_EOL);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
