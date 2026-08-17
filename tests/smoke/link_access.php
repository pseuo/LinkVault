<?php

declare(strict_types=1);

    $invalidCreate = $client->form('/shorten', [
        'csrf' => $csrf,
        'target_url' => 'not-a-url',
        'title' => 'Preserved title',
        'custom_slug' => 'preserve01',
        'expires_at' => '',
    ]);
    assert_true($invalidCreate['status'] === 303, 'Invalid create input must redirect.');
    $invalidCreatePage = $client->request('GET', '/');
    assert_true(str_contains($invalidCreatePage['body'], 'value="not-a-url"'), 'Invalid create URL was not preserved.');
    assert_true(str_contains($invalidCreatePage['body'], 'value="Preserved title"'), 'Create title was not preserved.');
    assert_true(str_contains($invalidCreatePage['body'], 'id="create-target-url-error"'), 'Create field error is missing.');

    $create = $client->form('/shorten', [
        'csrf' => $csrf,
        'target_url' => 'https://example.com/active',
        'title' => 'Active link',
        'custom_slug' => 'active01',
        'expires_at' => '',
    ]);
    assert_true($create['status'] === 303, 'Creating a link must redirect.');
    $dashboardPage = $client->request('GET', '/');
    assert_true(
        str_contains($dashboardPage['body'], 'data-overview-url=')
            && !str_contains($dashboardPage['body'], 'class="trend-row"'),
        'Dashboard overview statistics were not deferred from the initial response.'
    );
    $overviewPath = '/links/overview?view=active&q=&status=all&tag=&favorite=';
    $overview = $client->request('GET', $overviewPath);
    $dashboardProgressCount = substr_count($overview['body'], 'class="trend-row"');
    assert_true(
        $overview['status'] === 200
            && header_value($overview, 'X-LinkVault-Cache') === 'MISS'
            && $dashboardProgressCount === 14,
        "Dashboard overview must render a continuous 14-day trend; status {$overview['status']}, found {$dashboardProgressCount}."
    );
    assert_true(
        header_value($client->request('GET', $overviewPath), 'X-LinkVault-Cache') === 'HIT',
        'Dashboard overview did not reuse its short-lived session cache.'
    );
    foreach (['maintenance' => '链接维护', 'audit' => '全局操作审计', 'status' => '系统状态中心'] as $sectionName => $sectionHeading) {
        $sectionPage = $client->request('GET', '/?section=' . $sectionName);
        assert_true($sectionPage['status'] === 200 && str_contains($sectionPage['body'], $sectionHeading), "The {$sectionName} workspace is unavailable.");
        if ($sectionName === 'maintenance') {
            assert_true(
                str_contains($sectionPage['body'], '21 日内过期')
                    && str_contains($sectionPage['body'], '120 日零点击')
                    && str_contains($sectionPage['body'], '配额达到 65%'),
                'The maintenance workspace does not use the configured notification thresholds.'
            );
        }
        if ($sectionName === 'status') {
            assert_true(str_contains($sectionPage['body'], 'health-dot unconfigured'), 'An optional unconfigured service is not shown as unconfigured.');
            foreach ([
                'health-dot ok' => '正常',
                'health-dot attention' => '关注',
                'health-dot error' => '异常',
                'health-dot unconfigured' => '未配置',
            ] as $stateClass => $stateLabel) {
                if (str_contains($sectionPage['body'], $stateClass)) {
                    assert_true(
                        str_contains($sectionPage['body'], '>' . $stateLabel . '</span>'),
                        "The status center does not render the {$stateLabel} state consistently."
                    );
                }
            }
            assert_true(str_contains($sectionPage['body'], '最近一次失败'), 'The status center hides the latest restore-drill failure.');
            assert_true(
                str_contains($sectionPage['body'], '发布版本中心')
                    && str_contains($sectionPage['body'], '2.4.0')
                    && str_contains($sectionPage['body'], '2.3.1')
                    && str_contains($sectionPage['body'], '新增发布版本中心'),
                'The release center does not render release, rollback, and changelog metadata.'
            );
            assert_true(
                str_contains($sectionPage['body'], 'synthetic-monitor-panel')
                    && str_contains($sectionPage['body'], '>首页</strong>')
                    && str_contains($sectionPage['body'], '>登录页</strong>')
                    && str_contains($sectionPage['body'], '>API</strong>'),
                'The status center does not render endpoint-level synthetic results.'
            );
        }
    }
    $auditPdo = new PDO('sqlite:' . $databasePath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    assert_true((int)$auditPdo->query("SELECT COUNT(*) FROM audit_events WHERE action = 'create' AND outcome = 'success'")->fetchColumn() >= 1, 'Successful link creation was not audited.');

    $unflashedPassword = 'NeverFlash!234';
    assert_true($client->form('/shorten', [
        'csrf' => $csrf,
        'target_url' => 'invalid-protected-url',
        'custom_slug' => 'badprotect',
        'access_password' => $unflashedPassword,
        'expires_at' => '',
    ])['status'] === 303, 'Invalid protected-link input did not redirect.');
    $unflashedPage = $client->request('GET', '/');
    assert_true(!str_contains($unflashedPage['body'], $unflashedPassword), 'A rejected access password was flashed back to the administrator.');

    $protectedPassword = 'LinkAccess!234';
    $protectedRequestId = bin2hex(random_bytes(16));
    assert_true($client->form('/shorten', [
        'csrf' => $csrf,
        'create_request_id' => $protectedRequestId,
        'target_url' => 'https://example.com/protected-target',
        'title' => 'Protected link',
        'custom_slug' => 'protected01',
        'access_password' => $protectedPassword,
        'invalid_message' => 'This protected link is unavailable.',
        'fallback_url' => 'https://example.com/protected-fallback',
        'expires_at' => '',
    ])['status'] === 303, 'Protected link creation failed.');
    $protectedRow = $auditPdo->query("SELECT * FROM links WHERE slug = 'protected01'")->fetch(PDO::FETCH_ASSOC);
    assert_true(is_array($protectedRow), 'Protected link was not stored.');
    $protectedId = (int)$protectedRow['id'];
    $protectedHash = (string)$protectedRow['access_password_hash'];
    assert_true(
        $protectedHash !== '' && $protectedHash !== $protectedPassword && password_verify($protectedPassword, $protectedHash),
        'The protected link password was not hashed at rest with a verifiable password hash.'
    );
    assert_true(
        (string)$protectedRow['invalid_message'] === 'This protected link is unavailable.'
            && (string)$protectedRow['fallback_url'] === 'https://example.com/protected-fallback',
        'Protected-link invalid handling was not stored.'
    );
    $protectedAudit = (string)$auditPdo->query(
        "SELECT details_json FROM audit_events WHERE action = 'create' AND entity_id = '" . $protectedId . "' ORDER BY id DESC LIMIT 1"
    )->fetchColumn();
    assert_true(
        str_contains($protectedAudit, '"password_protected":true')
            && !str_contains($protectedAudit, $protectedPassword)
            && !str_contains($protectedAudit, $protectedHash)
            && !str_contains($protectedAudit, 'access_password_hash'),
        'Protected-link creation audit leaked password material or omitted its boolean indicator.'
    );
    $protectedAdminPage = $client->request('GET', '/');
    assert_true(
        str_contains($protectedAdminPage['body'], '已保护')
            && !str_contains($protectedAdminPage['body'], $protectedPassword)
            && !str_contains($protectedAdminPage['body'], $protectedHash),
        'The management UI omitted protection state or leaked password material.'
    );
    $protectedDetailPage = $client->request('GET', '/link?id=' . $protectedId);
    assert_true(
        str_contains($protectedDetailPage['body'], '密码保护')
            && str_contains($protectedDetailPage['body'], 'This protected link is unavailable.')
            && !str_contains($protectedDetailPage['body'], $protectedHash),
        'Protected-link detail metadata is incomplete or leaked its hash.'
    );

    assert_true($client->form('/edit', [
        'csrf' => $csrf,
        'id' => $protectedId,
        'updated_at' => (string)$protectedRow['updated_at'],
        'target_url' => (string)$protectedRow['target_url'],
        'title' => (string)$protectedRow['title'],
        'access_password' => '',
        'invalid_message' => (string)$protectedRow['invalid_message'],
        'fallback_url' => (string)$protectedRow['fallback_url'],
        'expires_at' => '',
        'starts_at' => '',
    ])['status'] === 303, 'Blank-password protected-link edit failed.');
    assert_true(
        (string)$auditPdo->query("SELECT access_password_hash FROM links WHERE id = {$protectedId}")->fetchColumn() === $protectedHash,
        'A blank edit password changed the existing access password.'
    );
    $replacementPassword = 'LinkAccessReplacement!234';
    $replacementVersion = (string)$auditPdo->query("SELECT updated_at FROM links WHERE id = {$protectedId}")->fetchColumn();
    assert_true($client->form('/edit', [
        'csrf' => $csrf,
        'id' => $protectedId,
        'updated_at' => $replacementVersion,
        'target_url' => (string)$protectedRow['target_url'],
        'title' => (string)$protectedRow['title'],
        'access_password' => $replacementPassword,
        'invalid_message' => (string)$protectedRow['invalid_message'],
        'fallback_url' => (string)$protectedRow['fallback_url'],
        'expires_at' => '',
        'starts_at' => '',
    ])['status'] === 303, 'Replacing a protected-link password failed.');
    $protectedHash = (string)$auditPdo->query("SELECT access_password_hash FROM links WHERE id = {$protectedId}")->fetchColumn();
    assert_true(
        $protectedHash !== '' && password_verify($replacementPassword, $protectedHash)
            && !password_verify($protectedPassword, $protectedHash),
        'Protected-link password replacement did not store the new hash.'
    );
    $protectedPassword = $replacementPassword;

    $unlockHeaders = ['X-Forwarded-For: 192.0.2.77'];
    $passwordPrompt = $client->request('GET', '/protected01', '', $unlockHeaders);
    assert_true(
        $passwordPrompt['status'] === 200
            && str_contains($passwordPrompt['body'], 'name="password"')
            && !str_contains($passwordPrompt['body'], 'protected-target')
            && header_value($passwordPrompt, 'Location') === null,
        'Protected-link GET did not show a target-free password prompt.'
    );
    assert_true((int)$auditPdo->query("SELECT clicks FROM links WHERE id = {$protectedId}")->fetchColumn() === 0, 'Password prompt consumed a click.');
    $protectedHead = $client->request('HEAD', '/protected01', '', $unlockHeaders);
    assert_true(
        $protectedHead['status'] === 200 && $protectedHead['body'] === ''
            && header_value($protectedHead, 'Location') === null,
        'Protected-link HEAD exposed a redirect target.'
    );

    $invalidUnlockCsrf = $client->form('/protected01/unlock', [
        'csrf' => 'invalid',
        'password' => $protectedPassword,
    ], $unlockHeaders);
    assert_true($invalidUnlockCsrf['status'] === 400, 'Protected-link unlock accepted invalid CSRF.');
    assert_true(
        (int)$auditPdo->query("SELECT COUNT(*) FROM link_password_attempts WHERE link_id = {$protectedId}")->fetchColumn() === 0,
        'Invalid unlock CSRF changed throttle state.'
    );
    $unlockCsrf = csrf_from($passwordPrompt['body']);
    for ($attemptNumber = 1; $attemptNumber <= 2; $attemptNumber++) {
        $wrongUnlock = $client->form('/protected01/unlock', [
            'csrf' => $unlockCsrf,
            'password' => 'WrongLinkPassword!234',
        ], $unlockHeaders);
        assert_true($wrongUnlock['status'] === 401, "Allowed wrong unlock attempt {$attemptNumber} did not return 401.");
        assert_true(
            str_contains($wrongUnlock['body'], 'id="password-error"')
                && str_contains($wrongUnlock['body'], 'aria-describedby="password-error password-help"'),
            'A password error was not programmatically associated with the password input.'
        );
        assert_true(!str_contains($wrongUnlock['body'], 'WrongLinkPassword!234'), 'Wrong access password was reflected.');
    }
    $lockedUnlock = $client->form('/protected01/unlock', [
        'csrf' => $unlockCsrf,
        'password' => 'WrongLinkPassword!234',
    ], $unlockHeaders);
    assert_true(
        $lockedUnlock['status'] === 429 && (int)header_value($lockedUnlock, 'Retry-After') >= 1,
        'Protected-link lockout did not return 429 with Retry-After.'
    );
    $storedClientIdentifier = (string)$auditPdo->query(
        "SELECT client_identifier_hash FROM link_password_attempts WHERE link_id = {$protectedId}"
    )->fetchColumn();
    assert_true(
        strlen($storedClientIdentifier) === 64 && $storedClientIdentifier !== '192.0.2.77',
        'The link password throttle stored a raw client identifier.'
    );
    $lockedCorrect = $client->form('/protected01/unlock', [
        'csrf' => $unlockCsrf,
        'password' => $protectedPassword,
    ], $unlockHeaders);
    assert_true($lockedCorrect['status'] === 429, 'A correct password bypassed an active link lockout.');
    $failedUnlockAudits = $auditPdo->query(
        "SELECT details_json FROM audit_events WHERE action = 'link_password_unlock' AND outcome = 'failure' AND entity_id = '" . $protectedId . "'"
    )->fetchAll(PDO::FETCH_COLUMN);
    assert_true(count($failedUnlockAudits) >= 4, 'Wrong and locked password attempts were not audited.');
    foreach ($failedUnlockAudits as $failedUnlockAudit) {
        assert_true(
            !str_contains((string)$failedUnlockAudit, $protectedPassword)
                && !str_contains((string)$failedUnlockAudit, 'WrongLinkPassword!234')
                && !str_contains((string)$failedUnlockAudit, $protectedHash),
            'A failed unlock audit retained submitted password material.'
        );
    }
    assert_true(!str_contains((string)file_get_contents($logPath), 'WrongLinkPassword!234'), 'Application logs retained a submitted link password.');
    $auditPdo->exec("DELETE FROM link_password_attempts WHERE link_id = {$protectedId}");

    $successfulUnlock = $client->form('/protected01/unlock', [
        'csrf' => $unlockCsrf,
        'password' => $protectedPassword,
    ], $unlockHeaders);
    assert_true(
        $successfulUnlock['status'] === 303 && header_value($successfulUnlock, 'Location') === '/protected01',
        'Successful password verification did not issue an internal one-use redirect.'
    );
    $unlockedAccess = $client->request('GET', '/protected01', '', $unlockHeaders);
    assert_true(
        $unlockedAccess['status'] === 302
            && header_value($unlockedAccess, 'Location') === 'https://example.com/protected-target',
        'The one-use password grant did not authorize its next GET.'
    );
    $reusedGrant = $client->request('GET', '/protected01', '', $unlockHeaders);
    assert_true(
        $reusedGrant['status'] === 200 && str_contains($reusedGrant['body'], 'name="password"')
            && header_value($reusedGrant, 'Location') === null,
        'A consumed password grant authorized a second GET.'
    );
    assert_true((int)$auditPdo->query("SELECT clicks FROM links WHERE id = {$protectedId}")->fetchColumn() === 1, 'Password prompts changed redirect accounting.');
    assert_true(str_contains($client->request('GET', '/')['body'], 'action="/logout"'), 'Public password verification destroyed the administrator session.');

    $failClosedPassword = 'FailClosed!234';
    assert_true($client->form('/shorten', [
        'csrf' => $csrf,
        'target_url' => 'https://example.com/fail-closed',
        'custom_slug' => 'failclosed1',
        'access_password' => $failClosedPassword,
        'expires_at' => '',
    ])['status'] === 303, 'Fail-closed protected-link fixture creation failed.');
    $failClosedId = (int)$auditPdo->query("SELECT id FROM links WHERE slug = 'failclosed1'")->fetchColumn();
    $failClosedClient = new HttpClient($baseUrl);
    $failClosedPrompt = $failClosedClient->request('GET', '/failclosed1');
    $auditPdo->exec(<<<SQL
        CREATE TRIGGER fail_link_password_throttle
        BEFORE INSERT ON link_password_attempts
        WHEN NEW.link_id = {$failClosedId}
        BEGIN
            SELECT RAISE(ABORT, 'forced throttle failure');
        END
    SQL);
    $failClosedUnlock = $failClosedClient->form('/failclosed1/unlock', [
        'csrf' => csrf_from($failClosedPrompt['body']),
        'password' => 'wrong-fail-closed',
    ]);
    $auditPdo->exec('DROP TRIGGER fail_link_password_throttle');
    assert_true(
        $failClosedUnlock['status'] === 503 && header_value($failClosedUnlock, 'Retry-After') === '1',
        'A throttle-store failure did not fail link password verification closed.'
    );
    assert_true($failClosedClient->request('GET', '/failclosed1')['status'] === 200, 'Throttle failure accidentally granted access.');
    assert_true(!str_contains((string)file_get_contents($logPath), 'wrong-fail-closed'), 'Throttle failure logged a submitted password.');

    assert_true($client->form('/shorten', [
        'csrf' => $csrf,
        'target_url' => 'https://example.com/disabled-custom',
        'custom_slug' => 'disabledcustom',
        'invalid_message' => '<b>Disabled & safe</b>',
        'expires_at' => '',
    ])['status'] === 303, 'Disabled custom-message fixture creation failed.');
    $disabledCustom = $auditPdo->query("SELECT id, updated_at FROM links WHERE slug = 'disabledcustom'")->fetch(PDO::FETCH_ASSOC);
    assert_true($client->form('/toggle', [
        'csrf' => $csrf,
        'id' => (int)$disabledCustom['id'],
        'desired_state' => '0',
        'updated_at' => (string)$disabledCustom['updated_at'],
    ])['status'] === 303, 'Disabled custom-message fixture could not be disabled.');
    $disabledCustomResponse = $client->request('GET', '/disabledcustom');
    assert_true(
        $disabledCustomResponse['status'] === 404
            && str_contains($disabledCustomResponse['body'], '&lt;b&gt;Disabled &amp; safe&lt;/b&gt;')
            && !str_contains($disabledCustomResponse['body'], '<b>Disabled & safe</b>'),
        'Inactive-link custom messaging was not escaped.'
    );

    assert_true($client->form('/shorten', [
        'csrf' => $csrf,
        'target_url' => 'https://example.com/expired-target',
        'custom_slug' => 'expiredfallback',
        'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() - 60),
        'fallback_url' => 'https://example.com/expired-fallback',
    ])['status'] === 303, 'Expired fallback fixture creation failed.');
    $expiredFallback = $client->request('GET', '/expiredfallback');
    assert_true(
        $expiredFallback['status'] === 302
            && header_value($expiredFallback, 'Location') === 'https://example.com/expired-fallback',
        'Expired-link fallback did not redirect to its validated URL.'
    );

    assert_true($client->form('/shorten', [
        'csrf' => $csrf,
        'target_url' => 'https://example.com/exhausted-target',
        'custom_slug' => 'exhaustcustom',
        'max_clicks' => '1',
        'invalid_message' => 'No visits remain.',
        'expires_at' => '',
    ])['status'] === 303, 'Exhausted custom-message fixture creation failed.');
    assert_true($client->request('GET', '/exhaustcustom')['status'] === 302, 'Exhausted fixture first access failed.');
    $exhaustedCustom = $client->request('GET', '/exhaustcustom');
    assert_true(
        $exhaustedCustom['status'] === 404 && str_contains($exhaustedCustom['body'], 'No visits remain.'),
        'Exhausted-link custom messaging was not rendered.'
    );

    assert_true($client->form('/shorten', [
        'csrf' => $csrf,
        'target_url' => 'https://example.com/future-custom-target',
        'custom_slug' => 'futurecustom',
        'starts_at' => '2030-01-01T00:00:00Z',
        'invalid_message' => 'SCHEDULED CUSTOM MESSAGE',
        'fallback_url' => 'https://example.com/future-fallback',
        'expires_at' => '',
    ])['status'] === 303, 'Scheduled generic-unavailable fixture creation failed.');
    $scheduledCustom = $client->request('GET', '/futurecustom');
    assert_true(
        $scheduledCustom['status'] === 404 && header_value($scheduledCustom, 'Location') === null
            && !str_contains($scheduledCustom['body'], 'SCHEDULED CUSTOM MESSAGE'),
        'A scheduled link exposed custom handling before activation.'
    );

    assert_true($client->form('/shorten', [
        'csrf' => $csrf,
        'target_url' => 'https://example.com/deleted-custom-target',
        'custom_slug' => 'deletedcustom',
        'invalid_message' => 'DELETED CUSTOM MESSAGE',
        'fallback_url' => 'https://example.com/deleted-fallback',
        'expires_at' => '',
    ])['status'] === 303, 'Deleted generic-unavailable fixture creation failed.');
    $deletedCustomId = (int)$auditPdo->query("SELECT id FROM links WHERE slug = 'deletedcustom'")->fetchColumn();
    assert_true($client->form('/delete', ['csrf' => $csrf, 'id' => $deletedCustomId])['status'] === 303, 'Deleted custom fixture could not be deleted.');
    $deletedCustom = $client->request('GET', '/deletedcustom');
    assert_true(
        $deletedCustom['status'] === 404 && header_value($deletedCustom, 'Location') === null
            && !str_contains($deletedCustom['body'], 'DELETED CUSTOM MESSAGE'),
        'A deleted link exposed custom invalid handling.'
    );

    $protectedConfirmPassword = 'ConfirmAccess!234';
    assert_true($client->form('/shorten', [
        'csrf' => $csrf,
        'target_url' => 'https://example.com/protected-confirm-target',
        'custom_slug' => 'protectconfirm',
        'access_password' => $protectedConfirmPassword,
        'is_one_time' => '1',
        'one_time_mode' => 'confirm',
        'expires_at' => '',
    ])['status'] === 303, 'Protected confirmation-link creation failed.');
    $confirmClient = new HttpClient($baseUrl);
    $protectedConfirmPrompt = $confirmClient->request('GET', '/protectconfirm');
    assert_true($protectedConfirmPrompt['status'] === 200 && str_contains($protectedConfirmPrompt['body'], 'name="password"'), 'Protected confirmation link skipped its password prompt.');
    $protectedConfirmUnlock = $confirmClient->form('/protectconfirm/unlock', [
        'csrf' => csrf_from($protectedConfirmPrompt['body']),
        'password' => $protectedConfirmPassword,
    ]);
    assert_true($protectedConfirmUnlock['status'] === 303, 'Protected confirmation password was rejected.');
    $protectedConfirmPage = $confirmClient->request('GET', '/protectconfirm');
    assert_true(
        $protectedConfirmPage['status'] === 200 && str_contains($protectedConfirmPage['body'], '确认并继续'),
        'Password unlock did not continue into one-time confirmation mode.'
    );
    $protectedConfirmReuse = $confirmClient->request('GET', '/protectconfirm');
    assert_true(
        $protectedConfirmReuse['status'] === 200 && str_contains($protectedConfirmReuse['body'], 'name="password"'),
        'A confirmation-mode pending grant could be reused by GET.'
    );
    $protectedConfirmedAccess = $confirmClient->form('/protectconfirm/confirm', [
        'csrf' => csrf_from($protectedConfirmPage['body']),
    ]);
    assert_true(
        $protectedConfirmedAccess['status'] === 303
            && header_value($protectedConfirmedAccess, 'Location') === 'https://example.com/protected-confirm-target',
        'Protected one-time confirmation required a second password or lost its confirmation permit.'
    );

    $removePassword = 'RemoveAccess!234';
    assert_true($client->form('/shorten', [
        'csrf' => $csrf,
        'target_url' => 'https://example.com/remove-password',
        'custom_slug' => 'removeprotect',
        'access_password' => $removePassword,
        'expires_at' => '',
    ])['status'] === 303, 'Password-removal fixture creation failed.');
    $removeRow = $auditPdo->query("SELECT * FROM links WHERE slug = 'removeprotect'")->fetch(PDO::FETCH_ASSOC);
    assert_true($client->form('/edit', [
        'csrf' => $csrf,
        'id' => (int)$removeRow['id'],
        'updated_at' => (string)$removeRow['updated_at'],
        'target_url' => (string)$removeRow['target_url'],
        'title' => (string)$removeRow['title'],
        'remove_access_password' => '1',
        'expires_at' => '',
        'starts_at' => '',
    ])['status'] === 303, 'Explicit access-password removal failed.');
    $removedPasswordHash = $auditPdo
        ->query("SELECT access_password_hash FROM links WHERE id = " . (int)$removeRow['id'])
        ->fetchColumn();
    $removedPasswordResponse = $client->request('GET', '/removeprotect');
    assert_true(
        $removedPasswordHash === null && $removedPasswordResponse['status'] === 302,
        sprintf(
            'Explicit password removal did not make the link directly accessible (hash type: %s, status: %d, location: %s).',
            get_debug_type($removedPasswordHash),
            $removedPasswordResponse['status'],
            header_value($removedPasswordResponse, 'Location') ?? 'none'
        )
    );

    foreach ([$databasePath, $databasePath . '-wal', $logPath, $serverOutput] as $sensitivePath) {
        if (is_file($sensitivePath)) {
            $sensitiveContents = (string)file_get_contents($sensitivePath);
            assert_true(
                !str_contains($sensitiveContents, $protectedPassword)
                    && !str_contains($sensitiveContents, 'WrongLinkPassword!234')
                    && !str_contains($sensitiveContents, $failClosedPassword),
                'A persisted database or log artifact contains a submitted access password.'
            );
        }
    }

    $managed = $client->form('/shorten', [
        'csrf' => $csrf,
        'target_url' => 'https://example.com/managed',
        'title' => 'Managed link',
        'custom_slug' => 'managed01',
        'tags' => 'mobile, private',
        'is_favorite' => '1',
        'max_clicks' => '2',
        'expires_at' => '',
        'starts_at' => '',
    ]);
    assert_true($managed['status'] === 303, 'Creating a managed link must redirect.');
    $managedPdo = new PDO('sqlite:' . $databasePath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $managedId = (int)$managedPdo->query("SELECT id FROM links WHERE slug = 'managed01'")->fetchColumn();
    assert_true($managedId > 0, 'Managed link was not stored.');
    assert_true((int)$managedPdo->query("SELECT is_favorite FROM links WHERE id = {$managedId}")->fetchColumn() === 1, 'Favorite state was not stored.');
    assert_true((int)$managedPdo->query("SELECT COUNT(*) FROM link_tags WHERE link_id = {$managedId}")->fetchColumn() === 2, 'Tags were not stored.');
    assert_true(str_contains($client->request('GET', '/?tag=mobile&favorite=1')['body'], 'managed01'), 'Tag and favorite filters missed a matching link.');
    $detailPage = $client->request('GET', '/link?id=' . $managedId);
    assert_true($detailPage['status'] === 200, 'Link detail page must return 200.');
    assert_true(str_contains($detailPage['body'], 'data-qr-value=') && str_contains($detailPage['body'], '30 天'), 'Detail page is missing QR or trend controls.');
    assert_true(str_contains($detailPage['body'], '/edit?id=' . $managedId), 'Detail editing does not use the independent link ID route.');
    $standaloneEditPage = $client->request('GET', '/edit?id=' . $managedId);
    assert_true(
        $standaloneEditPage['status'] === 200
            && str_contains($standaloneEditPage['body'], '编辑短链接：managed01')
            && str_contains($standaloneEditPage['body'], 'https://example.com/managed')
            && str_contains($standaloneEditPage['body'], 'name="id" value="' . $managedId . '"')
            && str_contains($standaloneEditPage['body'], 'aria-label="返回链接列表"')
            && !str_contains($standaloneEditPage['body'], 'name="return_to_detail"'),
        'The independent link edit page did not load the requested link by ID.'
    );
    $detailEditPage = $client->request('GET', '/edit?id=' . $managedId . '&return_to_detail=1');
    assert_true(
        $detailEditPage['status'] === 200
            && str_contains($detailEditPage['body'], 'aria-label="返回链接详情"')
            && str_contains($detailEditPage['body'], 'name="return_to_detail" value="1"'),
        'Editing from link details did not preserve the detail return target.'
    );
    assert_true($client->request('GET', '/edit/' . $managedId)['status'] === 404, 'A noncanonical path was accepted as the direct edit route.');
    assert_true($client->request('GET', '/edit?id=0')['status'] === 404, 'The direct edit route accepted an invalid link ID.');

    $editReturnClient = new HttpClient($baseUrl);
    $requestedEditPath = '/edit?id=' . $managedId . '&return_q=managed&return_page=2';
    $editLoginRedirect = $editReturnClient->request('GET', $requestedEditPath);
    assert_true(
        $editLoginRedirect['status'] === 303 && header_value($editLoginRedirect, 'Location') === '/login',
        'Unauthenticated direct edit did not redirect to login.'
    );
    $editLoginPage = $editReturnClient->request('GET', '/login');
    $editLogin = $editReturnClient->form('/login', [
        'csrf' => csrf_from($editLoginPage['body']),
        'password' => $password,
    ]);
    assert_true(
        $editLogin['status'] === 303 && header_value($editLogin, 'Location') === $requestedEditPath,
        'Login did not return safely to the exact requested direct edit route.'
    );
    $returnedEditPage = $editReturnClient->request('GET', $requestedEditPath);
    assert_true(
        $returnedEditPage['status'] === 200
            && str_contains($returnedEditPage['body'], '编辑短链接：managed01'),
        'The post-login direct edit return did not load the requested link.'
    );

    $externalReturnClient = new HttpClient($baseUrl);
    $externalReturnLoginPage = $externalReturnClient->request('GET', '/login?return=https%3A%2F%2Fevil.example%2Fsteal');
    $externalReturnLogin = $externalReturnClient->form('/login', [
        'csrf' => csrf_from($externalReturnLoginPage['body']),
        'password' => $password,
    ]);
    assert_true(
        $externalReturnLogin['status'] === 303 && header_value($externalReturnLogin, 'Location') === '/',
        'An external login return target influenced the post-login redirect.'
    );

    $campaignCreate = $client->form('/shorten', [
        'csrf' => $csrf,
        'target_url' => 'https://example.com/campaign?existing=1#details',
        'title' => 'Campaign link',
        'custom_slug' => 'campaign01',
        'campaign_name' => 'summer_launch',
        'campaign_source' => 'newsletter',
        'campaign_medium' => 'email',
        'campaign_content' => 'hero_button',
        'expires_at' => '',
    ]);
    assert_true($campaignCreate['status'] === 303, 'Creating a campaign link must redirect.');
    $campaignRow = $managedPdo->query("SELECT * FROM links WHERE slug = 'campaign01'")->fetch();
    assert_true(is_array($campaignRow), 'Campaign link was not stored.');
    $campaignQuery = [];
    parse_str((string)parse_url((string)$campaignRow['target_url'], PHP_URL_QUERY), $campaignQuery);
    assert_true(
        ($campaignQuery['existing'] ?? null) === '1'
            && ($campaignQuery['utm_campaign'] ?? null) === 'summer_launch'
            && ($campaignQuery['utm_source'] ?? null) === 'newsletter'
            && ($campaignQuery['utm_medium'] ?? null) === 'email'
            && ($campaignQuery['utm_content'] ?? null) === 'hero_button'
            && parse_url((string)$campaignRow['target_url'], PHP_URL_FRAGMENT) === 'details',
        'Campaign UTM generation did not preserve and augment the target URL.'
    );
    $campaignPreserve = $client->form('/shorten', [
        'csrf' => $csrf,
        'target_url' => 'https://example.com/items?id=1&id=2&utm_source=old#details',
        'custom_slug' => 'campaign02',
        'campaign_name' => 'new',
        'expires_at' => '',
    ]);
    assert_true($campaignPreserve['status'] === 303, 'Creating a campaign over an existing query failed.');
    assert_true(
        (string)$managedPdo->query("SELECT target_url FROM links WHERE slug = 'campaign02'")->fetchColumn()
            === 'https://example.com/items?id=1&id=2&utm_source=old&utm_campaign=new#details',
        'Campaign UTM generation changed duplicate parameters or removed an unmanaged UTM value.'
    );
