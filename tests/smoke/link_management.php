<?php

declare(strict_types=1);

    assert_true($client->form('/shorten', [
        'csrf' => $csrf,
        'target_url' => 'https://example.com/one-time',
        'custom_slug' => 'once001',
        'is_one_time' => '1',
        'expires_at' => '',
    ])['status'] === 303, 'One-time link creation failed.');
    assert_true($client->request('GET', '/once001')['status'] === 302, 'One-time link first access failed.');
    assert_true($client->request('GET', '/once001')['status'] === 404, 'One-time link allowed a second access.');

    assert_true($client->form('/shorten', [
        'csrf' => $csrf,
        'target_url' => 'https://example.com/one-time-confirmed',
        'custom_slug' => 'onceconfirm',
        'is_one_time' => '1',
        'one_time_mode' => 'confirm',
        'expires_at' => '',
    ])['status'] === 303, 'Confirmed one-time link creation failed.');
    $confirmPage = $client->request('GET', '/onceconfirm');
    assert_true(
        $confirmPage['status'] === 200
            && str_contains($confirmPage['body'], '确认并继续')
            && str_contains($confirmPage['body'], '取消访问')
            && str_contains($confirmPage['body'], 'name="cancel" value="1"')
            && str_contains($confirmPage['body'], '<dt>协议</dt><dd>HTTPS</dd>')
            && str_contains($confirmPage['body'], '<dt>域名</dt><dd>example.com</dd>')
            && str_contains($confirmPage['body'], '<dt>端口</dt><dd>443（默认）</dd>')
            && str_contains($confirmPage['body'], '<dt>完整目标地址</dt><dd><code>https://example.com/one-time-confirmed</code>'),
        'Confirmed one-time link did not render a complete confirmation page.'
    );
    $cancelledConfirmation = $client->form('/onceconfirm/confirm', [
        'csrf' => csrf_from($confirmPage['body']),
        'cancel' => '1',
    ]);
    assert_true(
        $cancelledConfirmation['status'] === 303
            && header_value($cancelledConfirmation, 'Location') === '/',
        'Cancelling a confirmation page did not return to the public home path.'
    );
    $confirmPage = $client->request('GET', '/onceconfirm');
    assert_true((int)$managedPdo->query("SELECT clicks FROM links WHERE slug = 'onceconfirm'")->fetchColumn() === 0, 'Confirmation-page GET consumed the one-time link.');
    $confirmedHead = $client->request('HEAD', '/onceconfirm');
    assert_true($confirmedHead['status'] === 405 && header_value($confirmedHead, 'Location') === null, 'Confirmed one-time link HEAD exposed its target.');
    assert_true((int)$managedPdo->query("SELECT clicks FROM links WHERE slug = 'onceconfirm'")->fetchColumn() === 0, 'Rejected confirmation-page HEAD consumed the one-time link.');
    $confirmedAccess = $client->form('/onceconfirm/confirm', ['csrf' => csrf_from($confirmPage['body'])]);
    assert_true($confirmedAccess['status'] === 303, 'Confirmed one-time link POST did not redirect.');
    assert_true(header_value($confirmedAccess, 'Location') === 'https://example.com/one-time-confirmed', 'Confirmed one-time link redirected to the wrong target.');
    assert_true((int)$managedPdo->query("SELECT clicks FROM links WHERE slug = 'onceconfirm'")->fetchColumn() === 1, 'Confirmed access did not consume the one-time link.');
    assert_true($client->request('GET', '/onceconfirm')['status'] === 404, 'Confirmed one-time link allowed a second access.');

    assert_true($client->form('/shorten', [
        'csrf' => $csrf,
        'target_url' => 'https://example.com/one-time-head',
        'custom_slug' => 'oncehead',
        'is_one_time' => '1',
        'expires_at' => '',
    ])['status'] === 303, 'HEAD one-time link creation failed.');
    $oneTimeHead = $client->request('HEAD', '/oncehead');
    assert_true($oneTimeHead['status'] === 405 && header_value($oneTimeHead, 'Location') === null, 'One-time link HEAD exposed its target.');
    assert_true((int)$managedPdo->query("SELECT clicks FROM links WHERE slug = 'oncehead'")->fetchColumn() === 0, 'Rejected HEAD consumed a one-time link.');
    assert_true($client->request('GET', '/oncehead')['status'] === 302, 'One-time link was unavailable after rejected HEAD.');
    assert_true($client->request('GET', '/oncehead')['status'] === 404, 'One-time link allowed access after it was consumed.');

    assert_true($client->form('/shorten', [
        'csrf' => $csrf,
        'target_url' => 'https://example.com/scheduled',
        'custom_slug' => 'future01',
        'starts_at' => '2030-01-01T00:00:00Z',
        'expires_at' => '',
    ])['status'] === 303, 'Scheduled link creation failed.');
    assert_true($client->request('GET', '/future01')['status'] === 404, 'Scheduled link activated too early.');
    assert_true(str_contains($client->request('GET', '/?status=scheduled')['body'], 'future01'), 'Scheduled status filter missed the link.');

    $apiId = (int)$managedPdo->query("SELECT id FROM links WHERE slug = 'api001'")->fetchColumn();
    $unpreviewedBulk = $client->form('/bulk', [
        'csrf' => $csrf,
        'selected' => [$apiId],
        'bulk_action' => 'disable',
    ]);
    assert_true(
        $unpreviewedBulk['status'] === 303
            && (int)$managedPdo->query("SELECT is_active FROM links WHERE id = {$apiId}")->fetchColumn() === 1,
        'Bulk apply without a preview operation changed the link.'
    );

    $bulkDisablePreviewResponse = $client->form('/bulk/preview', [
        'csrf' => $csrf,
        'selected' => [$apiId],
        'bulk_action' => 'disable',
    ]);
    $bulkDisablePreview = json_decode($bulkDisablePreviewResponse['body'], true, 32, JSON_THROW_ON_ERROR);
    $bulkDisableOperationId = (string)($bulkDisablePreview['operation_id'] ?? '');
    assert_true(
        $bulkDisablePreviewResponse['status'] === 200
            && preg_match('/^[a-f0-9]{32}$/D', $bulkDisableOperationId) === 1
            && ($bulkDisablePreview['selected'] ?? null) === 1
            && ($bulkDisablePreview['would_change'] ?? null) === 1
            && ($bulkDisablePreview['unchanged'] ?? null) === 0
            && ($bulkDisablePreview['items'][0]['state'] ?? null) === 'change',
        'Bulk disable preview did not describe the exact pending change.'
    );
    $bulkDisable = $client->form('/bulk', [
        'csrf' => $csrf,
        'operation_id' => $bulkDisableOperationId,
    ]);
    assert_true(
        $bulkDisable['status'] === 303
            && (int)$managedPdo->query("SELECT is_active FROM links WHERE id = {$apiId}")->fetchColumn() === 0
            && (string)$managedPdo->query("SELECT status FROM bulk_operations WHERE id = " . $managedPdo->quote($bulkDisableOperationId))->fetchColumn() === 'applied',
        'Applying the previewed bulk disable did not atomically update the link and operation.'
    );
    $bulkUndoFlashPage = $client->request('GET', '/');
    assert_true(
        str_contains($bulkUndoFlashPage['body'], 'name="operation_id" value="' . $bulkDisableOperationId . '"')
            && str_contains($bulkUndoFlashPage['body'], 'action="/bulk/undo"'),
        'Applied bulk operation did not expose its immediate undo action.'
    );
    $persistentUndoPage = $client->request('GET', '/');
    assert_true(
        str_contains($persistentUndoPage['body'], 'class="bulk-undo-center"')
            && str_contains($persistentUndoPage['body'], 'name="operation_id" value="' . $bulkDisableOperationId . '"'),
        'Applied bulk operation was not available for persistent undo after its flash was consumed.'
    );
    $bulkUndo = $client->form('/bulk/undo', [
        'csrf' => $csrf,
        'operation_id' => $bulkDisableOperationId,
    ]);
    assert_true(
        $bulkUndo['status'] === 303
            && (int)$managedPdo->query("SELECT is_active FROM links WHERE id = {$apiId}")->fetchColumn() === 1
            && (string)$managedPdo->query("SELECT status FROM bulk_operations WHERE id = " . $managedPdo->quote($bulkDisableOperationId))->fetchColumn() === 'undone',
        'Persistent bulk undo did not restore the previewed state.'
    );

    $futureId = (int)$managedPdo->query("SELECT id FROM links WHERE slug = 'future01'")->fetchColumn();
    $futureVersion = (string)$managedPdo->query("SELECT updated_at FROM links WHERE id = {$futureId}")->fetchColumn();
    assert_true($client->form('/toggle', [
        'csrf' => $csrf,
        'id' => $futureId,
        'desired_state' => '0',
        'updated_at' => $futureVersion,
    ])['status'] === 303, 'Cannot prepare the bulk conflict fixture.');
    assert_true((int)$managedPdo->query("SELECT is_active FROM links WHERE id = {$futureId}")->fetchColumn() === 0, 'Bulk conflict fixture was not disabled.');

    $bulkConflictPreviewResponse = $client->form('/bulk/preview', [
        'csrf' => $csrf,
        'selected' => [$apiId, $futureId],
        'bulk_action' => 'enable',
    ]);
    $bulkConflictPreview = json_decode($bulkConflictPreviewResponse['body'], true, 32, JSON_THROW_ON_ERROR);
    $bulkConflictOperationId = (string)($bulkConflictPreview['operation_id'] ?? '');
    assert_true(
        $bulkConflictPreviewResponse['status'] === 200
            && preg_match('/^[a-f0-9]{32}$/D', $bulkConflictOperationId) === 1
            && ($bulkConflictPreview['would_change'] ?? null) === 1
            && ($bulkConflictPreview['unchanged'] ?? null) === 1
            && array_column($bulkConflictPreview['items'] ?? [], 'state') === ['unchanged', 'change'],
        'Mixed bulk preview did not retain its unchanged item snapshot.'
    );
    assert_true($client->form('/favorite', [
        'csrf' => $csrf,
        'id' => $apiId,
        'desired_state' => '1',
    ])['status'] === 303, 'Cannot stale the unchanged bulk-preview item.');
    $bulkConflictApply = $client->form('/bulk', [
        'csrf' => $csrf,
        'operation_id' => $bulkConflictOperationId,
    ]);
    $bulkConflictResult = json_decode((string)$managedPdo->query(
        "SELECT result_json FROM bulk_operations WHERE id = " . $managedPdo->quote($bulkConflictOperationId)
    )->fetchColumn(), true, 32, JSON_THROW_ON_ERROR);
    assert_true(
        $bulkConflictApply['status'] === 303
            && (string)$managedPdo->query("SELECT status FROM bulk_operations WHERE id = " . $managedPdo->quote($bulkConflictOperationId))->fetchColumn() === 'conflicted'
            && ($bulkConflictResult['reason'] ?? null) === 'selection_changed'
            && ($bulkConflictResult['link_id'] ?? null) === $apiId
            && (int)$managedPdo->query("SELECT is_active FROM links WHERE id = {$futureId}")->fetchColumn() === 0,
        'A stale unchanged preview item did not conflict the entire bulk apply.'
    );
    assert_true($client->form('/favorite', [
        'csrf' => $csrf,
        'id' => $apiId,
        'desired_state' => '0',
    ])['status'] === 303, 'Cannot restore the bulk conflict favorite fixture.');
    $futureVersion = (string)$managedPdo->query("SELECT updated_at FROM links WHERE id = {$futureId}")->fetchColumn();
    assert_true($client->form('/toggle', [
        'csrf' => $csrf,
        'id' => $futureId,
        'desired_state' => '1',
        'updated_at' => $futureVersion,
    ])['status'] === 303, 'Cannot restore the bulk conflict status fixture.');
    assert_true((int)$managedPdo->query("SELECT is_active FROM links WHERE id = {$futureId}")->fetchColumn() === 1, 'Bulk conflict fixture was not restored.');

    $idempotencyKey = bin2hex(random_bytes(16));
    $idempotentFields = [
        'csrf' => $csrf,
        'create_request_id' => $idempotencyKey,
        'target_url' => 'https://example.com/idempotent',
        'title' => 'Idempotent link',
        'custom_slug' => 'idem01',
        'expires_at' => '',
    ];
    assert_true($client->form('/shorten', $idempotentFields)['status'] === 303, 'Initial idempotent create failed.');
    assert_true($client->form('/shorten', $idempotentFields)['status'] === 303, 'Idempotent create replay failed.');
    $idempotencyPdo = new PDO('sqlite:' . $databasePath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    assert_true((int)$idempotencyPdo->query("SELECT COUNT(*) FROM links WHERE slug = 'idem01'")->fetchColumn() === 1, 'A create replay inserted a second link.');
    assert_true((int)$idempotencyPdo->query("SELECT COUNT(*) FROM create_requests WHERE request_id = '{$idempotencyKey}'")->fetchColumn() === 1, 'The create request identifier was not stored.');
    $conflictingFields = $idempotentFields;
    $conflictingFields['target_url'] = 'https://example.com/idempotent-conflict';
    $conflictingFields['custom_slug'] = 'idem02';
    assert_true($client->form('/shorten', $conflictingFields)['status'] === 303, 'An idempotency conflict must redirect safely.');
    assert_true((int)$idempotencyPdo->query("SELECT COUNT(*) FROM links WHERE slug = 'idem02'")->fetchColumn() === 0, 'A reused create request identifier accepted different input.');

    $expired = $client->form('/shorten', [
        'csrf' => $csrf,
        'target_url' => 'https://example.com/expired',
        'title' => 'Expired link',
        'custom_slug' => 'expired01',
        'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() - 60),
    ]);
    assert_true($expired['status'] === 303, 'Creating an expired link must complete.');
    $expiredRedirect = $client->request('GET', '/expired01');
    assert_true($expiredRedirect['status'] === 404, 'An expired link must not redirect.');
    assert_true(str_contains($expiredRedirect['body'], '请求编号'), 'Errors must use the unified error page.');

    $localExpiration = $client->form('/shorten', [
        'csrf' => $csrf,
        'target_url' => 'https://example.com/local-time',
        'title' => 'Local time link',
        'custom_slug' => 'local01',
        'expires_at' => '2030-01-01T00:00',
        'expires_at_offset' => '-480',
    ]);
    assert_true($localExpiration['status'] === 303, 'Creating a link with local expiration must redirect.');

    $pdo = new PDO('sqlite:' . $databasePath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $storedExpiration = $pdo->query("SELECT expires_at FROM links WHERE slug = 'local01'")->fetchColumn();
    assert_true($storedExpiration === '2029-12-31T16:00:00Z', 'Local expiration was not normalized to UTC.');
    $activeId = (int)$pdo->query("SELECT id FROM links WHERE slug = 'active01'")->fetchColumn();
    assert_true($activeId > 0, 'The active link was not stored.');
    $expiredId = (int)$pdo->query("SELECT id FROM links WHERE slug = 'expired01'")->fetchColumn();
    $expiredAdminPage = $client->request('GET', '/');
    assert_true(str_contains($expiredAdminPage['body'], '清除过期时间'), 'Expired links still expose the toggle action.');
    $clearExpiration = $client->form('/clear-expiration', ['csrf' => $csrf, 'id' => $expiredId]);
    assert_true($clearExpiration['status'] === 303, 'Clearing an expired timestamp must redirect.');
    assert_true($pdo->query("SELECT expires_at FROM links WHERE id = {$expiredId}")->fetchColumn() === null, 'Expired timestamp was not cleared.');
    $clearAudit = json_decode((string)$pdo->query("SELECT details_json FROM audit_events WHERE action = 'clear_expiration' ORDER BY id DESC LIMIT 1")->fetchColumn(), true, 32, JSON_THROW_ON_ERROR);
    assert_true(
        !empty($clearAudit['before']['expires_at'])
            && array_key_exists('expires_at', $clearAudit['after'])
            && $clearAudit['after']['expires_at'] === null,
        'Clear-expiration audit is missing before/after fields.'
    );
    assert_true($client->request('GET', '/expired01')['status'] === 302, 'A renewed active link must redirect.');

    $invalidEdit = $client->form('/edit', [
        'csrf' => $csrf,
        'id' => $activeId,
        'return_to_detail' => '1',
        'target_url' => 'invalid-edit-url',
        'title' => 'Preserved edit title',
        'expires_at' => '',
    ]);
    assert_true(
        $invalidEdit['status'] === 303
            && header_value($invalidEdit, 'Location') === '/edit?id=' . $activeId . '&return_to_detail=1',
        'Invalid edit input must redirect to the exact direct edit route.'
    );
    $invalidEditPage = $client->request('GET', '/edit?id=' . $activeId . '&return_to_detail=1');
    assert_true(str_contains($invalidEditPage['body'], 'value="invalid-edit-url"'), 'Invalid edit URL was not preserved.');
    assert_true(str_contains($invalidEditPage['body'], 'value="Preserved edit title"'), 'Edit title was not preserved.');
    assert_true(str_contains($invalidEditPage['body'], 'id="edit-target-url-error"'), 'Direct edit field error is missing.');

    $edit = $client->form('/edit', [
        'csrf' => $csrf,
        'id' => $activeId,
        'return_to_detail' => '1',
        'slug' => 'changed01',
        'target_url' => 'https://example.com/edited',
        'title' => 'Edited link',
        'expires_at' => '',
        'updated_at' => (string)$pdo->query("SELECT updated_at FROM links WHERE id = {$activeId}")->fetchColumn(),
    ]);
    assert_true(
        $edit['status'] === 303 && header_value($edit, 'Location') === '/link?id=' . $activeId,
        'Editing a link must redirect to its exact detail route.'
    );
    assert_true(
        $pdo->query("SELECT slug FROM links WHERE id = {$activeId}")->fetchColumn() === 'active01',
        'Editing a link must not change its short code.'
    );
    assert_true($client->request('GET', '/active01')['status'] === 302, 'The original short URL must survive editing.');
    assert_true($client->request('GET', '/changed01')['status'] === 404, 'A forged edited short code must not be created.');

    $inlineEdit = $client->form('/edit', [
        'csrf' => $csrf,
        'id' => $activeId,
        'target_url' => 'https://example.com/edited-inline',
        'title' => 'Edited link',
        'expires_at' => '',
        'updated_at' => (string)$pdo->query("SELECT updated_at FROM links WHERE id = {$activeId}")->fetchColumn(),
        'return_q' => 'Edited',
    ]);
    assert_true(
        $inlineEdit['status'] === 303
            && str_starts_with((string)header_value($inlineEdit, 'Location'), '/?q=Edited'),
        'An inline list edit was redirected to the standalone detail flow.'
    );

    $standaloneListEdit = $client->form('/edit', [
        'csrf' => $csrf,
        'id' => $activeId,
        'standalone_edit' => '1',
        'target_url' => 'https://example.com/edited-from-list',
        'title' => 'Edited link',
        'expires_at' => '',
        'updated_at' => (string)$pdo->query("SELECT updated_at FROM links WHERE id = {$activeId}")->fetchColumn(),
        'return_q' => 'Edited',
        'return_page' => '2',
        'return_scroll' => '123',
    ]);
    assert_true(
        $standaloneListEdit['status'] === 303
            && header_value($standaloneListEdit, 'Location') === '/?q=Edited&page=2&scroll=123',
        'A standalone edit opened from the link list did not return to the original list state.'
    );

    $editVersion = (string)$pdo->query("SELECT updated_at FROM links WHERE id = {$activeId}")->fetchColumn();
    $staleEdit = $client->form('/edit', [
        'csrf' => $csrf,
        'id' => $activeId,
        'return_to_detail' => '1',
        'target_url' => 'https://example.com/stale-edit',
        'title' => 'Edited link',
        'expires_at' => '',
        'updated_at' => $editVersion,
    ]);
    assert_true($staleEdit['status'] === 303, 'A stale edit must redirect.');
    assert_true(
        $pdo->query("SELECT target_url FROM links WHERE id = {$activeId}")->fetchColumn() === 'https://example.com/stale-edit',
        'The first edit fixture did not update the link.'
    );
    $staleEditVersion = (string)$pdo->query("SELECT updated_at FROM links WHERE id = {$activeId}")->fetchColumn();
    $staleEditRetry = $client->form('/edit', [
        'csrf' => $csrf,
        'id' => $activeId,
        'return_to_detail' => '1',
        'target_url' => 'https://example.com/should-not-win',
        'title' => 'Should not win',
        'expires_at' => '',
        'updated_at' => $editVersion,
    ]);
    assert_true(
        $staleEditRetry['status'] === 303
            && header_value($staleEditRetry, 'Location') === '/edit?id=' . $activeId . '&return_to_detail=1',
        'A rejected stale edit must redirect to the exact direct edit route.'
    );
    assert_true(
        $pdo->query("SELECT target_url FROM links WHERE id = {$activeId}")->fetchColumn() === 'https://example.com/stale-edit',
        'A stale edit silently overwrote a newer edit.'
    );
    $staleEditPage = $client->request('GET', '/edit?id=' . $activeId . '&return_to_detail=1');
    assert_true(str_contains($staleEditPage['body'], 'class="flash error"'), 'A stale edit did not use the error flash style.');

    $toggleVersion = $staleEditVersion;
    assert_true($client->form('/toggle', [
        'csrf' => $csrf,
        'id' => $activeId,
        'desired_state' => '0',
        'updated_at' => $toggleVersion,
    ])['status'] === 303, 'Disabling a link must redirect.');
    assert_true((int)$pdo->query("SELECT is_active FROM links WHERE id = {$activeId}")->fetchColumn() === 0, 'The link was not disabled.');
    assert_true($client->form('/toggle', [
        'csrf' => $csrf,
        'id' => $activeId,
        'desired_state' => '1',
        'updated_at' => $toggleVersion,
    ])['status'] === 303, 'A stale toggle must redirect.');
    assert_true((int)$pdo->query("SELECT is_active FROM links WHERE id = {$activeId}")->fetchColumn() === 0, 'A stale toggle changed the current state.');
    $toggleVersion = (string)$pdo->query("SELECT updated_at FROM links WHERE id = {$activeId}")->fetchColumn();
    assert_true($client->form('/toggle', [
        'csrf' => $csrf,
        'id' => $activeId,
        'desired_state' => '1',
        'updated_at' => $toggleVersion,
    ])['status'] === 303, 'Re-enabling a link must redirect.');
    assert_true((int)$pdo->query("SELECT is_active FROM links WHERE id = {$activeId}")->fetchColumn() === 1, 'The link was not re-enabled.');

    foreach ([
        ['slug' => 'percent01', 'title' => '100% literal'],
        ['slug' => 'percent02', 'title' => '100X literal'],
        ['slug' => 'under01', 'title' => 'under_score literal'],
        ['slug' => 'under02', 'title' => 'underXscore literal'],
    ] as $searchFixture) {
        $response = $client->form('/shorten', [
            'csrf' => $csrf,
            'target_url' => 'https://example.com/' . $searchFixture['slug'],
            'title' => $searchFixture['title'],
            'custom_slug' => $searchFixture['slug'],
            'expires_at' => '',
        ]);
        assert_true($response['status'] === 303, 'Search fixture creation failed.');
    }
    $client->request('GET', '/');
    $literalSearch = $client->request('GET', '/?q=%25');
    assert_true(str_contains($literalSearch['body'], 'percent01'), 'Literal percent search missed the matching link.');
    assert_true(!str_contains($literalSearch['body'], 'percent02'), 'Percent search was still treated as a wildcard.');
    $underscoreSearch = $client->request('GET', '/?q=_');
    assert_true(str_contains($underscoreSearch['body'], 'under01'), 'Literal underscore search missed the matching link.');
    assert_true(!str_contains($underscoreSearch['body'], 'under02'), 'Underscore search was still treated as a wildcard.');
    $ftsSearch = $client->request('GET', '/?q=Edited');
    assert_true(str_contains($ftsSearch['body'], 'active01'), 'FTS index was not updated after editing a link.');
    $emptyFilterPage = $client->request('GET', '/?q=definitely-no-match&tag=missing');
    assert_true(str_contains($emptyFilterPage['body'], '没有匹配的链接。'), 'Filtered empty state is missing.');
    assert_true(str_contains($emptyFilterPage['body'], '当前条件：') && str_contains($emptyFilterPage['body'], '清除筛选'), 'Filtered empty state does not explain or clear its conditions.');

    $delete = $client->form('/delete', ['csrf' => $csrf, 'id' => $activeId]);
    assert_true($delete['status'] === 303, 'Deleting a link must redirect.');
    assert_true($client->request('GET', '/active01')['status'] === 404, 'A deleted link must not redirect.');
    $deleteFlashPage = $client->request('GET', '/');
    assert_true(str_contains($deleteFlashPage['body'], '删除成功，可撤销。'), 'Soft delete does not offer undo.');
    assert_true(
        str_contains($deleteFlashPage['body'], 'action="/restore"')
            && str_contains($deleteFlashPage['body'], 'name="id" value="' . $activeId . '"')
            && str_contains($deleteFlashPage['body'], '撤销删除'),
        'Soft-delete undo action is missing its exact restore route or link ID.'
    );
    $restore = $client->form('/restore', ['csrf' => $csrf, 'id' => $activeId, 'return_view' => 'trash']);
    assert_true($restore['status'] === 303, 'Restoring a link must redirect.');
    assert_true($client->request('GET', '/active01')['status'] === 302, 'A restored link must redirect again.');

    $trashExpired = $client->form('/delete', ['csrf' => $csrf, 'id' => $expiredId]);
    assert_true($trashExpired['status'] === 303, 'Preparing a trashed snapshot fixture failed.');
    $purgeWithoutConfirmation = $client->form('/purge', ['csrf' => $csrf, 'id' => $expiredId]);
    assert_true($purgeWithoutConfirmation['status'] === 303, 'An unconfirmed purge must redirect.');
    assert_true((int)$pdo->query("SELECT COUNT(*) FROM links WHERE id = {$expiredId}")->fetchColumn() === 1, 'An unconfirmed purge deleted data.');

    assert_true($client->form('/delete', ['csrf' => $csrf, 'id' => $managedId])['status'] === 303, 'Tagged trash fixture could not be deleted.');
    $taggedTrashPage = $client->request('GET', '/?view=trash&tag=mobile');
    assert_true(str_contains($taggedTrashPage['body'], 'managed01'), 'Trash tag filter missed a deleted tagged link.');
    assert_true(str_contains($taggedTrashPage['body'], 'mobile (1)'), 'Trash tag list did not use trash-only counts.');
    assert_true(str_contains($taggedTrashPage['body'], '/link?id=' . $managedId), 'Trash row has no detail entry.');
    $trashDetailPage = $client->request('GET', '/link?id=' . $managedId . '&return_view=trash&return_tag=mobile&return_scroll=321');
    assert_true($trashDetailPage['status'] === 200 && str_contains($trashDetailPage['body'], '已删除'), 'Deleted link detail page is unavailable.');
    assert_true(str_contains($trashDetailPage['body'], 'view=trash') && str_contains($trashDetailPage['body'], 'tag=mobile') && str_contains($trashDetailPage['body'], 'scroll=321'), 'Detail return link lost trash context.');
    assert_true($client->form('/restore', ['csrf' => $csrf, 'id' => $managedId, 'return_view' => 'trash'])['status'] === 303, 'Tagged trash fixture could not be restored.');
