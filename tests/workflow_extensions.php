<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/app/bootstrap.php';
require $root . '/app/LinkService.php';
require $root . '/app/NotificationClaimService.php';
require $root . '/app/ApiTokenService.php';

function workflow_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$databasePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'linkvault-workflows-' . bin2hex(random_bytes(8)) . '.sqlite';
try {
    $pdo = new PDO('sqlite:' . $databasePath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    foreach (glob($root . '/migrations/*.sql') ?: [] as $migrationPath) {
        $version = (int)basename($migrationPath, '.sql');
        $pdo->exec(linkvault_verified_migration_sql($migrationPath, $version));
    }

    $config = [
        'base_url' => 'https://s.example.test',
        'lifecycle_webhook_url' => 'https://hooks.example.test/events',
        'lifecycle_webhook_signing_secret' => str_repeat('s', 32),
    ];
    $service = new LinkService($pdo, 2048, 100, 5000, $config);

    $presetId = $service->saveLinkPreset('工作链接', [
        'tags' => '工作, 文档',
        'expires_days' => 30,
        'campaign_source' => 'internal',
    ]);
    $presets = $service->linkPresets();
    workflow_assert($presetId > 0 && count($presets) === 1, 'Preset persistence failed.');
    workflow_assert(($presets[0]['values']['expires_days'] ?? null) === 30, 'Preset values were corrupted.');

    $linkId = $service->create('original-code', 'https://example.test/target', 'Alias test', null);
    $before = $service->getAdminLink($linkId);
    workflow_assert(is_array($before), 'Alias test link was not created.');
    workflow_assert($service->update(
        id: $linkId,
        targetUrl: (string)$before['target_url'],
        title: (string)$before['title'],
        expiresAt: null,
        expectedUpdatedAt: (string)$before['updated_at'],
        slug: 'current-code',
        preserveOldSlug: true
    ), 'Short-code update failed.');
    workflow_assert((int)($service->find('original-code')['id'] ?? 0) === $linkId, 'Old short-code alias did not resolve.');
    workflow_assert((int)($service->find('current-code')['id'] ?? 0) === $linkId, 'Current short code did not resolve.');

    $currentSlugState = $service->getAdminLink($linkId);
    workflow_assert($service->update(
        id: $linkId,
        targetUrl: 'https://example.test/target?utm_source=internal',
        title: (string)$currentSlugState['title'],
        expiresAt: null,
        expectedUpdatedAt: (string)$currentSlugState['updated_at'],
        campaignSource: 'internal',
        slug: 'original-code',
        preserveOldSlug: true
    ), 'Reusing the link own alias as its short code failed.');
    workflow_assert((int)($service->find('current-code')['id'] ?? 0) === $linkId, 'Replaced short code was not retained as an alias.');
    $aliases = array_column($service->aliasesForLink($linkId), 'alias');
    workflow_assert($aliases === ['current-code'], 'Alias exchange left an invalid alias set.');
    $aliasConflictItem = [[
        'slug' => 'current-code',
        'target_url' => 'https://example.test/imported',
        'title' => 'Alias import conflict',
    ]];
    $aliasSkip = $service->analyzeImport($aliasConflictItem, 1, 'skip');
    workflow_assert(
        $aliasSkip['writes'] === 0 && $aliasSkip['duplicate'] === 1
            && str_contains((string)$aliasSkip['duplicates'][0]['reason'], '别名'),
        'Import preview did not report an old short-code alias conflict.'
    );
    $aliasOverwrite = $service->analyzeImport($aliasConflictItem, 1, 'overwrite');
    workflow_assert(
        $aliasOverwrite['writes'] === 0 && $aliasOverwrite['duplicate'] === 1,
        'Import preview allowed an old short-code alias to be overwritten.'
    );
    $aliasRename = $service->analyzeImport($aliasConflictItem, 1, 'new_slug');
    workflow_assert(
        $aliasRename['renamed'] === 1 && $aliasRename['items'][0]['result_slug'] === 'current-code-2',
        'Import preview did not allocate a new short code for an alias conflict.'
    );
    $aliasRenameResult = $service->importPrepared($aliasRename['items']);
    workflow_assert(
        $aliasRenameResult['imported'] === 1 && $aliasRenameResult['renamed'] === 1
            && (int)$pdo->query("SELECT COUNT(*) FROM links WHERE slug = 'current-code-2'")->fetchColumn() === 1,
        'Alias-conflict import did not create the previewed replacement short code.'
    );

    $staleAliasItem = [[
        'slug' => 'future-alias',
        'target_url' => 'https://example.test/future',
        'title' => 'Stale alias conflict',
    ]];
    $staleAliasPlan = $service->analyzeImport($staleAliasItem, 1, 'skip');
    $aliasInsert = $pdo->prepare('INSERT INTO link_aliases (alias, link_id, created_at) VALUES (:alias, :link_id, :created_at)');
    $aliasInsert->execute(['alias' => 'future-alias', 'link_id' => $linkId, 'created_at' => utc_timestamp()]);
    try {
        $service->importPrepared($staleAliasPlan['items']);
        workflow_assert(false, 'A newly occupied alias did not invalidate the import preview.');
    } catch (RuntimeException $exception) {
        workflow_assert(str_contains($exception->getMessage(), 'now occupied'), 'Stale alias preview returned the wrong error.');
    }

    $eventId = str_repeat('a', 32);
    $now = utc_timestamp();
    $outbox = $pdo->prepare(<<<'SQL'
        INSERT INTO webhook_outbox (
            event_id, event_type, link_id, dedupe_key, payload_json, status,
            attempts, available_at, created_at, last_error
        ) VALUES (
            :event_id, 'link.created', :link_id, :dedupe_key, '{}', 'dead',
            8, :available_at, :created_at, 'HTTP 500'
        )
    SQL);
    $outbox->execute([
        'event_id' => $eventId,
        'link_id' => $linkId,
        'dedupe_key' => 'workflow-test-event',
        'available_at' => $now,
        'created_at' => $now,
    ]);
    $webhooks = new LifecycleWebhookService($pdo, $config);
    workflow_assert($webhooks->replayDead($eventId), 'Dead-letter replay was rejected.');
    $replayed = $pdo->query("SELECT status, attempts, replay_count, last_error FROM webhook_outbox WHERE event_id = '{$eventId}'")->fetch();
    workflow_assert(
        $replayed && $replayed['status'] === 'pending' && (int)$replayed['attempts'] === 0
            && (int)$replayed['replay_count'] === 1 && $replayed['last_error'] === null,
        'Dead-letter replay state is invalid.'
    );

    $current = $service->getAdminLink($linkId);
    $targetHash = hash('sha256', (string)$current['target_url']);
    $health = $pdo->prepare(<<<'SQL'
        INSERT INTO target_health (
            link_id, target_url_hash, state, reason, checked_at, next_check_at,
            redirect_state, consecutive_failures, redirect_chain_json
        ) VALUES (
            :link_id, :target_url_hash, 'unhealthy', 'http_500', :checked_at, :next_check_at,
            'none', 2, '[]'
        )
    SQL);
    $health->execute([
        'link_id' => $linkId,
        'target_url_hash' => $targetHash,
        'checked_at' => $now,
        'next_check_at' => $now,
    ]);
    workflow_assert($service->repairTargetHealth(
        $linkId,
        'ignore',
        (string)$current['updated_at'],
        $targetHash,
        null,
        'Known maintenance window'
    ), 'Target anomaly ignore failed.');
    $counts = $service->maintenanceCounts(evaluatedAt: $now);
    workflow_assert((int)$counts['target_health'] === 0, 'Ignored target anomaly remained actionable.');

    $snapshotJson = '';
    $service->streamFullSnapshot(static function (string $chunk) use (&$snapshotJson): void {
        $snapshotJson .= $chunk;
    }, $now);
    $snapshot = json_decode($snapshotJson, true, 64, JSON_THROW_ON_ERROR);
    $snapshotWebhook = null;
    foreach ($snapshot['webhook_outbox'] ?? [] as $snapshotEvent) {
        if (($snapshotEvent['event_id'] ?? null) === $eventId) {
            $snapshotWebhook = $snapshotEvent;
            break;
        }
    }
    workflow_assert(
        (int)($snapshotWebhook['replay_count'] ?? -1) === 1,
        'Audit snapshot omitted the webhook replay count.'
    );
    workflow_assert(
        array_key_exists('redirect_chain_json', $snapshot['target_health'][0] ?? [])
            && !empty($snapshot['target_health'][0]['ignored_at'])
            && ($snapshot['target_health'][0]['ignored_reason'] ?? null) === 'Known maintenance window',
        'Audit snapshot omitted target redirect or ignore details.'
    );
    foreach (['link_aliases', 'link_presets', 'webhook_delivery_attempts', 'analytics_ingest_state'] as $snapshotTable) {
        workflow_assert(
            array_key_exists($snapshotTable, $snapshot)
                && in_array($snapshotTable, $snapshot['table_manifest']['included_tables'] ?? [], true),
            "Audit snapshot omitted {$snapshotTable}."
        );
    }

    workflow_assert($service->repairTargetHealth(
        $linkId,
        'target',
        (string)$current['updated_at'],
        $targetHash,
        'https://example.test/repaired?utm_source=stale'
    ), 'Target repair failed.');
    $repaired = $service->getAdminLink($linkId);
    workflow_assert(
        ($repaired['target_url'] ?? null) === 'https://example.test/repaired?utm_source=internal',
        'Target repair did not reconcile stored campaign parameters.'
    );

    $claims = new NotificationClaimService($pdo);
    workflow_assert($claims->claim('maintenance_daily', '2026-08-10'), 'Initial notification claim failed.');
    workflow_assert(!$claims->claim('maintenance_daily', '2026-08-10'), 'Overlapping notification claim was accepted.');
    $claims->release('maintenance_daily', '2026-08-10', 'retry');
    workflow_assert($claims->claim('maintenance_daily', '2026-08-10'), 'Released notification claim was not reusable.');
    $claims->complete('maintenance_daily', '2026-08-10');
    workflow_assert(!$claims->claim('maintenance_daily', '2026-08-10'), 'Completed notification claim was reused.');

    $tokenInsert = $pdo->prepare(<<<'SQL'
        INSERT INTO api_tokens (name, token_prefix, token_hash, scopes, created_at)
        VALUES (:name, :prefix, :token_hash, 'links:read', :created_at)
    SQL);
    for ($tokenNumber = 1; $tokenNumber <= 30; $tokenNumber++) {
        $tokenInsert->execute([
            'name' => 'Pagination token ' . $tokenNumber,
            'prefix' => 'page' . str_pad((string)$tokenNumber, 8, '0', STR_PAD_LEFT),
            'token_hash' => hash('sha256', 'pagination-token-' . $tokenNumber),
            'created_at' => gmdate('Y-m-d\TH:i:s\Z', time() + $tokenNumber),
        ]);
    }
    $tokenPage = (new ApiTokenService($pdo))->listTokens(2, 25);
    workflow_assert(
        $tokenPage['total'] === 30 && $tokenPage['page'] === 2 && count($tokenPage['tokens']) === 5,
        'API token pagination did not enforce a bounded page.'
    );

    $invalidWebhookSchema = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $invalidWebhookSchema->exec(<<<'SQL'
        CREATE TABLE webhook_outbox (event_id TEXT PRIMARY KEY);
        CREATE TABLE webhook_delivery_attempts (
            id INTEGER PRIMARY KEY, event_id TEXT NOT NULL, attempt_number INTEGER NOT NULL,
            attempted_at TEXT NOT NULL, http_status INTEGER, duration_ms INTEGER NOT NULL, error TEXT
        );
    SQL);
    workflow_assert(
        in_array(
            'missing cascade foreign key webhook_delivery_attempts.event_id',
            linkvault_schema_problems($invalidWebhookSchema),
            true
        ),
        'Schema validation accepted a webhook attempt table without its outbox cascade.'
    );

    fwrite(STDOUT, "Workflow extension tests passed.\n");
} finally {
    @unlink($databasePath);
    @unlink($databasePath . '-wal');
    @unlink($databasePath . '-shm');
}
