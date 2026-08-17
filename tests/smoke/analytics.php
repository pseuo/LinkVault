<?php

declare(strict_types=1);

    $aliasInsert = $managedPdo->prepare('INSERT INTO link_aliases (alias, link_id, created_at) VALUES (:alias, :link_id, :created_at)');
    $aliasInsert->execute(['alias' => 'campaign-old', 'link_id' => (int)$campaignRow['id'], 'created_at' => gmdate('c')]);
    $analyticsTime = gmdate('c');
    $analyticsLines = [
        json_encode([
            'time' => $analyticsTime,
            'method' => 'GET',
            'uri' => '/campaign-old',
            'status' => 302,
            'country' => 'US',
            'user_agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Version/17.0 Mobile Safari/604.1',
            'referrer' => 'https://news.example/path?private=value',
        ], JSON_THROW_ON_ERROR),
        json_encode([
            'time' => $analyticsTime,
            'method' => 'GET',
            'uri' => '/campaign01',
            'status' => 302,
            'country' => 'DE',
            'user_agent' => 'Googlebot/2.1',
            'referrer' => '',
        ], JSON_THROW_ON_ERROR),
        json_encode([
            'time' => $analyticsTime,
            'method' => 'HEAD',
            'uri' => '/campaign01',
            'status' => 302,
            'country' => '',
            'user_agent' => 'curl/8.0',
            'referrer' => '',
        ], JSON_THROW_ON_ERROR),
        json_encode([
            'time' => $analyticsTime,
            'method' => 'GET',
            'uri' => '/campaign01',
            'status' => 302,
            'country' => 'GB',
            'user_agent' => 'custom-client/1.0',
            'referrer_domain' => 'portal.example',
        ], JSON_THROW_ON_ERROR),
        json_encode([
            'time' => $analyticsTime,
            'method' => 'GET',
            'uri' => '/campaign01',
            'status' => 200,
            'user_agent' => 'ignored',
        ], JSON_THROW_ON_ERROR),
    ];
    assert_true(
        file_put_contents($analyticsLogPath, implode(PHP_EOL, $analyticsLines) . PHP_EOL) !== false,
        'Cannot create the analytics log fixture.'
    );
    $campaignUpdate = $managedPdo->prepare(<<<'SQL'
        UPDATE links
        SET campaign_name = 'winter_launch', updated_at = :updated_at
        WHERE id = :id
    SQL);
    $campaignUpdate->execute(['updated_at' => gmdate('c'), 'id' => (int)$campaignRow['id']]);
    $aggregation = run_process([PHP_BINARY, $root . '/bin/aggregate-analytics.php'], $root, $environment);
    assert_true($aggregation['exit_code'] === 0, 'Analytics aggregation failed: ' . $aggregation['stderr']);
    $analyticsState = json_decode((string)file_get_contents($analyticsStatePath), true, 16, JSON_THROW_ON_ERROR);
    foreach ([
        'version', 'inode', 'offset', 'observed_size', 'active_backlog_bytes', 'backlog_bytes', 'completed_at', 'log_exists',
        'complete', 'read', 'accepted', 'skipped', 'duration_ms', 'lock_wait_ms',
        'throughput_per_second',
    ] as $stateField) {
        assert_true(array_key_exists($stateField, $analyticsState), "Analytics ingest state is missing {$stateField}.");
    }
    assert_true(
        $analyticsState['version'] === 1
            && is_string($analyticsState['inode'])
            && $analyticsState['offset'] === filesize($analyticsLogPath)
            && $analyticsState['observed_size'] === filesize($analyticsLogPath)
            && $analyticsState['active_backlog_bytes'] === 0
            && $analyticsState['backlog_bytes'] === 0
            && is_int($analyticsState['completed_at'])
            && $analyticsState['log_exists'] === true
            && $analyticsState['complete'] === true
            && $analyticsState['read'] === 5
            && $analyticsState['accepted'] === 4
            && $analyticsState['skipped'] === 1
            && is_int($analyticsState['duration_ms'])
            && is_int($analyticsState['lock_wait_ms'])
            && is_int($analyticsState['throughput_per_second']),
        'Analytics ingest state does not describe the completed run.'
    );
    $analyticsRuntimeStatus = linkvault_analytics_status([
        'analytics_state_path' => $analyticsStatePath,
        'analytics_status_max_age_seconds' => 900,
    ]);
    assert_true(
        !empty($analyticsRuntimeStatus['available']) && !empty($analyticsRuntimeStatus['fresh']),
        'A valid analytics ingest state is not reported as fresh.'
    );
    assert_true(
        (int)$managedPdo->query("SELECT SUM(clicks) FROM visitor_hourly_stats WHERE link_id = " . (int)$campaignRow['id'])->fetchColumn() === 4,
        'Analytics aggregation did not retain the expected visits.'
    );
    assert_true(
        (int)$managedPdo->query("SELECT COUNT(*) FROM visitor_hourly_stats WHERE link_id = " . (int)$campaignRow['id'] . " AND visitor_kind IN ('suspected_human', 'bot', 'scanner', 'unknown')")->fetchColumn() === 4,
        'Analytics traffic classification is incomplete.'
    );
    assert_true(
        (int)$managedPdo->query("SELECT SUM(clicks) FROM visitor_hourly_stats WHERE link_id = " . (int)$campaignRow['id'] . " AND campaign_name = 'summer_launch'")->fetchColumn() === 4,
        'Analytics aggregation did not use the campaign snapshot from request time.'
    );
    assert_true(
        (int)$managedPdo->query('SELECT COUNT(*) FROM analytics_ingest_state')->fetchColumn() === 1,
        'Analytics ingest position was not persisted in SQLite.'
    );

    $atomicLine = json_encode([
        'time' => gmdate('c', time() + 1),
        'method' => 'GET',
        'uri' => '/campaign01',
        'status' => 302,
        'country' => 'CA',
        'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/126.0.0.0 Safari/537.36',
        'referrer_domain' => 'search.example',
    ], JSON_THROW_ON_ERROR) . PHP_EOL;
    assert_true(file_put_contents($analyticsLogPath, $atomicLine, FILE_APPEND) !== false, 'Cannot append analytics fixture.');
    $managedPdo->exec(<<<'SQL'
        CREATE TRIGGER analytics_state_failure
        BEFORE UPDATE ON analytics_ingest_state
        BEGIN
            SELECT RAISE(ABORT, 'forced analytics state failure');
        END
    SQL);
    $failedAggregation = run_process([PHP_BINARY, $root . '/bin/aggregate-analytics.php'], $root, $environment);
    assert_true($failedAggregation['exit_code'] !== 0, 'Forced analytics state failure did not fail aggregation.');
    $failedAnalyticsStatus = linkvault_analytics_status([
        'analytics_state_path' => $analyticsStatePath,
        'analytics_status_max_age_seconds' => 900,
    ]);
    assert_true(
        ($failedAnalyticsStatus['collection_state'] ?? null) === 'failed'
            && empty($failedAnalyticsStatus['data_complete']),
        'Analytics aggregation failure was not exposed as incomplete data.'
    );
    assert_true(
        (int)$managedPdo->query("SELECT SUM(clicks) FROM visitor_hourly_stats WHERE link_id = " . (int)$campaignRow['id'])->fetchColumn() === 4,
        'Analytics rows committed without their ingest position.'
    );
    $managedPdo->exec('DROP TRIGGER analytics_state_failure');
    $aggregationRetry = run_process([PHP_BINARY, $root . '/bin/aggregate-analytics.php'], $root, $environment);
    assert_true($aggregationRetry['exit_code'] === 0, 'Analytics retry after transactional rollback failed.');
    assert_true(
        (int)$managedPdo->query("SELECT SUM(clicks) FROM visitor_hourly_stats WHERE link_id = " . (int)$campaignRow['id'])->fetchColumn() === 5,
        'Analytics retry did not aggregate the rolled-back batch exactly once.'
    );
    $aggregationReplay = run_process([PHP_BINARY, $root . '/bin/aggregate-analytics.php'], $root, $environment);
    assert_true($aggregationReplay['exit_code'] === 0, 'Analytics replay aggregation failed.');
    assert_true(
        (int)$managedPdo->query("SELECT SUM(clicks) FROM visitor_hourly_stats WHERE link_id = " . (int)$campaignRow['id'])->fetchColumn() === 5,
        'Analytics ingest state did not prevent duplicate aggregation.'
    );
    $rotatedTail = json_encode([
        'time' => gmdate('c', time() + 2),
        'method' => 'GET',
        'uri' => '/campaign01',
        'status' => 302,
        'country' => 'FR',
        'user_agent' => 'Mozilla/5.0 (X11; Linux x86_64) Firefox/128.0',
        'referrer_domain' => 'rotation.example',
    ], JSON_THROW_ON_ERROR) . PHP_EOL;
    assert_true(file_put_contents($analyticsLogPath, $rotatedTail, FILE_APPEND) !== false, 'Cannot append the rotation tail fixture.');
    $rotatedAnalyticsPath = $analyticsLogPath . '.1';
    assert_true(rename($analyticsLogPath, $rotatedAnalyticsPath), 'Cannot rotate the analytics fixture.');
    $newActiveLine = json_encode([
        'time' => gmdate('c', time() + 3),
        'method' => 'GET',
        'uri' => '/campaign01',
        'status' => 302,
        'country' => 'JP',
        'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_5) Version/17.5 Safari/605.1.15',
        'referrer_domain' => 'active.example',
    ], JSON_THROW_ON_ERROR) . PHP_EOL;
    assert_true(file_put_contents($analyticsLogPath, $newActiveLine) !== false, 'Cannot create the new active analytics fixture.');
    $rotatedAggregation = run_process([PHP_BINARY, $root . '/bin/aggregate-analytics.php'], $root, $environment);
    assert_true($rotatedAggregation['exit_code'] === 0, 'Rotated analytics tail aggregation failed.');
    $rotatedState = json_decode((string)file_get_contents($analyticsStatePath), true, 16, JSON_THROW_ON_ERROR);
    $rotatedStatus = linkvault_analytics_status([
        'analytics_state_path' => $analyticsStatePath,
        'analytics_status_max_age_seconds' => 900,
    ]);
    assert_true(
        $rotatedState['active_backlog_bytes'] === filesize($analyticsLogPath)
            && $rotatedState['backlog_bytes'] === filesize($analyticsLogPath)
            && $rotatedState['complete'] === false
            && !empty($rotatedStatus['available'])
            && ($rotatedStatus['collection_state'] ?? null) === 'backlogged'
            && empty($rotatedStatus['data_complete']),
        'Analytics rotation backlog was not represented as a valid incomplete state.'
    );
    assert_true(
        (int)$managedPdo->query("SELECT SUM(clicks) FROM visitor_hourly_stats WHERE link_id = " . (int)$campaignRow['id'])->fetchColumn() === 6,
        'Analytics switched to the new inode before draining the rotated tail.'
    );
    $activeAggregation = run_process([PHP_BINARY, $root . '/bin/aggregate-analytics.php'], $root, $environment);
    assert_true($activeAggregation['exit_code'] === 0, 'New analytics inode aggregation failed.');
    assert_true(
        (int)$managedPdo->query("SELECT SUM(clicks) FROM visitor_hourly_stats WHERE link_id = " . (int)$campaignRow['id'])->fetchColumn() === 7,
        'Analytics did not continue with the new inode after draining the rotated log.'
    );
    assert_true(unlink($rotatedAnalyticsPath), 'Cannot remove the drained analytics rotation fixture.');
    $cursorCheckpoint = (string)$managedPdo->query(
        'SELECT checkpoint_hash FROM analytics_ingest_state LIMIT 1'
    )->fetchColumn();
    assert_true(strlen($cursorCheckpoint) === 64, 'Analytics cursor did not persist a content checkpoint.');
    $copytruncatePath = $analyticsLogPath . '.1';
    assert_true(copy($analyticsLogPath, $copytruncatePath), 'Cannot create the copytruncate fixture.');
    $copytruncateLines = [];
    for ($copytruncateIndex = 0; $copytruncateIndex < 12; $copytruncateIndex++) {
        $copytruncateLines[] = json_encode([
            'time' => gmdate('c', time() + 10 + $copytruncateIndex),
            'method' => 'GET',
            'uri' => '/campaign01',
            'status' => 302,
            'country' => 'NL',
            'user_agent' => 'Mozilla/5.0 copytruncate-' . $copytruncateIndex,
            'referrer_domain' => 'copytruncate.example',
        ], JSON_THROW_ON_ERROR);
    }
    assert_true(
        file_put_contents($analyticsLogPath, implode(PHP_EOL, $copytruncateLines) . PHP_EOL) !== false
            && filesize($analyticsLogPath) > filesize($copytruncatePath),
        'Copytruncate fixture did not regrow beyond the saved offset.'
    );
    $copytruncateAggregation = run_process([PHP_BINARY, $root . '/bin/aggregate-analytics.php'], $root, $environment);
    assert_true($copytruncateAggregation['exit_code'] === 0, 'Copytruncate recovery aggregation failed.');
    assert_true(
        (int)$managedPdo->query("SELECT SUM(clicks) FROM visitor_hourly_stats WHERE link_id = " . (int)$campaignRow['id'])->fetchColumn() === 19,
        'Copytruncate recovery skipped records written before the previous byte offset.'
    );
    assert_true(unlink($copytruncatePath), 'Cannot remove the copytruncate fixture.');
    $analyticsPage = $client->request('GET', '/?section=analytics&days=7&link=' . (int)$campaignRow['id']);
    assert_true(
        $analyticsPage['status'] === 200
            && str_contains($analyticsPage['body'], '<title>访问分析 - 链匣 LinkVault</title>')
            && str_contains($analyticsPage['body'], '流量画像')
            && str_contains($analyticsPage['body'], 'summer_launch')
            && str_contains($analyticsPage['body'], '较上一周期')
            && str_contains($analyticsPage['body'], '差异对账')
            && str_contains($analyticsPage['body'], '数据更新时间')
            && str_contains($analyticsPage['body'], '聚合完成时间')
            && str_contains($analyticsPage['body'], '待处理积压')
            && str_contains($analyticsPage['body'], '聚合状态')
            && str_contains($analyticsPage['body'], '实际数据覆盖时间')
            && str_contains($analyticsPage['body'], '活跃时段仅覆盖最近 90 天小时数据')
            && str_contains($analyticsPage['body'], '疑似人工访问')
            && str_contains($analyticsPage['body'], '未知 / 无法分类')
            && str_contains($analyticsPage['body'], '不计算百分比')
            && str_contains($analyticsPage['body'], 'class="trend-suspected"')
            && str_contains($analyticsPage['body'], 'class="activity-fill"')
            && !str_contains($analyticsPage['body'], 'style='),
        'Visitor analytics workspace did not render aggregated campaign data.'
    );
    $analyticsCsv = $client->request('GET', '/export-analytics?days=30');
    assert_true(
        $analyticsCsv['status'] === 200
            && str_contains((string)header_value($analyticsCsv, 'Content-Type'), 'text/csv')
            && str_contains($analyticsCsv['body'], 'summer_launch'),
        'Campaign CSV export is unavailable or incomplete.'
    );
    $customAnalyticsPage = $client->request(
        'GET',
        '/?section=analytics&range=custom&start=' . gmdate('Y-m-d', time() - 86400)
            . '&end=' . gmdate('Y-m-d')
            . '&timezone=Asia%2FShanghai&link=' . (int)$campaignRow['id']
            . '&campaign=summer_launch&source=newsletter&medium=email&device=mobile&country=US'
            . '&traffic=suspected_human'
    );
    assert_true(
        $customAnalyticsPage['status'] === 200
            && str_contains($customAnalyticsPage['body'], 'value="custom" selected')
            && str_contains($customAnalyticsPage['body'], 'Asia/Shanghai')
             && str_contains($customAnalyticsPage['body'], '链接表现排行')
            && str_contains($customAnalyticsPage['body'], 'Campaign link · campaign01')
             && str_contains($customAnalyticsPage['body'], '当前筛选结果'),
        'Custom local-time analytics filters and rankings did not render.'
    );
    $invalidAnalyticsPage = $client->request(
        'GET',
        '/?section=analytics&range=custom&start=2026-02-30&end=2026-03-01'
    );
    assert_true(
        $invalidAnalyticsPage['status'] === 303
            && header_value($invalidAnalyticsPage, 'Location') === '/?section=analytics',
        'Invalid custom analytics dates were not rejected explicitly.'
    );
    $invalidAnalyticsMessagePage = $client->request('GET', '/?section=analytics');
    assert_true(
        str_contains($invalidAnalyticsMessagePage['body'], '自定义分析日期无效'),
        'Invalid custom analytics dates did not show an actionable error.'
    );
    $deviceCsv = $client->request(
        'GET',
        '/export-analytics?report=devices&range=custom&start=' . gmdate('Y-m-d', time() - 86400)
            . '&end=' . gmdate('Y-m-d') . '&timezone=Asia%2FShanghai&campaign=summer_launch'
    );
    assert_true(
        $deviceCsv['status'] === 200
            && str_contains($deviceCsv['body'], '设备')
            && str_contains($deviceCsv['body'], 'mobile'),
        'Filtered device analytics CSV is unavailable or incomplete.'
    );

    $exportLimitHour = gmdate('Y-m-d\TH:00:00\Z');
    $exportLimitInsert = $managedPdo->prepare(<<<'SQL'
        WITH digits(value) AS (
            VALUES (0), (1), (2), (3), (4), (5), (6), (7), (8), (9)
        ), numbers(value) AS (
            SELECT a.value + b.value * 10 + c.value * 100 + d.value * 1000 + e.value * 10000
            FROM digits a CROSS JOIN digits b CROSS JOIN digits c CROSS JOIN digits d CROSS JOIN digits e
        )
        INSERT INTO visitor_hourly_stats (
            link_id, accessed_hour, country_code, device_type, browser, operating_system,
            referrer_domain, visitor_kind, request_kind, campaign_name, campaign_source,
            campaign_medium, campaign_content, clicks
        )
        SELECT :link_id, :accessed_hour, 'ZZ', 'other', 'Other', 'Other',
               'export-limit-' || printf('%05d', value) || '.example', 'unknown',
               'redirect_get', '', '', '', '', 1
        FROM numbers WHERE value <= 50000
    SQL);
    $exportLimitInsert->execute([
        'link_id' => (int)$campaignRow['id'],
        'accessed_hour' => $exportLimitHour,
    ]);
    $oversizedAnalyticsCsv = $client->request('GET', '/export-analytics?report=sources&days=30');
    assert_true(
        $oversizedAnalyticsCsv['status'] === 303,
        'An analytics CSV over 50,000 rows was returned as an apparently complete file.'
    );
    $oversizedAnalyticsPage = $client->request('GET', '/?section=analytics');
    assert_true(
        str_contains($oversizedAnalyticsPage['body'], '未生成不完整文件'),
        'Analytics CSV row-limit rejection did not explain that no incomplete file was generated.'
    );
    $managedPdo->exec("DELETE FROM visitor_hourly_stats WHERE referrer_domain LIKE 'export-limit-%'");

    $oldAnalyticsDate = gmdate('Y-m-d', strtotime('-120 days'));
    $oldAnalytics = $managedPdo->prepare(<<<'SQL'
        INSERT INTO visitor_hourly_stats (
            link_id, accessed_hour, country_code, device_type, browser, operating_system,
            referrer_domain, visitor_kind, request_kind, campaign_name, campaign_source,
            campaign_medium, campaign_content, clicks
        ) VALUES (
            :link_id, :accessed_hour, 'US', 'mobile', 'Safari', 'iOS', 'direct',
            'suspected_human', 'legacy_unknown', 'summer_launch', 'newsletter', 'email',
            'archive_test', 7
        )
    SQL);
    $oldAnalytics->execute([
        'link_id' => (int)$campaignRow['id'],
        'accessed_hour' => $oldAnalyticsDate . 'T12:00:00Z',
    ]);
    $analyticsRetention = run_process([PHP_BINARY, $root . '/bin/retain-stats.php'], $root, $environment);
    assert_true($analyticsRetention['exit_code'] === 0, 'Analytics daily rollup failed.');
    assert_true(
        (int)$managedPdo->query("SELECT COUNT(*) FROM visitor_hourly_stats WHERE accessed_hour < datetime('now', '-90 days')")->fetchColumn() === 0
            && (int)$managedPdo->query("SELECT SUM(clicks) FROM visitor_daily_stats WHERE accessed_on = " . $managedPdo->quote($oldAnalyticsDate))->fetchColumn() === 7,
        'Analytics hourly rows were not transactionally rolled up to daily storage.'
    );
    $coverageAnalyticsPage = $client->request(
        'GET',
        '/?section=analytics&range=custom&start=' . gmdate('Y-m-d', strtotime('-400 days'))
            . '&end=' . gmdate('Y-m-d')
    );
    assert_true(
        $coverageAnalyticsPage['status'] === 200
            && str_contains($coverageAnalyticsPage['body'], '实际数据覆盖时间')
            && str_contains($coverageAnalyticsPage['body'], '数据已清理')
            && str_contains($coverageAnalyticsPage['body'], '零值不代表零流量'),
        'Analytics retention gaps were rendered as zero traffic instead of cleaned data.'
    );
    assert_true(unlink($analyticsLogPath), 'Cannot remove the analytics log fixture.');
    $missingLogAggregation = run_process([PHP_BINARY, $root . '/bin/aggregate-analytics.php'], $root, $environment);
    assert_true($missingLogAggregation['exit_code'] === 0, 'Missing-log analytics aggregation failed.');
    $missingLogState = json_decode((string)file_get_contents($analyticsStatePath), true, 16, JSON_THROW_ON_ERROR);
    assert_true(
        $missingLogState['version'] === 1
            && $missingLogState['inode'] === ''
            && $missingLogState['offset'] === 0
            && $missingLogState['observed_size'] === 0
            && $missingLogState['active_backlog_bytes'] === 0
            && $missingLogState['backlog_bytes'] === 0
            && $missingLogState['log_exists'] === false
            && $missingLogState['complete'] === true
            && $missingLogState['read'] === 0
            && $missingLogState['accepted'] === 0
            && $missingLogState['skipped'] === 0,
        'A successful missing-log run did not persist a complete analytics status.'
    );
    $incompleteAnalyticsPage = $client->request('GET', '/?section=analytics');
    assert_true(
        $incompleteAnalyticsPage['status'] === 200
            && str_contains($incompleteAnalyticsPage['body'], '采集日志缺失')
            && str_contains($incompleteAnalyticsPage['body'], '流量数据暂不可判定')
            && str_contains($incompleteAnalyticsPage['body'], '零值不可用于判断没有流量')
            && !str_contains($incompleteAnalyticsPage['body'], 'aria-label="访客统计摘要"'),
        'Analytics collection failure was rendered as a zero-traffic report.'
    );
    $anomalyCheck = run_process([PHP_BINARY, $root . '/bin/check-analytics-anomalies.php'], $root, array_merge(
        $environment,
        ['LINKVAULT_ALERT_WEBHOOK_URL' => '', 'LINKVAULT_ALERT_BEARER_TOKEN' => '']
    ));
    assert_true($anomalyCheck['exit_code'] === 0, 'Analytics anomaly check failed without a webhook.');
    assert_true(
        (int)$managedPdo->query("SELECT is_active FROM analytics_alert_state WHERE anomaly_type = 'aggregation_stopped'")->fetchColumn() === 1,
        'Stopped analytics aggregation was not persisted as an active anomaly.'
    );
    assert_true(
        (int)$managedPdo->query("SELECT is_active FROM analytics_alert_state WHERE anomaly_type = 'traffic_zero'")->fetchColumn() === 0,
        'Incomplete analytics collection was reported as a real zero-traffic anomaly.'
    );
    $analyticsStatusPage = $client->request('GET', '/?section=status');
    assert_true(
        $analyticsStatusPage['status'] === 200
            && str_contains($analyticsStatusPage['body'], '访问分析聚合')
            && str_contains($analyticsStatusPage['body'], 'data-local-time')
            && str_contains($analyticsStatusPage['body'], 'data-timezone-label')
            && str_contains($analyticsStatusPage['body'], '日志不存在'),
        'The status center does not expose analytics runtime details.'
    );
    $limitedHead = $client->request('HEAD', '/managed01');
    assert_true(
        $limitedHead['status'] === 405
            && header_value($limitedHead, 'Location') === null
            && $limitedHead['body'] === '',
        'Limited link HEAD exposed its target address.'
    );
    assert_true((int)$managedPdo->query("SELECT clicks FROM links WHERE id = {$managedId}")->fetchColumn() === 0, 'Rejected HEAD consumed limited-link quota.');
    assert_true($client->request('GET', '/managed01')['status'] === 302, 'Limited link first click failed.');
    assert_true($client->request('GET', '/managed01')['status'] === 302, 'Limited link second click failed.');
    assert_true($client->request('GET', '/managed01')['status'] === 404, 'Limited link exceeded its click cap.');
    assert_true((int)$managedPdo->query("SELECT COUNT(*) FROM link_status_history WHERE link_id = {$managedId} AND event = 'click_limit_reached'")->fetchColumn() === 1, 'Click-limit status history is missing.');
