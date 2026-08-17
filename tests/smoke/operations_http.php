<?php

declare(strict_types=1);

    $serverProcesses[] = start_server($root, $port, $environment, $serverOutput);

    $client = new HttpClient($baseUrl);
    $page = null;
    for ($attempt = 0; $attempt < 50; $attempt++) {
        usleep(100000);
        $page = $client->request('GET', '/');
        if ($page['status'] === 200) {
            break;
        }
    }
    assert_true(
        is_array($page) && $page['status'] === 200,
        'The test server did not become ready: ' . (string)@file_get_contents($serverOutput)
    );
    assert_true(str_contains($page['body'], '<h1 id="home-title">链匣 LinkVault</h1>'), 'The root path does not render the public homepage.');
    assert_true(!str_contains($page['body'], 'name="password"'), 'The root path still renders the login form.');
    assert_true(str_contains($page['body'], 'href="/login"'), 'The public homepage has no admin login entry.');
    assert_true(header_value($page, 'X-Content-Type-Options') === 'nosniff', 'Missing X-Content-Type-Options header.');
    assert_true(header_value($page, 'X-Frame-Options') === 'DENY', 'Missing X-Frame-Options header.');
    assert_true(header_value($page, 'Content-Security-Policy') !== null, 'Missing Content-Security-Policy header.');
    assert_true(header_value($page, 'X-Request-ID') !== null, 'Missing request ID header.');
    $probeRowsBefore = (int)$migrationPdo->query('SELECT COUNT(*) FROM healthcheck_probe')->fetchColumn();
    $health = $client->request('GET', '/healthz');
    assert_true($health['status'] === 200 && str_contains($health['body'], '"status":"ok"'), 'Health check must report healthy.');
    $healthPayload = json_decode($health['body'], true, 16, JSON_THROW_ON_ERROR);
    assert_true(
        ($healthPayload['release']['version'] ?? null) === '2.4.0'
            && is_string($healthPayload['release']['build_time'] ?? null)
            && ($healthPayload['release']['schema_version'] ?? null) === LINKVAULT_SCHEMA_VERSION,
        'Health does not expose release, build, and schema metadata.'
    );
    $integrityCachePath = $validBackupDirectory . DIRECTORY_SEPARATOR . '.health-local-backup-integrity.json';
    $integrityCache = linkvault_read_json_marker($integrityCachePath);
    assert_true(
        is_array($integrityCache)
            && ($integrityCache['version'] ?? null) === 2
            && ($integrityCache['valid'] ?? null) === true
            && is_int($integrityCache['identity']['metadata']['changed_at'] ?? null)
            && is_string($integrityCache['identity']['sample_sha256'] ?? null),
        'Health did not persist a valid backup integrity cache.'
    );
    $integrityCheckedAt = (int)$integrityCache['checked_at'];
    assert_true($client->request('GET', '/healthz')['status'] === 200, 'Cached backup health check failed.');
    assert_true(
        (int)(linkvault_read_json_marker($integrityCachePath)['checked_at'] ?? 0) === $integrityCheckedAt,
        'Health recomputed the complete backup hash before the cache interval expired.'
    );
    assert_true(unlink($integrityCachePath), 'Cannot remove the health integrity cache fixture.');
    assert_true(mkdir($integrityCachePath), 'Cannot block health integrity cache writes.');
    $cacheWriteFailureHealth = $client->request('GET', '/healthz');
    assert_true(
        $cacheWriteFailureHealth['status'] === 503
            && !str_contains($cacheWriteFailureHealth['body'], $validBackupDirectory),
        'Health did not fail safely or exposed a path after an integrity cache write failure.'
    );
    assert_true(rmdir($integrityCachePath), 'Cannot restore the health integrity cache path.');
    assert_true($client->request('GET', '/healthz')['status'] === 200, 'Health did not recover after cache writes resumed.');
    assert_true($client->request('GET', '/livez')['status'] === 200, 'Liveness must not depend on the database.');
    $readiness = $client->request('GET', '/readyz');
    assert_true($client->request('GET', '/metrics')['status'] === 401, 'Metrics accepted a request without its Bearer token.');
    $metrics = $client->request('GET', '/metrics', '', ['Authorization: Bearer smoke-metrics-token-234567890']);
    assert_true(
        $metrics['status'] === 200
            && str_contains($metrics['body'], 'linkvault_requests_total')
            && str_contains($metrics['body'], 'linkvault_backup_age_seconds'),
        'The authenticated Prometheus endpoint did not return core metrics.'
    );
    assert_true($readiness['status'] === 200 && str_contains($readiness['body'], '"database_write":true'), 'Readiness must verify writable database storage.');
    $targetFailureTime = gmdate('Y-m-d\TH:i:s\Z');
    $migrationPdo->prepare(<<<'SQL'
        INSERT INTO links (slug, target_url, title, created_at, updated_at)
        VALUES ('targetfailure', 'https://target-failure.test/', 'Target failure probe', :created_at, :updated_at)
    SQL)->execute(['created_at' => $targetFailureTime, 'updated_at' => $targetFailureTime]);
    $targetFailureLinkId = (int)$migrationPdo->lastInsertId();
    $migrationPdo->prepare(<<<'SQL'
        INSERT INTO target_health (
            link_id, target_url_hash, state, reason, checked_at, next_check_at,
            http_status, latency_ms, effective_url, redirect_count, redirect_state,
            consecutive_failures
        ) VALUES (
            :link_id, :target_url_hash, 'anomaly', 'private_address', :checked_at, :next_check_at,
            NULL, NULL, NULL, 0, 'none', 1
        )
    SQL)->execute([
        'link_id' => $targetFailureLinkId,
        'target_url_hash' => hash('sha256', 'https://target-failure.test/'),
        'checked_at' => $targetFailureTime,
        'next_check_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 900),
    ]);
    assert_true(
        $client->request('GET', '/readyz')['status'] === 200
            && $client->request('GET', '/healthz')['status'] === 200,
        'A target health failure must not fail readiness or operational health.'
    );
    $migrationPdo->exec('DELETE FROM links WHERE id = ' . $targetFailureLinkId);
    assert_true(
        (int)$migrationPdo->query('SELECT COUNT(*) FROM target_health WHERE link_id = ' . $targetFailureLinkId)->fetchColumn() === 0,
        'Target health summaries do not cascade when a link is purged.'
    );
    assert_true(
        (int)$migrationPdo->query('SELECT COUNT(*) FROM healthcheck_probe')->fetchColumn() === $probeRowsBefore,
        'Public health probes must not acquire a database write lock.'
    );
    $endpointMonitor = run_process([PHP_BINARY, $root . '/bin/check-http-endpoints.php'], $root, $environment);
    assert_true($endpointMonitor['exit_code'] === 0, 'Public proxy endpoint monitor failed: ' . $endpointMonitor['stderr']);
    $syntheticStatus = linkvault_synthetic_monitor_status([
        'synthetic_status_path' => $syntheticStatusPath,
        'synthetic_status_max_age_seconds' => 900,
    ]);
    assert_true(
        !empty($syntheticStatus['available'])
            && !empty($syntheticStatus['fresh'])
            && ($syntheticStatus['status'] ?? null) === 'success'
            && count($syntheticStatus['probes'] ?? []) >= 4
            && count(array_filter(
                $syntheticStatus['probes'],
                static fn (array $probe): bool => in_array($probe['id'], ['home', 'login', 'api'], true)
                    && $probe['status'] === 'ok'
            )) === 3,
        'Synthetic monitor did not persist fresh endpoint-level results.'
    );
    $freshness = run_process([PHP_BINARY, $root . '/bin/check-backup-age.php'], $root, $environment);
    assert_true($freshness['exit_code'] === 0, 'A fresh verified backup failed the age monitor.');
    $markerPath = $validBackupDirectory . DIRECTORY_SEPARATOR . '.last-local-success.json';
    $backupMarker = json_decode((string)file_get_contents($markerPath), true, 16, JSON_THROW_ON_ERROR);
    assert_true(
        ($backupMarker['schema_version'] ?? null) === LINKVAULT_SCHEMA_VERSION
            && ($backupMarker['release']['version'] ?? null) === '2.4.0'
            && is_string($backupMarker['release']['build_time'] ?? null),
        'Backup marker does not identify the application release.'
    );
    $verifiedBackupPath = $validBackupDirectory . DIRECTORY_SEPARATOR . $backupMarker['backup_file'];
    $temporarilyMissingBackupPath = $verifiedBackupPath . '.missing';
    assert_true(rename($verifiedBackupPath, $temporarilyMissingBackupPath), 'Cannot prepare the deleted-backup health test.');
    assert_true($client->request('GET', '/healthz')['status'] === 503, 'Health accepted a deleted verified backup file.');
    assert_true(rename($temporarilyMissingBackupPath, $verifiedBackupPath), 'Cannot restore the deleted-backup health fixture.');
    $backupModifiedAt = (int)filemtime($verifiedBackupPath);
    $backupTamperOffset = intdiv(max(0, (int)filesize($verifiedBackupPath) - 4096), 3) + 17;
    $backupHandle = fopen($verifiedBackupPath, 'r+b');
    assert_true(is_resource($backupHandle), 'Cannot open the backup tamper fixture.');
    assert_true(fseek($backupHandle, $backupTamperOffset) === 0, 'Cannot seek to the backup interior sample.');
    $originalBackupByte = fread($backupHandle, 1);
    assert_true(is_string($originalBackupByte) && strlen($originalBackupByte) === 1, 'Cannot read the backup tamper fixture.');
    assert_true(fseek($backupHandle, $backupTamperOffset) === 0, 'Cannot rewind the backup interior sample.');
    assert_true(fwrite($backupHandle, chr(ord($originalBackupByte) ^ 1)) === 1, 'Cannot tamper with the backup fixture.');
    fflush($backupHandle);
    fclose($backupHandle);
    assert_true(touch($verifiedBackupPath, $backupModifiedAt), 'Cannot preserve the tampered backup modification time.');
    assert_true($client->request('GET', '/healthz')['status'] === 503, 'Health accepted a tampered verified backup file.');
    $backupHandle = fopen($verifiedBackupPath, 'r+b');
    assert_true(is_resource($backupHandle), 'Cannot reopen the backup tamper fixture.');
    assert_true(fseek($backupHandle, $backupTamperOffset) === 0, 'Cannot seek to restore the backup interior sample.');
    assert_true(fwrite($backupHandle, $originalBackupByte) === 1, 'Cannot restore the backup tamper fixture.');
    fflush($backupHandle);
    fclose($backupHandle);
    assert_true(touch($verifiedBackupPath, $backupModifiedAt), 'Cannot restore the backup modification time.');
    assert_true($client->request('GET', '/healthz')['status'] === 200, 'Health did not recover after the verified backup was restored.');
    $missingFileMarker = $backupMarker;
    $missingFileMarker['backup_file'] = 'linkvault-19990101-000000.sqlite';
    file_put_contents($markerPath, json_encode($missingFileMarker, JSON_THROW_ON_ERROR));
    assert_true($client->request('GET', '/healthz')['status'] === 503, 'Health accepted a missing verified backup file.');
    $changedSizeMarker = $backupMarker;
    $changedSizeMarker['size_bytes']++;
    file_put_contents($markerPath, json_encode($changedSizeMarker, JSON_THROW_ON_ERROR));
    assert_true($client->request('GET', '/healthz')['status'] === 503, 'Health accepted a changed backup size.');
    $changedHashMarker = $backupMarker;
    $changedHashMarker['sha256'] = str_repeat('0', 64);
    file_put_contents($markerPath, json_encode($changedHashMarker, JSON_THROW_ON_ERROR));
    assert_true($client->request('GET', '/healthz')['status'] === 503, 'Health accepted a backup hash mismatch.');
    $emptyBackupName = 'linkvault-19990101-000000.sqlite';
    $emptyBackupPath = $validBackupDirectory . DIRECTORY_SEPARATOR . $emptyBackupName;
    file_put_contents($emptyBackupPath, '');
    $emptyBackupMarker = $backupMarker;
    $emptyBackupMarker['backup_file'] = $emptyBackupName;
    $emptyBackupMarker['size_bytes'] = 0;
    $emptyBackupMarker['sha256'] = hash('sha256', '');
    file_put_contents($markerPath, json_encode($emptyBackupMarker, JSON_THROW_ON_ERROR));
    assert_true($client->request('GET', '/healthz')['status'] === 503, 'Health accepted a zero-byte backup.');
    unlink($emptyBackupPath);
    $staleMarker = $backupMarker;
    $staleMarker['completed_at'] = time() - 9 * 3600;
    file_put_contents($markerPath, json_encode($staleMarker, JSON_THROW_ON_ERROR));
    $staleHealth = $client->request('GET', '/healthz');
    assert_true($staleHealth['status'] === 503 && str_contains($staleHealth['body'], '"backup_fresh":false'), 'A stale backup must fail operational health.');
    assert_true($client->request('GET', '/readyz')['status'] === 200, 'A stale backup must not remove a serving instance from readiness.');
    $staleFreshness = run_process([PHP_BINARY, $root . '/bin/check-backup-age.php'], $root, $environment);
    assert_true($staleFreshness['exit_code'] !== 0, 'The backup age monitor accepted a stale marker.');
    file_put_contents($markerPath, json_encode($backupMarker, JSON_THROW_ON_ERROR));

    $missingPort = available_port();
    $missingBaseUrl = 'http://127.0.0.1:' . $missingPort;
    $serverProcesses[] = start_server($root, $missingPort, array_merge($environment, [
        'LINKVAULT_BASE_URL' => $missingBaseUrl,
        'LINKVAULT_DATABASE_PATH' => $testDirectory . DIRECTORY_SEPARATOR . 'missing.sqlite',
    ]), $serverOutput);
    $missingClient = new HttpClient($missingBaseUrl);
    $missingReady = false;
    for ($attempt = 0; $attempt < 50; $attempt++) {
        usleep(100000);
        if ($missingClient->request('GET', '/livez')['status'] === 200) {
            $missingReady = true;
            break;
        }
    }
    assert_true($missingReady, 'The missing-database test server did not start.');
    $missingRedirect = $missingClient->request('GET', '/missing01');
    assert_true($missingRedirect['status'] === 503, 'A missing database must return 503 for public short links.');
    assert_true(header_value($missingRedirect, 'Retry-After') === '5', 'Database 503 responses must include Retry-After.');
    assert_true($client->request('GET', '/', '', ['Host: attacker.example'])['status'] === 421, 'Unknown Host must be rejected.');
    $routerResponse = $client->request('GET', '/router.php');
    assert_true($routerResponse['status'] === 404, 'router.php must not be a second application entry point.');
    assert_true(header_value($routerResponse, 'X-Content-Type-Options') === 'nosniff', 'router.php did not use the main security headers.');
    assert_true($client->request('GET', '/router.php/foo')['status'] === 404, 'router.php PATH_INFO must not be executable.');
