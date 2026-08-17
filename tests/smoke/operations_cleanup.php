<?php

declare(strict_types=1);

    $adminPage = $client->request('GET', '/');
    assert_true(str_contains($adminPage['body'], 'data-local-time'), 'Stored UTC times are not marked for local display.');
    assert_true(str_contains($adminPage['body'], 'data-expiration-offset'), 'Expiration forms do not submit a local timezone offset.');
    assert_true(!str_contains($adminPage['body'], '过期时间，可选（UTC）'), 'The expiration field still presents local input as UTC.');
    assert_true(str_contains($adminPage['body'], 'aria-label="退出登录"'), 'Logout button has no accessible name.');
    assert_true(str_contains($adminPage['body'], '当前视图近 14 日跳转统计'), 'Statistics do not state their current-view scope.');
    $overviewPage = $client->request('GET', '/links/overview?view=active&q=&status=all&tag=&favorite=');
    assert_true(str_contains($overviewPage['body'], '按 UTC 自然日聚合'), 'Statistics do not disclose UTC aggregation.');
    assert_true(str_contains($adminPage['body'], '导出当前筛选') && str_contains($adminPage['body'], '导出所选'), 'Scoped export controls are missing.');
    $auditPage = $client->request('GET', '/?section=audit&action=clear_expiration');
    assert_true(str_contains($auditPage['body'], '查看字段变更') && str_contains($auditPage['body'], 'before'), 'Structured before/after audit is not rendered.');
    $directEditPage = $client->request('GET', '/edit?id=' . $activeId);
    assert_true(
        $directEditPage['status'] === 200
            && str_contains($directEditPage['body'], '编辑短链接：active01')
            && str_contains($directEditPage['body'], 'class="edit-form standalone-edit-form"'),
        'The exact direct edit route is unavailable without client-side scripting.'
    );

    $activeStatsTotal = (int)$pdo->query(<<<'SQL'
        SELECT COALESCE(SUM(stats.clicks), 0)
        FROM link_daily_stats stats
        INNER JOIN links ON links.id = stats.link_id
        WHERE links.deleted_at IS NULL
          AND stats.accessed_on >= date('now', '-13 days')
    SQL)->fetchColumn();
    assert_true(str_contains($overviewPage['body'], '累计 ' . $activeStatsTotal . ' 次'), 'Recent click total does not match the current view.');

    $beforeClicks = (int)$pdo->query("SELECT clicks FROM links WHERE slug = 'active01'")->fetchColumn();
    $concurrentBaseUrls = [$baseUrl];
    for ($serverIndex = 1; $serverIndex < 4; $serverIndex++) {
        $concurrentPort = available_port();
        $concurrentBaseUrl = 'http://127.0.0.1:' . $concurrentPort;
        $serverProcesses[] = start_server(
            $root,
            $concurrentPort,
            array_merge($environment, ['LINKVAULT_BASE_URL' => $concurrentBaseUrl]),
            $serverOutput . '.' . $concurrentPort
        );
        $concurrentClient = new HttpClient($concurrentBaseUrl);
        $ready = false;
        for ($attempt = 0; $attempt < 50; $attempt++) {
            usleep(100000);
            if ($concurrentClient->request('GET', '/')['status'] === 200) {
                $ready = true;
                break;
            }
        }
        assert_true($ready, 'A concurrent test server did not become ready.');
        $concurrentBaseUrls[] = $concurrentBaseUrl;
    }
    $concurrentCount = 8;
    $exitCodes = start_parallel_redirects($concurrentBaseUrls, $concurrentCount);
    assert_true(!array_filter($exitCodes, static fn (int $code): bool => $code !== 0), 'A concurrent redirect did not return 302.');
    $afterClicks = (int)$pdo->query("SELECT clicks FROM links WHERE slug = 'active01'")->fetchColumn();
    assert_true($afterClicks === $beforeClicks + $concurrentCount, 'Concurrent redirect clicks were lost.');

    $oldDailyDate = gmdate('Y-m-d', time() - 400 * 86400);
    $pdo->exec("INSERT INTO link_daily_stats (link_id, accessed_on, clicks) VALUES ({$activeId}, "
        . $pdo->quote($oldDailyDate) . ", 7)");
    $pdo->exec("INSERT INTO link_daily_stats_archive (link_id, slug, title, accessed_on, clicks, archived_at) "
        . "VALUES ({$activeId}, 'active01', 'expired archive', '2000-01-01', 1, '2000-01-02T00:00:00Z')");
    $cumulativeBeforeRetention = (int)$pdo->query("SELECT clicks FROM links WHERE id = {$activeId}")->fetchColumn();
    $statsRetention = run_process([PHP_BINARY, $root . '/bin/retain-stats.php'], $root, array_merge($environment, [
        'LINKVAULT_DAILY_STATS_RETENTION_DAYS' => '30',
        'LINKVAULT_DAILY_STATS_RETENTION_MODE' => 'archive',
    ]));
    assert_true($statsRetention['exit_code'] === 0, 'Daily-statistics retention failed: ' . $statsRetention['stderr']);
    assert_true((int)$pdo->query("SELECT COUNT(*) FROM link_daily_stats WHERE link_id = {$activeId} AND accessed_on = " . $pdo->quote($oldDailyDate))->fetchColumn() === 0, 'Expired online daily statistics were not removed.');
    assert_true((int)$pdo->query("SELECT clicks FROM link_daily_stats_archive WHERE link_id = {$activeId} AND accessed_on = " . $pdo->quote($oldDailyDate))->fetchColumn() === 7, 'Expired daily statistics were not archived.');
    assert_true((int)$pdo->query("SELECT COUNT(*) FROM link_daily_stats_archive WHERE accessed_on = '2000-01-01'")->fetchColumn() === 0, 'Expired archived daily statistics were not removed.');
    assert_true((int)$pdo->query("SELECT clicks FROM links WHERE id = {$activeId}")->fetchColumn() === $cumulativeBeforeRetention, 'Daily-statistics retention changed cumulative clicks.');
    assert_true((int)$pdo->query("SELECT COUNT(*) FROM audit_events WHERE action = 'daily_stats_retention' AND outcome = 'success'")->fetchColumn() >= 1, 'Daily-statistics retention was not audited.');

    $cleanupNow = time();
    $cleanupApi = $pdo->prepare(<<<'SQL'
        INSERT INTO idempotency_requests (
            operation, key_hash, payload_hash, response_status, response_body, created_at, expires_at
        ) VALUES ('cleanup:test', :key_hash, :payload_hash, 200, '{}', :created_at, :expires_at)
    SQL);
    $cleanupApi->bindValue(':key_hash', str_repeat('a', 64));
    $cleanupApi->bindValue(':payload_hash', str_repeat('b', 64));
    $cleanupApi->bindValue(':created_at', $cleanupNow - 120, PDO::PARAM_INT);
    $cleanupApi->bindValue(':expires_at', $cleanupNow - 60, PDO::PARAM_INT);
    $cleanupApi->execute();
    $pdo->exec("UPDATE create_requests SET created_at = '2000-01-01T00:00:00Z' WHERE request_id = '" . $idempotencyKey . "'");
    $freshCreateRequest = str_repeat('c', 32);
    $freshCreate = $pdo->prepare(<<<'SQL'
        INSERT INTO create_requests (request_id, payload_hash, link_id, created_at)
        VALUES (:request_id, :payload_hash, :link_id, :created_at)
    SQL);
    $freshCreate->execute([
        'request_id' => $freshCreateRequest,
        'payload_hash' => str_repeat('d', 64),
        'link_id' => $activeId,
        'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
    ]);
    $oldAudit = $pdo->prepare(<<<'SQL'
        INSERT INTO audit_events (
            created_at, actor_type, action, outcome, entity_type, entity_id, request_id, details_json
        ) VALUES ('2000-01-01T00:00:00Z', 'system', 'cleanup_fixture', 'success', 'database', NULL, NULL, '{}')
    SQL);
    $oldAudit->execute();
    $pdo->exec(<<<'SQL'
        INSERT INTO webhook_outbox (
            event_id, event_type, link_id, dedupe_key, payload_json, status, attempts,
            available_at, leased_until, last_error, created_at, delivered_at, replay_count
        ) VALUES
            ('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'link.disabled', NULL, 'cleanup:dead', '{}', 'dead', 8,
             '2000-01-01T00:00:00Z', NULL, 'failed', '2000-01-01T00:00:00Z', NULL, 0),
            ('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', 'link.disabled', NULL, 'cleanup:pending', '{}', 'pending', 1,
             '2999-01-01T00:00:00Z', NULL, 'waiting', '2000-01-01T00:00:00Z', NULL, 0)
    SQL);
    $pdo->exec(<<<'SQL'
        INSERT INTO webhook_delivery_attempts (event_id, attempt_number, attempted_at, http_status, duration_ms, error)
        VALUES
            ('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 8, '2000-01-01T00:00:00Z', 500, 10, 'failed'),
            ('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', 1, '2000-01-01T00:00:00Z', 500, 10, 'waiting')
    SQL);
    $oldTokenUsageId = (int)$pdo->query('SELECT id FROM api_token_usage ORDER BY id ASC LIMIT 1')->fetchColumn();
    assert_true($oldTokenUsageId > 0, 'No API token usage record is available for the retention fixture.');
    $pdo->exec("UPDATE api_token_usage SET used_at = '2000-01-01T00:00:00Z' WHERE id = {$oldTokenUsageId}");
    $pdo->exec(<<<'SQL'
        INSERT INTO api_tokens (
            name, token_prefix, token_hash, scopes, created_at, revoked_at
        ) VALUES (
            'Old revoked token', 'oldrevoked12',
            'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'links:create', '2000-01-01T00:00:00Z', '2000-01-02T00:00:00Z'
        )
    SQL);
    $oldRevokedTokenId = (int)$pdo->lastInsertId();
    $freshRetainedUsage = $pdo->prepare(<<<'SQL'
        INSERT INTO api_token_usage (
            token_id, token_name, token_prefix, used_at, outcome, endpoint, request_id
        ) VALUES (
            :token_id, 'Old revoked token', 'oldrevoked12', :used_at, 'revoked', '/api/links', 'retained-usage'
        )
    SQL);
    $freshRetainedUsage->execute(['token_id' => $oldRevokedTokenId, 'used_at' => gmdate('Y-m-d\TH:i:s\Z')]);
    $requestBeforeCleanup = $client->request('POST', '/api/shorten', json_encode([
        'url' => 'https://example.com/cleanup-request',
        'slug' => 'cleanup01',
    ], JSON_THROW_ON_ERROR), $apiHeaders);
    assert_true($requestBeforeCleanup['status'] === 201, 'Cleanup request-path fixture could not be created.');
    assert_true(
        (int)$pdo->query("SELECT COUNT(*) FROM idempotency_requests WHERE operation = 'cleanup:test'")->fetchColumn() === 1,
        'A normal API request still performs expired idempotency cleanup.'
    );
    $cleanup = run_process([PHP_BINARY, $root . '/bin/cleanup-data.php'], $root, array_merge($environment, [
        'LINKVAULT_IDEMPOTENCY_RETENTION_SECONDS' => '60',
        'LINKVAULT_AUDIT_RETENTION_DAYS' => '1',
        'LINKVAULT_API_TOKEN_USAGE_RETENTION_DAYS' => '1',
        'LINKVAULT_API_TOKEN_RETENTION_DAYS' => '1',
        'LINKVAULT_LIFECYCLE_WEBHOOK_RETENTION_DAYS' => '1',
        'LINKVAULT_LIFECYCLE_WEBHOOK_ATTEMPT_RETENTION_DAYS' => '1',
        'LINKVAULT_MAINTENANCE_BATCH_SIZE' => '10',
    ]));
    assert_true($cleanup['exit_code'] === 0, 'Operational data cleanup failed: ' . $cleanup['stderr']);
    assert_true((int)$pdo->query("SELECT COUNT(*) FROM idempotency_requests WHERE operation = 'cleanup:test'")->fetchColumn() === 0, 'The fixed cleanup task retained an expired API idempotency record.');
    assert_true((int)$pdo->query("SELECT COUNT(*) FROM create_requests WHERE request_id = '" . $idempotencyKey . "'")->fetchColumn() === 0, 'The fixed cleanup task retained an expired admin idempotency record.');
    assert_true((int)$pdo->query("SELECT COUNT(*) FROM create_requests WHERE request_id = '{$freshCreateRequest}'")->fetchColumn() === 1, 'The fixed cleanup task removed a current admin idempotency record.');
    assert_true((int)$pdo->query("SELECT COUNT(*) FROM audit_events WHERE action = 'cleanup_fixture'")->fetchColumn() === 0, 'The fixed cleanup task retained an expired audit record.');
    assert_true((int)$pdo->query("SELECT COUNT(*) FROM api_token_usage WHERE id = {$oldTokenUsageId}")->fetchColumn() === 0, 'The fixed cleanup task retained an expired API token usage record.');
    assert_true((int)$pdo->query("SELECT COUNT(*) FROM api_tokens WHERE id = {$oldRevokedTokenId}")->fetchColumn() === 0, 'The fixed cleanup task retained an expired API token.');
    assert_true((int)$pdo->query("SELECT COUNT(*) FROM api_token_usage WHERE request_id = 'retained-usage' AND token_id IS NULL")->fetchColumn() === 1, 'Token cleanup did not preserve usage metadata with a null token reference.');
    assert_true((int)$pdo->query("SELECT COUNT(*) FROM api_token_usage WHERE used_at >= datetime('now', '-1 day')")->fetchColumn() > 0, 'The fixed cleanup task removed current API token usage records.');
    assert_true((int)$pdo->query("SELECT COUNT(*) FROM webhook_outbox WHERE event_id = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'")->fetchColumn() === 0, 'Expired dead webhook event was retained.');
    assert_true((int)$pdo->query("SELECT COUNT(*) FROM webhook_outbox WHERE event_id = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'")->fetchColumn() === 1, 'Pending webhook event was removed by retention.');
    assert_true((int)$pdo->query("SELECT COUNT(*) FROM webhook_delivery_attempts WHERE attempted_at = '2000-01-01T00:00:00Z'")->fetchColumn() === 0, 'Expired webhook delivery attempts were retained.');
    assert_true((int)$pdo->query("SELECT COUNT(*) FROM audit_events WHERE action = 'operational_data_cleanup' AND outcome = 'success'")->fetchColumn() === 1, 'Operational data cleanup was not audited.');

    $canaryEnvironment = array_merge($environment, [
        'LINKVAULT_CANARY_ENABLED' => '1',
        'LINKVAULT_CANARY_SLUG' => 'monitor-canary',
        'LINKVAULT_CANARY_TARGET_URL' => $baseUrl . '/',
    ]);
    $canarySeed = run_process([PHP_BINARY, $root . '/bin/seed-canary.php'], $root, $canaryEnvironment);
    assert_true($canarySeed['exit_code'] === 0, 'Synthetic canary seed failed: ' . $canarySeed['stderr']);
    $canarySeedReplay = run_process([PHP_BINARY, $root . '/bin/seed-canary.php'], $root, $canaryEnvironment);
    assert_true($canarySeedReplay['exit_code'] === 0, 'Synthetic canary seed is not idempotent.');
    $canaryMonitor = run_process([PHP_BINARY, $root . '/bin/check-http-endpoints.php'], $root, $canaryEnvironment);
    assert_true($canaryMonitor['exit_code'] === 0, 'Synthetic endpoint monitor failed: ' . $canaryMonitor['stderr']);
    assert_true(
        (int)$pdo->query("SELECT clicks FROM links WHERE slug = 'monitor-canary'")->fetchColumn() === 0,
        'The synthetic HEAD canary probe changed business click totals.'
    );
