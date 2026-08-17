<?php

declare(strict_types=1);

    $export = $client->request('GET', '/export-links');
    assert_true($export['status'] === 200, 'Export must return 200.');
    $exportPayload = json_decode($export['body'], true, 512, JSON_THROW_ON_ERROR);
    assert_true(($exportPayload['kind'] ?? null) === 'link_export', 'Link export kind is missing.');
    assert_true(($exportPayload['version'] ?? null) === 3, 'Link export did not use the domain-aware v3 safety format.');
    $exportSlugs = array_column($exportPayload['links'] ?? [], 'slug');
    assert_true(in_array('active01', $exportSlugs, true), 'Export is missing the active link.');
    assert_true(($exportPayload['scope'] ?? null) === 'all', 'Default link export did not declare all scope.');
    $exportedBySlug = array_column($exportPayload['links'] ?? [], null, 'slug');
    assert_true(
        ($exportedBySlug['api001']['tags'] ?? null) === ['api,primary', 'stable'],
        'Link export flattened structured API tags into a comma-delimited string.'
    );
    assert_true(
        isset($exportedBySlug['protected01'])
            && ($exportedBySlug['protected01']['password_protected'] ?? null) === 1
            && ($exportedBySlug['protected01']['invalid_message'] ?? null) === 'This protected link is unavailable.'
            && ($exportedBySlug['protected01']['fallback_url'] ?? null) === 'https://example.com/protected-fallback'
            && !array_key_exists('access_password_hash', $exportedBySlug['protected01'])
            && !str_contains($export['body'], $protectedPassword)
            && !str_contains($export['body'], $protectedHash),
        'Link export exposed protected-link password material.'
    );

    $currentExport = $client->request('GET', '/export-links?scope=current&q=Edited&status=active');
    assert_true($currentExport['status'] === 200, 'Current-view export must return 200.');
    $currentExportPayload = json_decode($currentExport['body'], true, 512, JSON_THROW_ON_ERROR);
    $currentExportSlugs = array_column($currentExportPayload['links'] ?? [], 'slug');
    assert_true(($currentExportPayload['scope'] ?? null) === 'current' && in_array('active01', $currentExportSlugs, true), 'Current-view export missed its filtered link.');
    assert_true(!in_array('managed01', $currentExportSlugs, true), 'Current-view export ignored its search filter.');

    $selectedExport = $client->form('/export-links', [
        'csrf' => $csrf,
        'selected' => [$managedId],
        'view' => 'active',
        'status' => 'all',
        'sort' => 'created_desc',
    ]);
    assert_true($selectedExport['status'] === 200, 'Selected-link export must return 200.');
    $selectedExportPayload = json_decode($selectedExport['body'], true, 512, JSON_THROW_ON_ERROR);
    assert_true(
        ($selectedExportPayload['scope'] ?? null) === 'selected'
        && array_column($selectedExportPayload['links'] ?? [], 'slug') === ['managed01'],
        'Selected-link export included links that were not selected.'
    );

    assert_true($client->form('/filters/save', [
        'csrf' => $csrf,
        'name' => '常用工作链接',
        'q' => 'Edited',
        'view' => 'active',
        'status' => 'active',
        'sort' => 'clicks_desc',
        'tag' => '',
        'favorite' => '',
    ])['status'] === 303, 'Saving a reusable filter must redirect.');
    $savedFilterId = (int)$pdo->query("SELECT id FROM saved_filters WHERE name = '常用工作链接'")->fetchColumn();
    assert_true($savedFilterId > 0, 'Reusable filter was not stored.');
    assert_true(str_contains($client->request('GET', '/')['body'], '常用工作链接'), 'Saved filter is not available from the dashboard.');
    assert_true($client->form('/filters/rename', [
        'csrf' => $csrf,
        'id' => $savedFilterId,
        'name' => '每日工作链接',
    ])['status'] === 303, 'Renaming a reusable filter must redirect.');
    assert_true(
        (string)$pdo->query("SELECT name FROM saved_filters WHERE id = {$savedFilterId}")->fetchColumn() === '每日工作链接',
        'Reusable filter was not renamed.'
    );
    $renamedFilterPage = $client->request('GET', '/');
    assert_true(str_contains($renamedFilterPage['body'], '每日工作链接'), 'Renamed filter is not available from the dashboard.');
    assert_true(str_contains($renamedFilterPage['body'], 'data-rename-filter'), 'Saved filter rename control is missing.');

    $snapshot = $client->request('GET', '/export-snapshot');
    assert_true(
        $snapshot['status'] === 200
            && str_contains((string)header_value($snapshot, 'Content-Disposition'), 'linkvault-audit-snapshot-'),
        'Audit data snapshot must return 200 with a non-backup filename.'
    );
    $snapshotPayload = json_decode($snapshot['body'], true, 512, JSON_THROW_ON_ERROR);
    assert_true(($snapshotPayload['kind'] ?? null) === 'full_data_snapshot', 'Audit data snapshot kind is missing.');
    assert_true(($snapshotPayload['restorable'] ?? null) === false, 'Audit data snapshot does not declare itself non-restorable.');
    assert_true(
        in_array('bulk_operations', $snapshotPayload['table_manifest']['included_tables'] ?? [], true)
            && in_array('saved_analytics_views', $snapshotPayload['table_manifest']['included_tables'] ?? [], true)
            && in_array('target_health', $snapshotPayload['table_manifest']['included_tables'] ?? [], true)
            && in_array('link_aliases', $snapshotPayload['table_manifest']['included_tables'] ?? [], true)
            && in_array('link_presets', $snapshotPayload['table_manifest']['included_tables'] ?? [], true)
            && in_array('webhook_delivery_attempts', $snapshotPayload['table_manifest']['included_tables'] ?? [], true)
            && in_array('analytics_ingest_state', $snapshotPayload['table_manifest']['included_tables'] ?? [], true)
            && in_array('admin_security', $snapshotPayload['table_manifest']['excluded_tables'] ?? [], true),
        'Audit data snapshot does not declare its included and excluded tables.'
    );
    $snapshotLinks = array_column($snapshotPayload['links'] ?? [], null, 'slug');
    assert_true(!empty($snapshotLinks['expired01']['deleted_at']), 'Audit data snapshot is missing recycle-bin state.');
    foreach (['clicks', 'created_at', 'updated_at', 'last_accessed_at'] as $field) {
        assert_true(array_key_exists($field, $snapshotLinks['active01'] ?? []), "Audit data snapshot is missing {$field}.");
    }
    assert_true(!empty($snapshotPayload['link_daily_stats']), 'Audit data snapshot is missing daily click statistics.');
    assert_true(array_key_exists('link_daily_stats_archive', $snapshotPayload), 'Audit data snapshot is missing archived daily statistics.');
    assert_true(count($snapshotPayload['saved_filters'] ?? []) === 1, 'Audit data snapshot is missing saved filters.');
    assert_true(count($snapshotPayload['api_tokens'] ?? []) === 2, 'Audit data snapshot is missing API token metadata.');
    assert_true(!array_key_exists('token_hash', $snapshotPayload['api_tokens'][0] ?? []), 'Audit data snapshot exposed an API token digest.');
    assert_true(!empty($snapshotPayload['api_token_usage']), 'Audit data snapshot is missing API token usage records.');
    assert_true(
        array_key_exists('bulk_operations', $snapshotPayload)
            && array_key_exists('saved_analytics_views', $snapshotPayload)
            && array_key_exists('target_health', $snapshotPayload)
            && array_key_exists('analytics_alert_state', $snapshotPayload),
        'Audit data snapshot is missing operational audit tables.'
    );
    assert_true(
        ($snapshotLinks['protected01']['password_protected'] ?? null) === 1
            && !array_key_exists('access_password_hash', $snapshotLinks['protected01'] ?? [])
            && !str_contains($snapshot['body'], $protectedPassword)
            && !str_contains($snapshot['body'], $protectedHash),
        'Audit data snapshot omitted the protection indicator or exposed password material.'
    );
    assert_true($client->form('/filters/delete', ['csrf' => $csrf, 'id' => $savedFilterId])['status'] === 303, 'Deleting a saved filter must redirect.');
    assert_true((int)$pdo->query("SELECT COUNT(*) FROM saved_filters WHERE id = {$savedFilterId}")->fetchColumn() === 0, 'Saved filter was not deleted.');

    $trashPage = $client->request('GET', '/?view=trash');
    assert_true(
        preg_match('/data-confirm-token="([a-f0-9]{64})"/', $trashPage['body'], $purgeTokenMatch) === 1,
        'The purge confirmation token is missing.'
    );
    $purge = $client->form('/purge', [
        'csrf' => $csrf,
        'id' => $expiredId,
        'confirmation_token' => $purgeTokenMatch[1],
    ]);
    assert_true($purge['status'] === 303, 'A confirmed purge must redirect.');
    assert_true((int)$pdo->query("SELECT COUNT(*) FROM links WHERE id = {$expiredId}")->fetchColumn() === 0, 'A confirmed purge did not remove the link.');

    $snapshotImportPayload = $snapshotPayload;
    $snapshotImportPayload['links'][0]['slug'] = 'snapshot01';
    $snapshotImport = import_json($client, $csrf, json_encode($snapshotImportPayload, JSON_THROW_ON_ERROR));
    assert_true($snapshotImport['status'] === 303, 'Audit data snapshot rejection must redirect.');
    assert_true((int)$pdo->query("SELECT COUNT(*) FROM links WHERE slug = 'snapshot01'")->fetchColumn() === 0, 'Audit data snapshot was accepted by the link importer.');
    $snapshotImportPage = $client->request('GET', '/');
    assert_true(str_contains($snapshotImportPage['body'], '审计数据快照不能导入或恢复'), 'Audit snapshot rejection is not explicit.');

    $untypedImportPayload = json_encode(['links' => [[
        'slug' => 'untyped01',
        'target_url' => 'https://example.com/untyped',
    ]]], JSON_THROW_ON_ERROR);
    assert_true(import_json($client, $csrf, $untypedImportPayload)['status'] === 303, 'Untyped import rejection must redirect.');
    assert_true((int)$pdo->query("SELECT COUNT(*) FROM links WHERE slug = 'untyped01'")->fetchColumn() === 0, 'Untyped JSON was accepted by the link importer.');
    $untypedImportPage = $client->request('GET', '/');
    assert_true(str_contains($untypedImportPage['body'], 'version=1'), 'Unsupported import envelope rejection is not explicit.');

    $unsupportedImportPayload = json_encode([
        'kind' => 'link_export',
        'version' => 3,
        'links' => [[
            'slug' => 'version03',
            'target_url' => 'https://example.com/version-3',
        ]],
    ], JSON_THROW_ON_ERROR);
    assert_true(import_json($client, $csrf, $unsupportedImportPayload)['status'] === 303, 'Unsupported import version rejection must redirect.');
    assert_true((int)$pdo->query("SELECT COUNT(*) FROM links WHERE slug = 'version03'")->fetchColumn() === 0, 'Unsupported link export version was imported.');

    $tabFormula = "\t=1+1";
    $issueItems = [
        ['slug' => '=1+1', 'target_url' => 'https://example.com/formula'],
        ['slug' => '+1+1', 'target_url' => 'https://example.com/plus-formula'],
        ['slug' => '-1+1', 'target_url' => 'https://example.com/minus-formula'],
        ['slug' => '@cmd', 'target_url' => 'https://example.com/at-formula'],
        ['slug' => $tabFormula, 'target_url' => 'https://example.com/tab-formula'],
    ];
    for ($issueIndex = 6; $issueIndex <= 105; $issueIndex++) {
        $issueItems[] = ['slug' => '!invalid' . $issueIndex, 'target_url' => 'https://example.com/invalid/' . $issueIndex];
    }
    $issuePayload = json_encode([
        'kind' => 'link_export',
        'version' => 1,
        'links' => $issueItems,
    ], JSON_THROW_ON_ERROR);
    assert_true(import_json($client, $csrf, $issuePayload)['status'] === 303, 'Import issue preview did not redirect.');
    $issuePreviewPage = $client->request('GET', '/');
    assert_true(str_contains($issuePreviewPage['body'], '前 100 / 共 105 条问题（共 105 条记录）'), 'Import preview does not show its displayed and total issue counts.');
    assert_true(str_contains($issuePreviewPage['body'], '下载完整错误报告'), 'Import preview has no complete error report download.');
    assert_true(str_contains($issuePreviewPage['body'], '<button type="submit" disabled>'), 'All-invalid import preview did not disable confirmation.');
    $issueReport = $client->request('GET', '/import-report');
    assert_true($issueReport['status'] === 200 && str_starts_with((string)header_value($issueReport, 'Content-Type'), 'text/csv'), 'Import error report is not downloadable as CSV.');
    assert_true(str_contains($issueReport['body'], '!invalid105'), 'Complete import error report omitted issues beyond the preview limit.');
    assert_true(str_contains($issueReport['body'], "'=1+1"), 'Import error report did not escape a formula-like CSV cell.');
    assert_true(str_contains($issueReport['body'], "'+1+1"), 'Import error report did not escape a plus-prefixed CSV cell.');
    assert_true(str_contains($issueReport['body'], "'-1+1"), 'Import error report did not escape a minus-prefixed CSV cell.');
    assert_true(str_contains($issueReport['body'], "'@cmd"), 'Import error report did not escape an at-prefixed CSV cell.');
    assert_true(str_contains($issueReport['body'], "'" . $tabFormula), 'Import error report did not escape a whitespace-prefixed formula cell.');
    assert_true($client->form('/import-cancel', ['csrf' => $csrf])['status'] === 303, 'Import issue preview could not be cancelled.');

    $strictTypePayload = json_encode([
        'kind' => 'link_export',
        'version' => 1,
        'links' => [
            ['slug' => 'typed001', 'target_url' => 'https://example.com/typed/1', 'is_active' => 'false'],
            ['slug' => 'typed002', 'target_url' => 'https://example.com/typed/2', 'max_clicks' => 1.9],
            ['slug' => 'typed003', 'target_url' => 'https://example.com/typed/3', 'tags' => ['valid', 2]],
        ],
    ], JSON_THROW_ON_ERROR);
    assert_true(import_json($client, $csrf, $strictTypePayload)['status'] === 303, 'Strict-type import preview did not redirect.');
    assert_true((int)$pdo->query("SELECT COUNT(*) FROM links WHERE slug LIKE 'typed00%'")->fetchColumn() === 0, 'Strict-type import accepted a weakly typed field.');
    $strictTypePage = $client->request('GET', '/');
    assert_true(str_contains($strictTypePage['body'], '共 3 条问题'), 'Strict-type import did not report every invalid field.');
    assert_true(
        str_contains($strictTypePage['body'], 'is_active 必须是整数 0 或 1')
            && str_contains($strictTypePage['body'], '最大点击次数无效')
            && str_contains($strictTypePage['body'], '标签数组至多包含'),
        'Strict-type import errors do not identify the invalid field types.'
    );
    assert_true($client->form('/import-cancel', ['csrf' => $csrf])['status'] === 303, 'Strict-type import preview could not be cancelled.');

    $importPayload = json_encode([
        'kind' => 'link_export',
        'version' => 1,
        'links' => [[
            'slug' => 'import01',
            'target_url' => 'https://example.com/imported',
            'title' => 'Imported link',
            'is_active' => 1,
            'expires_at' => null,
            'tags' => ['alpha,beta', 'gamma'],
        ]],
    ], JSON_THROW_ON_ERROR);
    $import = import_json($client, $csrf, $importPayload);
    assert_true($import['status'] === 303, 'Import must redirect after processing.');
    $importPreviewPage = $client->request('GET', '/');
    assert_true(preg_match('/name="preview_token" value="([a-f0-9]+)"/', $importPreviewPage['body'], $importTokenMatch) === 1, 'Import preview token is missing.');
    assert_true($client->form('/import-confirm', [
        'csrf' => $csrf,
        'preview_token' => $importTokenMatch[1],
    ])['status'] === 303, 'Import confirmation must redirect.');
    assert_true((int)$pdo->query("SELECT COUNT(*) FROM links WHERE slug = 'import01'")->fetchColumn() === 1, 'Import did not store the link.');
    assert_true(
        (int)$pdo->query("SELECT COUNT(*) FROM link_tags t INNER JOIN links l ON l.id = t.link_id WHERE l.slug = 'import01'")->fetchColumn() === 2
            && (int)$pdo->query("SELECT COUNT(*) FROM link_tags t INNER JOIN links l ON l.id = t.link_id WHERE l.slug = 'import01' AND t.tag = 'alpha,beta'")->fetchColumn() === 1,
        'Import flattened an array tag containing a comma.'
    );

    $importedOriginal = $pdo->query("SELECT * FROM links WHERE slug = 'import01'")->fetch(PDO::FETCH_ASSOC);
    $overwritePayload = json_encode([
        'kind' => 'link_export',
        'version' => 1,
        'links' => [[
            'slug' => 'import01',
            'target_url' => 'https://example.com/imported/updated',
            'title' => 'Overwritten link',
            'is_active' => 0,
            'tags' => ['merged'],
        ]],
    ], JSON_THROW_ON_ERROR);
    assert_true(import_json($client, $csrf, $overwritePayload, 'overwrite')['status'] === 303, 'Overwrite import preview failed.');
    $overwritePreview = $client->request('GET', '/');
    assert_true(
        str_contains($overwritePreview['body'], '冲突时覆盖')
            && str_contains($overwritePreview['body'], 'Overwritten link')
            && str_contains($overwritePreview['body'], '确认应用 1 项')
            && preg_match('/name="preview_token" value="([a-f0-9]+)"/', $overwritePreview['body'], $overwriteToken) === 1,
        'Overwrite Dry Run did not show its mode and field differences.'
    );
    assert_true($client->request('GET', '/import-report?type=changes')['status'] === 200, 'Import difference report is not downloadable.');
    assert_true($client->form('/import-confirm', [
        'csrf' => $csrf,
        'preview_token' => $overwriteToken[1],
    ])['status'] === 303, 'Overwrite import confirmation failed.');
    $importedOverwritten = $pdo->query("SELECT * FROM links WHERE slug = 'import01'")->fetch(PDO::FETCH_ASSOC);
    assert_true(
        is_array($importedOriginal) && is_array($importedOverwritten)
            && (int)$importedOverwritten['id'] === (int)$importedOriginal['id']
            && (string)$importedOverwritten['target_url'] === 'https://example.com/imported/updated'
            && (string)$importedOverwritten['title'] === 'Overwritten link'
            && (int)$importedOverwritten['is_active'] === 0
            && (int)$pdo->query("SELECT COUNT(*) FROM link_tags WHERE link_id = " . (int)$importedOverwritten['id'] . " AND tag = 'merged'")->fetchColumn() === 1,
        'Overwrite import replaced identity or did not update portable fields.'
    );

    assert_true(import_json($client, $csrf, $overwritePayload, 'new_slug')['status'] === 303, 'New-slug import preview failed.');
    $newSlugPreview = $client->request('GET', '/');
    assert_true(
        str_contains($newSlugPreview['body'], 'import01-2')
            && preg_match('/name="preview_token" value="([a-f0-9]+)"/', $newSlugPreview['body'], $newSlugToken) === 1,
        'New-slug Dry Run did not show the generated short code.'
    );
    assert_true($client->form('/import-confirm', [
        'csrf' => $csrf,
        'preview_token' => $newSlugToken[1],
    ])['status'] === 303, 'New-slug import confirmation failed.');
    assert_true((int)$pdo->query("SELECT COUNT(*) FROM links WHERE slug = 'import01-2'")->fetchColumn() === 1, 'Generated import short code was not stored.');

    $stalePayload = json_encode([
        'kind' => 'link_export',
        'version' => 1,
        'links' => [[
            'slug' => 'import01',
            'target_url' => 'https://example.com/stale-overwrite',
            'title' => 'Must not be applied',
        ]],
    ], JSON_THROW_ON_ERROR);
    assert_true(import_json($client, $csrf, $stalePayload, 'overwrite')['status'] === 303, 'Stale overwrite preview failed.');
    $stalePreview = $client->request('GET', '/');
    assert_true(preg_match('/name="preview_token" value="([a-f0-9]+)"/', $stalePreview['body'], $staleToken) === 1, 'Stale overwrite token is missing.');
    $pdo->exec("UPDATE links SET title = 'Concurrent edit', updated_at = '2030-01-01T00:00:00Z' WHERE slug = 'import01'");
    assert_true($client->form('/import-confirm', [
        'csrf' => $csrf,
        'preview_token' => $staleToken[1],
    ])['status'] === 303, 'Stale overwrite rejection did not redirect.');
    assert_true(
        (string)$pdo->query("SELECT target_url FROM links WHERE slug = 'import01'")->fetchColumn() === 'https://example.com/imported/updated'
            && str_contains($client->request('GET', '/')['body'], '请重新执行 Dry Run'),
        'Stale overwrite was applied or did not require a fresh Dry Run.'
    );

    $protectedImportPayload = json_encode([
        'kind' => 'link_export',
        'version' => 2,
        'links' => [[
            'slug' => 'protectedimport',
            'target_url' => 'https://example.com/protected-import',
            'title' => 'Imported protected link',
            'is_active' => 1,
            'password_protected' => 1,
            'invalid_message' => 'Imported unavailable message.',
            'fallback_url' => 'https://example.com/import-fallback',
        ]],
    ], JSON_THROW_ON_ERROR);
    assert_true(import_json($client, $csrf, $protectedImportPayload)['status'] === 303, 'Protected v2 import preview failed.');
    $protectedImportPreview = $client->request('GET', '/');
    assert_true(
        str_contains($protectedImportPreview['body'], '必须重新设置密码')
            && preg_match('/name="preview_token" value="([a-f0-9]+)"/', $protectedImportPreview['body'], $protectedImportToken) === 1,
        'Protected v2 import did not warn that a password reset is required.'
    );
    assert_true($client->form('/import-confirm', [
        'csrf' => $csrf,
        'preview_token' => $protectedImportToken[1],
    ])['status'] === 303, 'Protected v2 import confirmation failed.');
    $protectedImported = $pdo->query("SELECT * FROM links WHERE slug = 'protectedimport'")->fetch(PDO::FETCH_ASSOC);
    assert_true(
        is_array($protectedImported)
            && (int)$protectedImported['is_active'] === 0
            && (int)$protectedImported['access_password_reset_required'] === 1
            && $protectedImported['access_password_hash'] === null
            && (string)$protectedImported['invalid_message'] === 'Imported unavailable message.'
            && (string)$protectedImported['fallback_url'] === 'https://example.com/import-fallback',
        'Protected v2 import became public or lost its invalid-link fields.'
    );
    assert_true($client->form('/toggle', [
        'csrf' => $csrf,
        'id' => (int)$protectedImported['id'],
        'desired_state' => '1',
        'updated_at' => (string)$protectedImported['updated_at'],
    ])['status'] === 303, 'Protected imported link activation did not redirect.');
    assert_true(
        (int)$pdo->query("SELECT is_active FROM links WHERE slug = 'protectedimport'")->fetchColumn() === 0,
        'Protected imported link was enabled before its password was reset.'
    );
    assert_true($client->form('/edit', [
        'csrf' => $csrf,
        'id' => (int)$protectedImported['id'],
        'updated_at' => (string)$protectedImported['updated_at'],
        'target_url' => (string)$protectedImported['target_url'],
        'title' => (string)$protectedImported['title'],
        'expires_at' => '',
        'starts_at' => '',
        'tags' => '',
        'max_clicks' => '',
        'campaign_name' => '',
        'campaign_source' => '',
        'campaign_medium' => '',
        'campaign_content' => '',
        'access_password' => 'ImportedReset!234',
        'invalid_message' => (string)$protectedImported['invalid_message'],
        'fallback_url' => (string)$protectedImported['fallback_url'],
    ])['status'] === 303, 'Protected imported link password reset failed.');
    $protectedReset = $pdo->query("SELECT * FROM links WHERE slug = 'protectedimport'")->fetch(PDO::FETCH_ASSOC);
    assert_true(
        is_array($protectedReset)
            && (int)$protectedReset['access_password_reset_required'] === 0
            && is_string($protectedReset['access_password_hash'])
            && password_verify('ImportedReset!234', $protectedReset['access_password_hash']),
        'Resetting an imported password did not clear the activation guard.'
    );
    $protectedOverwritePayload = json_encode([
        'kind' => 'link_export',
        'version' => 2,
        'links' => [[
            'slug' => 'protectedimport',
            'target_url' => 'https://example.com/protected-import-overwrite',
            'title' => 'Protected overwrite',
            'is_active' => 1,
            'password_protected' => 1,
            'invalid_message' => 'Overwritten unavailable message.',
            'fallback_url' => 'https://example.com/overwrite-fallback',
        ]],
    ], JSON_THROW_ON_ERROR);
    assert_true(
        import_json($client, $csrf, $protectedOverwritePayload, 'overwrite')['status'] === 303,
        'Protected v2 overwrite preview failed.'
    );
    $protectedOverwritePreview = $client->request('GET', '/');
    assert_true(
        preg_match('/name="preview_token" value="([a-f0-9]+)"/', $protectedOverwritePreview['body'], $protectedOverwriteToken) === 1,
        'Protected v2 overwrite token is missing.'
    );
    assert_true($client->form('/import-confirm', [
        'csrf' => $csrf,
        'preview_token' => $protectedOverwriteToken[1],
    ])['status'] === 303, 'Protected v2 overwrite confirmation failed.');
    $protectedOverwritten = $pdo->query("SELECT * FROM links WHERE slug = 'protectedimport'")->fetch(PDO::FETCH_ASSOC);
    assert_true(
        is_array($protectedOverwritten)
            && (int)$protectedOverwritten['is_active'] === 0
            && (int)$protectedOverwritten['access_password_reset_required'] === 1
            && $protectedOverwritten['access_password_hash'] === null
            && (string)$protectedOverwritten['invalid_message'] === 'Overwritten unavailable message.'
            && (string)$protectedOverwritten['fallback_url'] === 'https://example.com/overwrite-fallback',
        'Protected v2 overwrite retained a usable password or lost migrated invalid-link fields.'
    );

    $atomicItems = [];
    for ($index = 0; $index < 101; $index++) {
        $atomicItems[] = [
            'slug' => 'atomic' . str_pad((string)$index, 3, '0', STR_PAD_LEFT),
            'target_url' => 'https://example.com/atomic/' . $index,
        ];
    }
    $atomicPayload = json_encode([
        'kind' => 'link_export',
        'version' => 1,
        'links' => $atomicItems,
    ], JSON_THROW_ON_ERROR);
    assert_true(import_json($client, $csrf, $atomicPayload)['status'] === 303, 'Atomic import preview failed.');
    $atomicPreviewPage = $client->request('GET', '/');
    assert_true(preg_match('/name="preview_token" value="([a-f0-9]+)"/', $atomicPreviewPage['body'], $atomicTokenMatch) === 1, 'Atomic import preview token is missing.');
    $pdo->exec(<<<'SQL'
        CREATE TRIGGER fail_atomic_import BEFORE INSERT ON links
        WHEN new.slug = 'atomic100'
        BEGIN
            SELECT RAISE(ABORT, 'forced atomic import failure');
        END
    SQL);
    assert_true($client->form('/import-confirm', [
        'csrf' => $csrf,
        'preview_token' => $atomicTokenMatch[1],
    ])['status'] === 303, 'Failed atomic import must redirect.');
    $pdo->exec('DROP TRIGGER fail_atomic_import');
    assert_true((int)$pdo->query("SELECT COUNT(*) FROM links WHERE slug LIKE 'atomic%'")->fetchColumn() === 0, 'A failed import committed an earlier batch.');

    $longUrlPayload = json_encode([
        'kind' => 'link_export',
        'version' => 1,
        'links' => [[
            'slug' => 'longurl01',
            'target_url' => 'https://example.com/' . str_repeat('a', 2048),
        ]],
    ], JSON_THROW_ON_ERROR);
    assert_true(import_json($client, $csrf, $longUrlPayload)['status'] === 303, 'Long-URL import must redirect.');
    assert_true((int)$pdo->query("SELECT COUNT(*) FROM links WHERE slug = 'longurl01'")->fetchColumn() === 0, 'Import accepted an overlong target URL.');

    $tooManyLinks = [];
    for ($index = 0; $index <= 5000; $index++) {
        $tooManyLinks[] = ['slug' => 'bulk' . str_pad((string)$index, 5, '0', STR_PAD_LEFT), 'target_url' => 'https://e.co/' . $index];
    }
    $tooManyPayload = json_encode([
        'kind' => 'link_export',
        'version' => 1,
        'links' => $tooManyLinks,
    ], JSON_THROW_ON_ERROR);
    assert_true(import_json($client, $csrf, $tooManyPayload)['status'] === 303, 'Oversized record-count import must redirect.');
    assert_true((int)$pdo->query("SELECT COUNT(*) FROM links WHERE slug LIKE 'bulk%'")->fetchColumn() === 0, 'Import accepted more than 5000 records.');
