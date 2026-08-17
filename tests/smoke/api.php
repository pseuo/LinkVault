<?php

declare(strict_types=1);

    $replacement = $client->form('/shorten', [
        'csrf' => $csrf,
        'target_url' => 'https://example.com/managed',
        'custom_slug' => 'managed02',
        'expires_at' => '',
    ]);
    assert_true($replacement['status'] === 303, 'Creating a replacement for an exhausted target must redirect.');
    assert_true((int)$managedPdo->query("SELECT COUNT(*) FROM links WHERE slug = 'managed02'")->fetchColumn() === 1, 'An exhausted duplicate target was incorrectly reused.');
    $duplicateWithHistory = $client->form('/shorten', [
        'csrf' => $csrf,
        'target_url' => 'https://example.com/managed',
        'custom_slug' => 'managed03',
        'expires_at' => '',
    ]);
    assert_true($duplicateWithHistory['status'] === 303, 'Duplicate history check must redirect to confirmation.');
    assert_true((int)$managedPdo->query("SELECT COUNT(*) FROM links WHERE slug = 'managed03'")->fetchColumn() === 0, 'An available duplicate target was created without confirmation.');
    $duplicateHistoryPage = $client->request('GET', '/');
    assert_true(
        str_contains($duplicateHistoryPage['body'], 'managed02')
            && str_contains($duplicateHistoryPage['body'], 'managed01')
            && str_contains($duplicateHistoryPage['body'], '次数已用尽'),
        'Duplicate confirmation does not show the status of other matching links.'
    );

    $legacyReservedNow = gmdate('c');
    $legacyReserved = $managedPdo->prepare(<<<'SQL'
        INSERT INTO links (slug, target_url, title, created_at, updated_at)
        VALUES ('assets', :target_url, 'Legacy reserved route', :created_at, :updated_at)
    SQL);
    $legacyReserved->execute([
        'target_url' => 'https://example.com/legacy-reserved',
        'created_at' => $legacyReservedNow,
        'updated_at' => $legacyReservedNow,
    ]);
    assert_true($client->form('/shorten', [
        'csrf' => $csrf,
        'target_url' => 'https://example.com/legacy-reserved',
        'custom_slug' => 'reservedreplacement',
        'expires_at' => '',
    ])['status'] === 303, 'Creating a replacement for an unreachable reserved short code must redirect.');
    assert_true(
        (int)$managedPdo->query("SELECT COUNT(*) FROM links WHERE slug = 'reservedreplacement'")->fetchColumn() === 1,
        'An unreachable reserved short code was reused for a duplicate URL.'
    );

    $duplicate = $client->form('/shorten', [
        'csrf' => $csrf,
        'target_url' => 'https://example.com/active',
        'custom_slug' => 'active02',
        'expires_at' => '',
    ]);
    assert_true($duplicate['status'] === 303, 'Available duplicate-target check must redirect to confirmation.');
    assert_true((int)$managedPdo->query("SELECT COUNT(*) FROM links WHERE slug = 'active02'")->fetchColumn() === 0, 'Available duplicate target was created without confirmation.');
    $duplicatePromptPage = $client->request('GET', '/');
    assert_true(str_contains($duplicatePromptPage['body'], '继续创建新短链'), 'Duplicate reuse prompt is missing.');
    assert_true(
        preg_match('/name="duplicate_target_hash" value="([a-f0-9]{64})"/', $duplicatePromptPage['body'], $duplicateHashMatch) === 1,
        'Duplicate confirmation is not bound to its reviewed target.'
    );
    $staleDuplicateConfirmation = $client->form('/shorten', [
        'csrf' => $csrf,
        'create_request_id' => bin2hex(random_bytes(16)),
        'target_url' => 'https://example.com/managed',
        'custom_slug' => 'active02',
        'expires_at' => '',
        'allow_duplicate' => '1',
        'duplicate_target_hash' => $duplicateHashMatch[1],
    ]);
    assert_true(
        $staleDuplicateConfirmation['status'] === 303
            && (int)$managedPdo->query("SELECT COUNT(*) FROM links WHERE slug = 'active02'")->fetchColumn() === 0,
        'A duplicate confirmation for an old target bypassed review after the target changed.'
    );

    $domainFixtureTime = gmdate('c');
    $managedPdo->prepare(<<<'SQL'
        INSERT INTO short_domains (
            hostname, verification_token, verified_at, is_enabled, brand_name, brand_tagline,
            brand_theme, created_at, updated_at
        ) VALUES (
            'duplicate.example.test', :token, :verified_at, 1, 'Duplicate domain', '',
            'graphite', :created_at, :updated_at
        )
    SQL)->execute([
        'token' => bin2hex(random_bytes(24)),
        'verified_at' => $domainFixtureTime,
        'created_at' => $domainFixtureTime,
        'updated_at' => $domainFixtureTime,
    ]);
    $managedDomainId = (int)$managedPdo->lastInsertId();
    $managedPdo->prepare(<<<'SQL'
        INSERT INTO links (slug, target_url, title, short_domain_id, created_at, updated_at)
        VALUES ('active-domain-existing', 'https://example.com/active', 'Domain duplicate', :domain_id, :created_at, :updated_at)
    SQL)->execute([
        'domain_id' => $managedDomainId,
        'created_at' => $domainFixtureTime,
        'updated_at' => $domainFixtureTime,
    ]);
    $domainDuplicate = $client->form('/shorten', [
        'csrf' => csrf_from($client->request('GET', '/')['body']),
        'create_request_id' => bin2hex(random_bytes(16)),
        'target_url' => 'https://example.com/active',
        'custom_slug' => 'active-domain',
        'short_domain_id' => (string)$managedDomainId,
        'allow_duplicate' => '1',
        'duplicate_target_hash' => $duplicateHashMatch[1],
    ]);
    assert_true(
        $domainDuplicate['status'] === 303
            && (int)$managedPdo->query("SELECT COUNT(*) FROM links WHERE slug = 'active-domain'")->fetchColumn() === 0,
        'A duplicate confirmation reviewed for another short domain was reused.'
    );

    $apiCreate = $client->request('POST', '/api/shorten', json_encode([
        'url' => 'https://example.com/api-created',
        'slug' => 'api001',
        'tags' => ['api,primary', 'stable'],
        'campaign_name' => 'api_launch',
        'source' => 'automation',
        'medium' => 'api',
        'content' => 'primary',
    ], JSON_THROW_ON_ERROR), [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiToken,
    ]);
    assert_true($apiCreate['status'] === 201, 'Create-only API did not create a link.');
    assert_true((int)$managedPdo->query("SELECT COUNT(*) FROM links WHERE slug = 'api001'")->fetchColumn() === 1, 'API-created link was not stored.');

    $apiCrudCreate = $client->request('POST', '/api/shorten', json_encode([
        'url' => 'https://example.com/api-crud',
        'slug' => 'apicrud1',
        'title' => 'CRUD API link',
    ], JSON_THROW_ON_ERROR), [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiToken,
    ]);
    assert_true($apiCrudCreate['status'] === 201, 'Create scope could not prepare a CRUD API test link.');
    $apiCrudId = (int)$managedPdo->query("SELECT id FROM links WHERE slug = 'apicrud1'")->fetchColumn();
    $crudToken = 'crud-token-' . bin2hex(random_bytes(20));
    $crudTokenInsert = $managedPdo->prepare(<<<'SQL'
        INSERT INTO api_tokens (name, token_prefix, token_hash, scopes, created_at)
        VALUES ('CRUD test', :prefix, :token_hash, 'links:read links:write links:delete', :created_at)
    SQL);
    $crudTokenInsert->execute([
        'prefix' => substr($crudToken, 0, 12),
        'token_hash' => hash('sha256', $crudToken),
        'created_at' => gmdate('c'),
    ]);
    $crudHeaders = ['Authorization: Bearer ' . $crudToken];
    $legacyReadDenied = $client->request('GET', '/api/links', '', ['Authorization: Bearer ' . $apiToken]);
    assert_true(
        $legacyReadDenied['status'] === 403
            && str_contains($legacyReadDenied['body'], 'insufficient_scope')
            && str_contains((string)header_value($legacyReadDenied, 'WWW-Authenticate'), 'links:read'),
        'Create-only environment token gained read access.'
    );
    $apiList = $client->request('GET', '/api/links?per_page=10&q=CRUD', '', $crudHeaders);
    assert_true($apiList['status'] === 200 && str_contains($apiList['body'], 'apicrud1'), 'Scoped API list did not return matching links.');
    $apiGet = $client->request('GET', '/api/links/' . $apiCrudId, '', $crudHeaders);
    $apiCrudEtag = header_value($apiGet, 'ETag');
    assert_true(
        $apiGet['status'] === 200 && is_string($apiCrudEtag)
            && str_contains($apiGet['body'], 'CRUD API link')
            && !str_contains($apiGet['body'], 'access_password_hash'),
        'Scoped API get omitted its ETag or exposed private fields.'
    );
    $missingPrecondition = $client->request('PATCH', '/api/links/' . $apiCrudId, '{"title":"Unsafe"}', [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $crudToken,
    ]);
    assert_true($missingPrecondition['status'] === 428, 'API update accepted a missing If-Match precondition.');
    $apiPatch = $client->request('PATCH', '/api/links/' . $apiCrudId, json_encode([
        'title' => 'CRUD API updated',
        'tags' => ['api', 'edited'],
        'max_clicks' => 25,
    ], JSON_THROW_ON_ERROR), [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $crudToken,
        'If-Match: ' . $apiCrudEtag,
    ]);
    assert_true($apiPatch['status'] === 204 && is_string(header_value($apiPatch, 'ETag')), 'Scoped API update failed.');
    $stalePatch = $client->request('PATCH', '/api/links/' . $apiCrudId, '{"title":"Stale"}', [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $crudToken,
        'If-Match: ' . $apiCrudEtag,
    ]);
    assert_true($stalePatch['status'] === 412, 'API update accepted a stale ETag.');
    $apiDisable = $client->request('POST', '/api/links/' . $apiCrudId . '/disable', '', [
        'Authorization: Bearer ' . $crudToken,
        'If-Match: ' . header_value($apiPatch, 'ETag'),
    ]);
    assert_true(
        $apiDisable['status'] === 204
            && (int)$managedPdo->query("SELECT is_active FROM links WHERE id = {$apiCrudId}")->fetchColumn() === 0,
        'Scoped API disable failed.'
    );
    $apiDelete = $client->request('DELETE', '/api/links/' . $apiCrudId, '', [
        'Authorization: Bearer ' . $crudToken,
        'If-Match: ' . header_value($apiDisable, 'ETag'),
    ]);
    assert_true(
        $apiDelete['status'] === 204
            && $managedPdo->query("SELECT deleted_at FROM links WHERE id = {$apiCrudId}")->fetchColumn() !== null,
        'Scoped API delete did not move the link to the recycle bin.'
    );
    foreach (['api_update', 'api_disable', 'api_delete'] as $auditedApiAction) {
        $auditDetails = json_decode((string)$managedPdo->query(
            "SELECT details_json FROM audit_events WHERE action = '{$auditedApiAction}'"
                . " AND entity_id = '{$apiCrudId}' ORDER BY id DESC LIMIT 1"
        )->fetchColumn(), true, 32, JSON_THROW_ON_ERROR);
        assert_true(
            is_array($auditDetails['before'] ?? null) && is_array($auditDetails['after'] ?? null),
            "{$auditedApiAction} audit event omitted before/after state."
        );
    }
    assert_true(
        ($auditDetails['before']['deleted_at'] ?? null) === null
            && ($auditDetails['after']['deleted_at'] ?? null) !== null,
        'API delete audit event did not capture the recycle-bin transition.'
    );
    assert_true($client->request('GET', '/api/links/' . $apiCrudId, '', $crudHeaders)['status'] === 404, 'Deleted API link remained readable.');
    $managedPdo->exec("DELETE FROM api_tokens WHERE name = 'CRUD test'");
    $apiTarget = (string)$managedPdo->query("SELECT target_url FROM links WHERE slug = 'api001'")->fetchColumn();
    $apiTargetQuery = [];
    parse_str((string)parse_url($apiTarget, PHP_URL_QUERY), $apiTargetQuery);
    assert_true(
        ($apiTargetQuery['utm_campaign'] ?? null) === 'api_launch'
            && ($apiTargetQuery['utm_source'] ?? null) === 'automation'
            && ($apiTargetQuery['utm_medium'] ?? null) === 'api'
            && ($apiTargetQuery['utm_content'] ?? null) === 'primary',
        'The API did not generate campaign UTM parameters.'
    );
    assert_true(
        (int)$managedPdo->query("SELECT COUNT(*) FROM link_tags t INNER JOIN links l ON l.id = t.link_id WHERE l.slug = 'api001'")->fetchColumn() === 2
            && (int)$managedPdo->query("SELECT COUNT(*) FROM link_tags t INNER JOIN links l ON l.id = t.link_id WHERE l.slug = 'api001' AND t.tag = 'api,primary'")->fetchColumn() === 1,
        'An API array tag containing a comma lost its element boundary.'
    );
    $apiLink = $managedPdo->query("SELECT * FROM links WHERE slug = 'api001'")->fetch();
    $apiEditPage = $client->request('GET', '/edit?id=' . (int)$apiLink['id']);
    assert_true(
        $apiEditPage['status'] === 200
            && str_contains($apiEditPage['body'], '编辑短链接：api001')
            && str_contains($apiEditPage['body'], 'name="tags" value="&quot;api,primary&quot;, stable"'),
        'The direct edit route flattened an API array tag containing a comma.'
    );
    $apiTagEdit = $client->form('/edit', [
        'csrf' => $csrf,
        'id' => (string)$apiLink['id'],
        'updated_at' => (string)$apiLink['updated_at'],
        'target_url' => (string)$apiLink['target_url'],
        'title' => (string)$apiLink['title'],
        'expires_at' => '',
        'starts_at' => '',
        'tags' => '"api,primary", stable',
        'campaign_name' => 'api_launch',
        'campaign_source' => 'automation',
        'campaign_medium' => 'api',
        'campaign_content' => 'primary',
    ]);
    assert_true($apiTagEdit['status'] === 303, 'Editing a link with a quoted comma tag failed.');
    assert_true(
        (int)$managedPdo->query("SELECT COUNT(*) FROM link_tags WHERE link_id = " . (int)$apiLink['id'])->fetchColumn() === 2
            && (int)$managedPdo->query("SELECT COUNT(*) FROM link_tags WHERE link_id = " . (int)$apiLink['id'] . " AND tag = 'api,primary'")->fetchColumn() === 1,
        'Editing from the management page split an atomic comma tag.'
    );
    assert_true(
        (int)$managedPdo->query("SELECT COUNT(*) FROM api_token_usage WHERE token_id IS NULL AND token_name = 'LINKVAULT_API_TOKEN' AND outcome = 'accepted'")->fetchColumn() === 2,
        'Legacy usage was recorded before scope checks or accepted requests were not recorded.'
    );
    $apiWrongMethod = $client->request('GET', '/api/shorten');
    assert_true($apiWrongMethod['status'] === 405 && header_value($apiWrongMethod, 'Allow') === 'POST', 'API wrong-method response is not standardized.');
    assert_true(str_contains($apiWrongMethod['body'], 'method_not_allowed'), 'API wrong-method response did not use the JSON error envelope.');
    $rateUsageBeforeInvalidTokens = (int)$managedPdo->query(
        'SELECT COALESCE(SUM(request_count), 0) FROM api_rate_limits'
    )->fetchColumn();
    for ($apiAttempt = 1; $apiAttempt <= 61; $apiAttempt++) {
        $rateAttempt = $client->request('POST', '/api/shorten', '{}', [
            'Content-Type: application/json',
            'Authorization: Bearer invalid-rate-test-token',
            'X-Forwarded-For: 192.0.2.60',
        ]);
        assert_true($rateAttempt['status'] === 401, "Invalid API token did not return 401 on request {$apiAttempt}.");
    }
    assert_true(
        (int)$managedPdo->query('SELECT COALESCE(SUM(request_count), 0) FROM api_rate_limits')->fetchColumn()
            === $rateUsageBeforeInvalidTokens,
        'Unauthenticated requests consumed an authenticated Token quota.'
    );

    $apiIdempotencyKey = 'smoke-key-' . bin2hex(random_bytes(8));
    $keyedPayload = json_encode([
        'url' => 'https://example.com/api-idempotent',
        'slug' => 'apiidem1',
        'tags' => ['stable', 'api'],
    ], JSON_THROW_ON_ERROR);
    $keyedHeaders = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiToken,
        'Idempotency-Key: ' . $apiIdempotencyKey,
    ];
    $keyedCreate = $client->request('POST', '/api/shorten', $keyedPayload, $keyedHeaders);
    $keyedReplay = $client->request('POST', '/api/shorten', $keyedPayload, $keyedHeaders);
    assert_true($keyedCreate['status'] === 201 && $keyedReplay['status'] === 201, 'API idempotency did not preserve the original status.');
    assert_true($keyedCreate['body'] === $keyedReplay['body'], 'API idempotency did not replay the original response body.');
    assert_true(header_value($keyedCreate, 'Idempotency-Replayed') === 'false', 'Initial keyed response was not marked as new.');
    assert_true(header_value($keyedReplay, 'Idempotency-Replayed') === 'true', 'Keyed replay was not marked as replayed.');
    assert_true((int)$managedPdo->query("SELECT COUNT(*) FROM links WHERE slug = 'apiidem1'")->fetchColumn() === 1, 'API idempotency created duplicate links.');
    assert_true((int)$managedPdo->query('SELECT COUNT(*) FROM idempotency_requests')->fetchColumn() === 1, 'API idempotency result was not stored.');
    assert_true((int)$managedPdo->query("SELECT COUNT(*) FROM idempotency_requests WHERE key_hash = '" . hash('sha256', $apiIdempotencyKey) . "'")->fetchColumn() === 1, 'The API idempotency key was not stored as a digest.');
    $keyConflict = $client->request('POST', '/api/shorten', json_encode([
        'url' => 'https://example.com/api-idempotent-conflict',
        'slug' => 'apiidem2',
    ], JSON_THROW_ON_ERROR), $keyedHeaders);
    assert_true($keyConflict['status'] === 409 && str_contains($keyConflict['body'], 'idempotency_conflict'), 'A reused API idempotency key did not return the documented conflict.');
    $expiredIdempotencyNow = time();
    $managedPdo->exec(
        "UPDATE idempotency_requests SET created_at = " . ($expiredIdempotencyNow - 2)
        . ', expires_at = ' . ($expiredIdempotencyNow - 1)
        . " WHERE key_hash = '" . hash('sha256', $apiIdempotencyKey) . "'"
    );
    $expiredKeyReuse = $client->request('POST', '/api/shorten', json_encode([
        'url' => 'https://example.com/api-idempotent-after-expiry',
        'slug' => 'apiidem2',
    ], JSON_THROW_ON_ERROR), $keyedHeaders);
    assert_true(
        $expiredKeyReuse['status'] === 201
            && header_value($expiredKeyReuse, 'Idempotency-Replayed') === 'false'
            && (int)$managedPdo->query("SELECT COUNT(*) FROM links WHERE slug = 'apiidem2'")->fetchColumn() === 1,
        'An expired API idempotency record remained effective.'
    );
    $invalidKey = $client->request('POST', '/api/shorten', $keyedPayload, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiToken,
        'Idempotency-Key: invalid key',
    ]);
    assert_true($invalidKey['status'] === 400 && str_contains($invalidKey['body'], 'invalid_idempotency_key'), 'An invalid API idempotency key was accepted.');

    $openApi = json_decode((string)file_get_contents($root . '/docs/openapi.json'), true, 64, JSON_THROW_ON_ERROR);
    assert_true(isset($openApi['paths']['/api/shorten']['post']), 'OpenAPI document does not define POST /api/shorten.');
    assert_true(
        isset($openApi['components']['schemas']['ShortenRequest']['properties']['tags']['oneOf']),
        'OpenAPI does not document both implemented tag input structures.'
    );
    assert_true(
        ($openApi['components']['schemas']['ShortenRequest']['additionalProperties'] ?? null) === false,
        'OpenAPI still permits undocumented request fields.'
    );
    assert_true(isset($openApi['paths']['/api/shorten']['post']['responses']['429']), 'OpenAPI document does not define API rate limiting.');
    $patchOperation = $openApi['paths']['/api/links/{id}']['patch'] ?? [];
    assert_true(
        array_diff(['400', '413', '429', '503'], array_keys($patchOperation['responses'] ?? [])) === [],
        'OpenAPI PATCH responses omit implemented failure statuses.'
    );
    assert_true(
        ($openApi['components']['schemas']['LinkPatch']['properties']['url']['pattern'] ?? null) === '^https?://'
            && ($openApi['components']['schemas']['LinkPatch']['properties']['fallback_url']['maxLength'] ?? null) === 2048,
        'OpenAPI PATCH URL restrictions are incomplete.'
    );
    $documentedLinkFields = array_keys($openApi['components']['schemas']['Link']['properties'] ?? []);
    assert_true(
        array_diff([
            'campaign_name', 'source', 'medium', 'content', 'invalid_message', 'fallback_url',
            'last_accessed_at',
        ], $documentedLinkFields) === [],
        'OpenAPI Link schema omits fields returned by the link API.'
    );

    $apiHeaders = [
        'Content-Type: application/json; charset=UTF-8',
        'Authorization: Bearer ' . $apiToken,
    ];
    $wrongMediaType = $client->request('POST', '/api/shorten', '{"url":"https://example.com/wrong-media"}', [
        'Content-Type: text/plain',
        'Authorization: Bearer ' . $apiToken,
    ]);
    assert_true($wrongMediaType['status'] === 415, 'API accepted a non-JSON Content-Type.');
    $nonObjectJson = $client->request('POST', '/api/shorten', '[]', $apiHeaders);
    assert_true($nonObjectJson['status'] === 400, 'API accepted a non-object JSON payload.');
    foreach ([
        ['url' => 'https://example.com/string-false', 'slug' => 'badbool1', 'favorite' => 'false'],
        ['url' => 'https://example.com/fractional-clicks', 'slug' => 'badclick1', 'max_clicks' => 1.5],
        ['url' => 'https://example.com/numeric-title', 'slug' => 'badtitle1', 'title' => 123],
        ['url' => 'https://example.com/mixed-tags', 'slug' => 'badtags1', 'tags' => ['valid', 2]],
        ['url' => 'https://example.com/unknown-field', 'slug' => 'badfield1', 'expiresAt' => gmdate('c', time() + 3600)],
        ['url' => 'https://example.com/api-password', 'slug' => 'badpass1', 'access_password' => 'not-supported'],
    ] as $invalidApiPayload) {
        $invalidApi = $client->request(
            'POST',
            '/api/shorten',
            json_encode($invalidApiPayload, JSON_THROW_ON_ERROR),
            $apiHeaders
        );
        assert_true($invalidApi['status'] === 422, 'API accepted a JSON field with the wrong type.');
    }
    $oversizedApi = $client->request('POST', '/api/shorten', json_encode([
        'url' => 'https://example.com/oversized',
        'padding' => str_repeat('x', 70 * 1024),
    ], JSON_THROW_ON_ERROR), $apiHeaders);
    assert_true($oversizedApi['status'] === 413, 'API accepted a request larger than its configured limit.');
    assert_true((int)$managedPdo->query("SELECT COUNT(*) FROM links WHERE slug LIKE 'bad%'")->fetchColumn() === 0, 'An invalid typed API request created a link.');

    $quotaToken = 'quota-token-' . bin2hex(random_bytes(16));
    $quotaTokenInsert = $managedPdo->prepare(<<<'SQL'
        INSERT INTO api_tokens (name, token_prefix, token_hash, created_at)
        VALUES ('Quota isolation test', :prefix, :token_hash, :created_at)
    SQL);
    $quotaTokenInsert->execute([
        'prefix' => substr($quotaToken, 0, 12),
        'token_hash' => hash('sha256', $quotaToken),
        'created_at' => gmdate('c'),
    ]);
    for ($quotaAttempt = 1; $quotaAttempt <= 60; $quotaAttempt++) {
        $quotaResponse = $client->request('POST', '/api/shorten', '{}', [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $quotaToken,
        ]);
        assert_true($quotaResponse['status'] === 422, "Token quota rejected allowed request {$quotaAttempt}.");
    }
    $rateLimited = $client->request('POST', '/api/shorten', '{}', [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $quotaToken,
    ]);
    assert_true($rateLimited['status'] === 429, 'Authenticated Token quota did not return 429.');
    assert_true((int)header_value($rateLimited, 'Retry-After') >= 1, 'Token quota did not return Retry-After.');
    assert_true(str_contains($rateLimited['body'], 'rate_limited'), 'Token quota did not use the JSON error envelope.');
    assert_true(str_contains((string)file_get_contents($logPath), '"event":"api_rate_limited"'), 'Token quota rejection was not written to the application log.');
    $managedPdo->exec("DELETE FROM api_tokens WHERE name = 'Quota isolation test'");
