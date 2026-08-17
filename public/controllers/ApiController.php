<?php

declare(strict_types=1);

final class ApiController
{
    public static function dispatch(
        string $method,
        string $path,
        bool $isApiRequest,
        PDO $pdo,
        array $config,
        LinkService $service,
        ApiTokenService $apiTokenService,
        string $requestId,
    ): void {
        if ($isApiRequest) {
            $requiredScope = self::requiredScope($method, $path);
            if ($requiredScope === null) {
                api_error(404, 'not_found');
            }
            $legacyToken = (string)($config['api_token'] ?? '');
            $legacyTokenValid = strlen($legacyToken) >= 24;
            $authorization = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
            $providedToken = str_starts_with($authorization, 'Bearer ') ? substr($authorization, 7) : '';
            $databaseToken = $providedToken === ''
                ? null
                : $apiTokenService->authenticate($providedToken);
            $authenticated = is_array($databaseToken) && $databaseToken['accepted'] === true;
            $grantedScopes = $authenticated ? ($databaseToken['scopes'] ?? []) : [];
            $legacyAuthenticated = false;
            if (!$authenticated && $legacyTokenValid && $providedToken !== '' && hash_equals($legacyToken, $providedToken)) {
                $authenticated = true;
                $legacyAuthenticated = true;
                $grantedScopes = ['links:create'];
            }
            if (!$authenticated) {
                if ($databaseToken === null && !$legacyTokenValid && !$apiTokenService->hasActiveToken()) {
                    api_error(503, 'api_token_not_configured');
                }
                header('WWW-Authenticate: Bearer');
                api_error(401, 'invalid_token');
            }
            $clientIp = client_ip($config);
            if (is_array($databaseToken)
                && !ApiTokenService::clientAllowed($clientIp, (string)$databaseToken['allowed_cidrs'])) {
                $apiTokenService->recordAlert((int)$databaseToken['id'], 'cidr_denied', $path, $clientIp);
                log_event($config, 'api_token_anomaly', [
                    'token_id' => (int)$databaseToken['id'],
                    'type' => 'cidr_denied',
                    'endpoint' => $path,
                    'client_ip' => $clientIp,
                ]);
                api_error(403, 'source_not_allowed');
            }
            if (!in_array($requiredScope, $grantedScopes, true)) {
                header('WWW-Authenticate: Bearer error="insufficient_scope", scope="' . $requiredScope . '"');
                log_event($config, 'api_scope_denied', [
                    'token_id' => is_array($databaseToken) ? (int)$databaseToken['id'] : null,
                    'required_scope' => $requiredScope,
                    'endpoint' => $path,
                ]);
                api_error(403, 'insufficient_scope');
            }
            $quotaIdentifier = is_array($databaseToken)
                ? 'managed:' . (int)$databaseToken['id']
                : 'legacy';
            try {
                $apiRateLimit = reserve_api_token_request(
                    $pdo,
                    $quotaIdentifier,
                    $config,
                    is_array($databaseToken) ? $databaseToken['quota_requests'] : null,
                    is_array($databaseToken) ? $databaseToken['quota_window_seconds'] : null
                );
            } catch (Throwable $exception) {
                log_event($config, 'api_rate_limit_error', [
                    'quota' => $quotaIdentifier,
                    'error' => limit_text($exception->getMessage(), 300),
                ]);
                header('Retry-After: 1');
                api_error(503, 'service_unavailable');
            }
            if (!$apiRateLimit['allowed']) {
                if (is_array($databaseToken)) {
                    $apiTokenService->recordAlert((int)$databaseToken['id'], 'rate_limited', $path, $clientIp);
                }
                header('Retry-After: ' . $apiRateLimit['retry_after_seconds']);
                log_event($config, 'api_rate_limited', [
                    'quota' => $quotaIdentifier,
                    'retry_after_seconds' => $apiRateLimit['retry_after_seconds'],
                ]);
                api_error(429, 'rate_limited');
            }
            if (is_array($databaseToken)) {
                $apiTokenService->recordManagedUsage(
                    $databaseToken,
                    $path,
                    $requestId,
                    (int)($config['api_token_failed_usage_max_records'] ?? 1000)
                );
            } elseif ($legacyAuthenticated) {
                $apiTokenService->recordLegacyUsage($path, $requestId);
            }
            if ($path === '/api/conversions') {
                self::dispatchConversionApi($pdo, $config, $databaseToken, $providedToken);
            }
            if ($path !== '/api/shorten') {
                self::dispatchLinkApi($method, $path, $pdo, $config, $service);
            }
            $hasIdempotencyKey = array_key_exists('HTTP_IDEMPOTENCY_KEY', $_SERVER);
            $idempotencyKey = $hasIdempotencyKey ? trim((string)$_SERVER['HTTP_IDEMPOTENCY_KEY']) : '';
            if ($hasIdempotencyKey
                && preg_match('/^[A-Za-z0-9][A-Za-z0-9._~:-]{0,127}$/', $idempotencyKey) !== 1) {
                api_error(400, 'invalid_idempotency_key');
            }

            $contentType = strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? ''), 2)[0]));
            if ($contentType !== 'application/json') {
                api_error(415, 'unsupported_media_type');
            }
            $maxApiBytes = max(1024, (int)($config['api_max_bytes'] ?? 64 * 1024));
            $contentLength = $_SERVER['CONTENT_LENGTH'] ?? null;
            if ((is_string($contentLength) || is_int($contentLength))
                && ctype_digit((string)$contentLength) && (int)$contentLength > $maxApiBytes) {
                api_error(413, 'request_too_large');
            }
            try {
                $rawBody = file_get_contents('php://input', false, null, 0, $maxApiBytes + 1);
                if (!is_string($rawBody)) {
                    api_error(400, 'invalid_json');
                }
                if (strlen($rawBody) > $maxApiBytes) {
                    api_error(413, 'request_too_large');
                }
                $decoded = json_decode($rawBody, false, 64, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                api_error(400, 'invalid_json');
            }
            if (!is_object($decoded)) {
                api_error(400, 'invalid_json');
            }
            $payload = get_object_vars($decoded);
            $allowedFields = [
                'url', 'title', 'slug', 'tags', 'expires_at', 'starts_at', 'max_clicks',
                'one_time', 'one_time_mode', 'favorite', 'campaign_name', 'source', 'medium',
                'content', 'force',
                'domain',
            ];
            if (array_diff(array_keys($payload), $allowedFields) !== []) {
                api_error(422, 'invalid_parameters');
            }
            $stringFieldsValid = is_string($payload['url'] ?? null);
            foreach ([
                'title', 'slug', 'expires_at', 'starts_at', 'one_time_mode',
                'campaign_name', 'source', 'medium', 'content',
                'domain',
            ] as $stringField) {
                if (array_key_exists($stringField, $payload) && !is_string($payload[$stringField])) {
                    $stringFieldsValid = false;
                }
            }
            $targetUrl = is_string($payload['url'] ?? null) ? trim($payload['url']) : '';
            $title = is_string($payload['title'] ?? null) ? trim($payload['title']) : '';
            $customSlug = is_string($payload['slug'] ?? null) ? trim($payload['slug']) : '';
            $expiresInput = is_string($payload['expires_at'] ?? null) ? $payload['expires_at'] : '';
            $startsInput = is_string($payload['starts_at'] ?? null) ? $payload['starts_at'] : '';
            $campaignName = is_string($payload['campaign_name'] ?? null) ? trim($payload['campaign_name']) : '';
            $campaignSource = is_string($payload['source'] ?? null) ? trim($payload['source']) : '';
            $campaignMedium = is_string($payload['medium'] ?? null) ? trim($payload['medium']) : '';
            $campaignContent = is_string($payload['content'] ?? null) ? trim($payload['content']) : '';
            $domainHostname = is_string($payload['domain'] ?? null) ? strtolower(rtrim(trim($payload['domain']), '.')) : '';
            $shortDomain = $domainHostname === '' ? null : self::resolveShortDomain($pdo, $domainHostname);
            $campaign = [
                'campaign_name' => $campaignName,
                'campaign_source' => $campaignSource,
                'campaign_medium' => $campaignMedium,
                'campaign_content' => $campaignContent,
            ];
            $campaignValid = !array_filter($campaign, static fn (string $value): bool => !valid_campaign_value($value));
            if (valid_target_url($targetUrl, max(1, (int)($config['target_url_max_length'] ?? 2048))) && $campaignValid) {
                $targetUrl = apply_campaign_parameters($targetUrl, $campaign);
            }
            [$expiresValid, $expiresAt] = normalize_expiration($expiresInput);
            [$startsValid, $startsAt] = normalize_expiration($startsInput);
            $tagValue = $payload['tags'] ?? '';
            if (is_array($tagValue)) {
                $tagsTypeValid = true;
                [$tagsValid, $tags] = normalize_tag_list($tagValue);
            } else {
                $tagsTypeValid = is_string($tagValue);
                [$tagsValid, $tags] = normalize_tags(is_string($tagValue) ? $tagValue : '');
            }
            $maxClicks = $payload['max_clicks'] ?? null;
            $maxClicksValid = $maxClicks === null
                || (is_int($maxClicks) && $maxClicks >= 1 && $maxClicks <= 2147483647);
            $booleanFieldsValid = true;
            foreach (['favorite', 'one_time', 'force'] as $booleanField) {
                if (array_key_exists($booleanField, $payload) && !is_bool($payload[$booleanField])) {
                    $booleanFieldsValid = false;
                }
            }
            if (!$stringFieldsValid || !$tagsTypeValid
                || !valid_target_url($targetUrl, max(1, (int)($config['target_url_max_length'] ?? 2048)))
                || text_length($title) > 120
                || ($customSlug !== '' && !valid_slug($customSlug))
                || !$expiresValid || !$startsValid || !$tagsValid || !$maxClicksValid
                || ($startsAt !== null && $expiresAt !== null && $startsAt >= $expiresAt)
                || !$booleanFieldsValid || !$campaignValid
                || ($domainHostname !== '' && $shortDomain === null)) {
                api_error(422, 'invalid_parameters');
            }
            $isFavorite = ($payload['favorite'] ?? false) === true;
            $isOneTime = ($payload['one_time'] ?? false) === true;
            $oneTimeMode = is_string($payload['one_time_mode'] ?? null) ? $payload['one_time_mode'] : 'immediate';
            $force = ($payload['force'] ?? false) === true;
            if (!in_array($oneTimeMode, ['immediate', 'confirm'], true)) {
                api_error(422, 'invalid_parameters');
            }
            if (!$isOneTime) {
                $oneTimeMode = 'immediate';
            }
            try {
                $slug = $customSlug !== '' ? $customSlug : random_slug($pdo, (int)($config['slug_length'] ?? 6));
                if ($hasIdempotencyKey) {
                    $canonicalTags = $tags;
                    sort($canonicalTags, SORT_STRING);
                    $payloadHash = hash('sha256', json_encode([
                        'url' => $targetUrl,
                        'title' => $title,
                        'slug' => $customSlug === '' ? null : $customSlug,
                        'expires_at' => $expiresAt,
                        'starts_at' => $startsAt,
                        'tags' => $canonicalTags,
                        'max_clicks' => $maxClicks === null ? null : (int)$maxClicks,
                        'favorite' => $isFavorite,
                        'one_time' => $isOneTime,
                        'one_time_mode' => $oneTimeMode,
                        'force' => $force,
                        'campaign_name' => $campaignName,
                        'source' => $campaignSource,
                        'medium' => $campaignMedium,
                        'content' => $campaignContent,
                        'domain' => $domainHostname === '' ? null : $domainHostname,
                    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
                    $result = $service->shortenApiIdempotent(
                        hash('sha256', $idempotencyKey),
                        $payloadHash,
                        max(60, (int)($config['idempotency_retention_seconds'] ?? 86400)),
                        $shortDomain === null ? base_url($config) : 'https://' . (string)$shortDomain['hostname'],
                        $slug,
                        $targetUrl,
                        $title,
                        $expiresAt,
                        $tags,
                        $isFavorite,
                        $startsAt,
                        $maxClicks === null ? null : (int)$maxClicks,
                        $isOneTime,
                        $oneTimeMode,
                        $force,
                        $campaignName,
                        $campaignSource,
                        $campaignMedium,
                        $campaignContent,
                        $shortDomain === null ? null : (int)$shortDomain['id']
                    );
                    header('Idempotency-Replayed: ' . ($result['replayed'] ? 'true' : 'false'));
                    $resultPayload = json_decode($result['body'], true);
                    audit_event($pdo, $config, 'api', $result['replayed'] ? 'api_create_replayed' : 'api_create', 'success', 'link',
                        is_array($resultPayload) ? (string)($resultPayload['id'] ?? '') : null,
                        ['status' => $result['status']]);
                    json_response_raw($result['status'], $result['body']);
                }

                $duplicates = $service->findDuplicates(
                    $targetUrl,
                    1,
                    $shortDomain === null ? null : (int)$shortDomain['id']
                );
                if ($duplicates && !$force) {
                    $existing = $duplicates[0];
                    audit_event($pdo, $config, 'api', 'api_duplicate_reused', 'success', 'link', (string)$existing['id']);
                    json_response(200, [
                        'short_url' => ($shortDomain === null ? base_url($config) : 'https://' . (string)$shortDomain['hostname']) . '/' . rawurlencode((string)$existing['slug']),
                        'id' => (int)$existing['id'],
                        'duplicate' => true,
                    ]);
                }
                $id = $service->create(
                    $slug,
                    $targetUrl,
                    $title,
                    $expiresAt,
                    $tags,
                    $isFavorite,
                    $startsAt,
                    $maxClicks === null ? null : (int)$maxClicks,
                    $isOneTime,
                    $oneTimeMode,
                    $campaignName,
                    $campaignSource,
                    $campaignMedium,
                    $campaignContent,
                    null,
                    '',
                    null,
                    $shortDomain === null ? null : (int)$shortDomain['id']
                );
                audit_event($pdo, $config, 'api', 'api_create', 'success', 'link', (string)$id);
                json_response(201, [
                    'short_url' => ($shortDomain === null ? base_url($config) : 'https://' . (string)$shortDomain['hostname']) . '/' . rawurlencode($slug),
                    'id' => $id,
                    'duplicate' => false,
                ]);
            } catch (IdempotencyConflict) {
                api_error(409, 'idempotency_conflict');
            } catch (PDOException $exception) {
                if (is_slug_unique_violation($exception)) {
                    api_error(409, 'slug_exists');
                }
                throw $exception;
            }
        }
    }

    private static function requiredScope(string $method, string $path): ?string
    {
        if ($method === 'POST' && $path === '/api/shorten') {
            return 'links:create';
        }
        if ($method === 'POST' && $path === '/api/conversions') {
            return 'conversions:write';
        }
        if ($method === 'GET' && ($path === '/api/links' || preg_match('#^/api/links/[1-9][0-9]*$#', $path) === 1)) {
            return 'links:read';
        }
        if ($method === 'PATCH' && preg_match('#^/api/links/[1-9][0-9]*$#', $path) === 1) {
            return 'links:write';
        }
        if ($method === 'POST' && preg_match('#^/api/links/[1-9][0-9]*/disable$#', $path) === 1) {
            return 'links:write';
        }
        if ($method === 'DELETE' && preg_match('#^/api/links/[1-9][0-9]*$#', $path) === 1) {
            return 'links:delete';
        }
        return null;
    }

    private static function dispatchConversionApi(
        PDO $pdo,
        array $config,
        ?array $databaseToken,
        string $providedToken
    ): never {
        if (!is_array($databaseToken) || (int)($databaseToken['id'] ?? 0) < 1) {
            api_error(403, 'insufficient_scope');
        }
        $idempotencyKey = trim((string)($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ''));
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._~:-]{0,127}$/D', $idempotencyKey) !== 1) {
            api_error(400, 'invalid_idempotency_key');
        }
        $contentType = strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? ''), 2)[0]));
        if ($contentType !== 'application/json') {
            api_error(415, 'unsupported_media_type');
        }
        $maxBytes = max(1024, (int)($config['api_max_bytes'] ?? 64 * 1024));
        $rawBody = file_get_contents('php://input', false, null, 0, $maxBytes + 1);
        if (!is_string($rawBody) || strlen($rawBody) > $maxBytes) {
            api_error(413, 'request_too_large');
        }
        $timestamp = trim((string)($_SERVER['HTTP_X_LINKVAULT_TIMESTAMP'] ?? ''));
        $signature = trim((string)($_SERVER['HTTP_X_LINKVAULT_SIGNATURE'] ?? ''));
        if (!P2Service::validConversionSignature(
            $providedToken,
            $timestamp,
            $idempotencyKey,
            $rawBody,
            $signature,
            max(30, (int)($config['conversion_signature_tolerance_seconds'] ?? 300))
        )) {
            api_error(401, 'signature_required');
        }
        try {
            $decoded = json_decode($rawBody, true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            api_error(400, 'invalid_json');
        }
        if (!is_array($decoded) || array_is_list($decoded)
            || array_diff(array_keys($decoded), [
                'event_id', 'event', 'link_id', 'occurred_at', 'value_minor', 'currency', 'metadata',
            ]) !== []) {
            api_error(422, 'invalid_parameters');
        }
        try {
            $result = (new P2Service($pdo, $config))->recordConversion((int)$databaseToken['id'], $idempotencyKey, $decoded);
            header('Idempotency-Replayed: ' . ($result['replayed'] ? 'true' : 'false'));
            json_response($result['replayed'] ? 200 : 201, [
                'id' => $result['id'],
                'event_id' => $result['event_id'],
            ]);
        } catch (ConversionIdempotencyConflict) {
            api_error(409, 'idempotency_conflict');
        } catch (InvalidArgumentException) {
            api_error(422, 'invalid_parameters');
        }
    }

    private static function resolveShortDomain(PDO $pdo, string $hostname): ?array
    {
        if ($hostname === '' || strlen($hostname) > 253 || filter_var($hostname, FILTER_VALIDATE_IP)
            || !filter_var($hostname, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            return null;
        }
        $statement = $pdo->prepare(<<<'SQL'
            SELECT id, hostname FROM short_domains
            WHERE hostname = :hostname AND verified_at IS NOT NULL AND is_enabled = 1
        SQL);
        $statement->execute(['hostname' => $hostname]);
        $domain = $statement->fetch();
        return $domain ?: null;
    }

    private static function dispatchLinkApi(
        string $method,
        string $path,
        PDO $pdo,
        array $config,
        LinkService $service
    ): never {
        if ($method === 'GET' && $path === '/api/links') {
            $allowedQuery = ['page', 'per_page', 'q', 'status', 'tag', 'favorite'];
            if (array_diff(array_keys($_GET), $allowedQuery) !== []) {
                api_error(422, 'invalid_parameters');
            }
            $pageValue = (string)($_GET['page'] ?? '1');
            $perPageValue = (string)($_GET['per_page'] ?? '50');
            if (!ctype_digit($pageValue) || !ctype_digit($perPageValue)) {
                api_error(422, 'invalid_parameters');
            }
            $page = (int)$pageValue;
            $perPage = (int)$perPageValue;
            $status = (string)($_GET['status'] ?? 'all');
            $favorite = (string)($_GET['favorite'] ?? '0');
            if ($page < 1 || $perPage < 1 || $perPage > 100
                || !in_array($status, ['all', 'active', 'inactive', 'scheduled', 'expired', 'exhausted'], true)
                || !in_array($favorite, ['0', '1'], true)) {
                api_error(422, 'invalid_parameters');
            }
            $search = limit_text(trim((string)($_GET['q'] ?? '')), 200);
            $tag = limit_text(trim((string)($_GET['tag'] ?? '')), 24);
            $result = $service->listForAdmin(
                'active',
                $search,
                $page,
                $perPage,
                $status,
                'created_desc',
                $tag,
                $favorite === '1'
            );
            $links = array_map(
                static fn (array $link): array => self::serializeLink($link, $config),
                $result['links']
            );
            json_response(200, [
                'data' => $links,
                'pagination' => [
                    'page' => (int)$result['page'],
                    'per_page' => $perPage,
                    'total' => (int)$result['total'],
                    'total_pages' => max(1, (int)ceil((int)$result['total'] / $perPage)),
                ],
            ]);
        }

        $isDisable = preg_match('#^/api/links/([1-9][0-9]*)/disable$#', $path, $matches) === 1;
        if (!$isDisable && preg_match('#^/api/links/([1-9][0-9]*)$#', $path, $matches) !== 1) {
            api_error(404, 'not_found');
        }
        $id = (int)$matches[1];
        $link = $service->getAdminLink($id);
        if (!is_array($link) || !empty($link['deleted_at'])) {
            api_error(404, 'not_found');
        }

        if ($method === 'GET') {
            header('ETag: ' . self::linkEtag($link));
            json_response(200, ['data' => self::serializeLink($link, $config)]);
        }

        self::requireMatchingEtag($link);
        if ($isDisable) {
            if ((int)$link['is_active'] === 1
                && !$service->toggle($id, false, (string)$link['updated_at'])) {
                api_error(412, 'precondition_failed');
            }
            $after = $service->getAdminLink($id);
            audit_event($pdo, $config, 'api', 'api_disable', 'success', 'link', (string)$id, [
                'before' => audit_link_state($link),
                'after' => audit_link_state(is_array($after) ? $after : $link),
            ]);
            self::noContent(is_array($after) ? self::linkEtag($after) : null);
        }

        if ($method === 'DELETE') {
            if (!$service->softDelete($id, (string)$link['updated_at'])) {
                api_error(412, 'precondition_failed');
            }
            $after = $service->getAdminLink($id);
            audit_event($pdo, $config, 'api', 'api_delete', 'success', 'link', (string)$id, [
                'before' => audit_link_state($link),
                'after' => audit_link_state(is_array($after) ? $after : $link),
            ]);
            self::noContent();
        }

        if ((int)($link['access_password_reset_required'] ?? 0) === 1) {
            api_error(409, 'link_requires_password_reset');
        }
        $payload = self::readJsonObject($config);
        $allowedFields = [
            'url', 'title', 'tags', 'expires_at', 'starts_at', 'max_clicks', 'favorite',
            'one_time', 'one_time_mode', 'campaign_name', 'source', 'medium', 'content',
            'invalid_message', 'fallback_url',
        ];
        if (!$payload || array_diff(array_keys($payload), $allowedFields) !== []) {
            api_error(422, 'invalid_parameters');
        }

        $targetUrl = array_key_exists('url', $payload) && is_string($payload['url'])
            ? trim($payload['url']) : (string)$link['target_url'];
        $title = array_key_exists('title', $payload) && is_string($payload['title'])
            ? trim($payload['title']) : (string)$link['title'];
        $tags = split_stored_tags((string)$link['tags']);
        $tagsTypeValid = true;
        $tagsValid = true;
        if (array_key_exists('tags', $payload)) {
            if (is_array($payload['tags'])) {
                [$tagsValid, $tags] = normalize_tag_list($payload['tags']);
            } elseif (is_string($payload['tags'])) {
                [$tagsValid, $tags] = normalize_tags($payload['tags']);
            } else {
                $tagsTypeValid = false;
            }
        }
        [$expiresValid, $expiresAt] = self::patchExpiration($payload, 'expires_at', $link['expires_at']);
        [$startsValid, $startsAt] = self::patchExpiration($payload, 'starts_at', $link['starts_at']);
        $maxClicks = array_key_exists('max_clicks', $payload) ? $payload['max_clicks'] : $link['max_clicks'];
        $maxClicksValid = $maxClicks === null || (is_int($maxClicks) && $maxClicks >= 1 && $maxClicks <= 2147483647);
        $isFavorite = array_key_exists('favorite', $payload) && is_bool($payload['favorite'])
            ? $payload['favorite'] : (int)$link['is_favorite'] === 1;
        $isOneTime = array_key_exists('one_time', $payload) && is_bool($payload['one_time'])
            ? $payload['one_time'] : (int)$link['is_one_time'] === 1;
        $oneTimeMode = array_key_exists('one_time_mode', $payload) && is_string($payload['one_time_mode'])
            ? $payload['one_time_mode'] : (string)$link['one_time_mode'];
        if (!$isOneTime) {
            $oneTimeMode = 'immediate';
        }
        $campaignName = self::patchString($payload, 'campaign_name', (string)$link['campaign_name']);
        $campaignSource = self::patchString($payload, 'source', (string)$link['campaign_source']);
        $campaignMedium = self::patchString($payload, 'medium', (string)$link['campaign_medium']);
        $campaignContent = self::patchString($payload, 'content', (string)$link['campaign_content']);
        $campaign = [
            'campaign_name' => $campaignName,
            'campaign_source' => $campaignSource,
            'campaign_medium' => $campaignMedium,
            'campaign_content' => $campaignContent,
        ];
        $campaignValid = !array_filter($campaign, static fn (string $value): bool => !valid_campaign_value($value));
        $hadCampaign = array_filter([
            (string)$link['campaign_name'], (string)$link['campaign_source'],
            (string)$link['campaign_medium'], (string)$link['campaign_content'],
        ]) !== [];
        if (valid_target_url($targetUrl, max(1, (int)($config['target_url_max_length'] ?? 2048))) && $campaignValid) {
            $targetUrl = apply_campaign_parameters($targetUrl, $campaign, $hadCampaign);
        }
        $invalidMessage = self::patchString($payload, 'invalid_message', (string)($link['invalid_message'] ?? ''));
        $fallbackUrl = array_key_exists('fallback_url', $payload)
            ? (is_string($payload['fallback_url']) ? trim($payload['fallback_url']) : $payload['fallback_url'])
            : $link['fallback_url'];
        $stringsValid = (!array_key_exists('url', $payload) || is_string($payload['url']))
            && (!array_key_exists('title', $payload) || is_string($payload['title']))
            && (!array_key_exists('one_time_mode', $payload) || is_string($payload['one_time_mode']))
            && (!array_key_exists('fallback_url', $payload) || $fallbackUrl === null || is_string($fallbackUrl));
        foreach (['campaign_name', 'source', 'medium', 'content', 'invalid_message'] as $field) {
            $stringsValid = $stringsValid && (!array_key_exists($field, $payload) || is_string($payload[$field]));
        }
        $booleansValid = (!array_key_exists('favorite', $payload) || is_bool($payload['favorite']))
            && (!array_key_exists('one_time', $payload) || is_bool($payload['one_time']));
        if (!$stringsValid || !$booleansValid || !$tagsTypeValid || !$tagsValid
            || !valid_target_url($targetUrl, max(1, (int)($config['target_url_max_length'] ?? 2048)))
            || text_length($title) > 120 || !$expiresValid || !$startsValid
            || ($startsAt !== null && $expiresAt !== null && $startsAt >= $expiresAt)
            || !$maxClicksValid || !in_array($oneTimeMode, ['immediate', 'confirm'], true)
            || !$campaignValid || !valid_invalid_message($invalidMessage)
            || ($fallbackUrl !== null && !valid_target_url((string)$fallbackUrl, max(1, (int)($config['target_url_max_length'] ?? 2048))))) {
            api_error(422, 'invalid_parameters');
        }

        $updated = $service->update(
            $id,
            $targetUrl,
            $title,
            $expiresAt,
            (string)$link['updated_at'],
            $tags,
            $isFavorite,
            $startsAt,
            $maxClicks === null ? null : (int)$maxClicks,
            $isOneTime,
            $oneTimeMode,
            $campaignName,
            $campaignSource,
            $campaignMedium,
            $campaignContent,
            null,
            false,
            $invalidMessage,
            $fallbackUrl === null ? null : (string)$fallbackUrl
        );
        if (!$updated) {
            api_error(412, 'precondition_failed');
        }
        $after = $service->getAdminLink($id);
        audit_event($pdo, $config, 'api', 'api_update', 'success', 'link', (string)$id, [
            'before' => audit_link_state($link),
            'after' => audit_link_state(is_array($after) ? $after : $link),
        ]);
        self::noContent(is_array($after) ? self::linkEtag($after) : null);
    }

    private static function readJsonObject(array $config): array
    {
        $contentType = strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? ''), 2)[0]));
        if ($contentType !== 'application/json') {
            api_error(415, 'unsupported_media_type');
        }
        $maxBytes = max(1024, (int)($config['api_max_bytes'] ?? 64 * 1024));
        $contentLength = (string)($_SERVER['CONTENT_LENGTH'] ?? '');
        if ($contentLength !== '' && ctype_digit($contentLength) && (int)$contentLength > $maxBytes) {
            api_error(413, 'request_too_large');
        }
        try {
            $raw = file_get_contents('php://input', false, null, 0, $maxBytes + 1);
            if (!is_string($raw) || strlen($raw) > $maxBytes) {
                api_error(413, 'request_too_large');
            }
            $decoded = json_decode($raw, false, 64, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            api_error(400, 'invalid_json');
        }
        if (!is_object($decoded)) {
            api_error(400, 'invalid_json');
        }
        return get_object_vars($decoded);
    }

    private static function patchExpiration(array $payload, string $field, mixed $current): array
    {
        if (!array_key_exists($field, $payload)) {
            return [true, is_string($current) && $current !== '' ? $current : null];
        }
        if ($payload[$field] === null) {
            return [true, null];
        }
        if (!is_string($payload[$field])) {
            return [false, null];
        }
        return normalize_expiration($payload[$field]);
    }

    private static function patchString(array $payload, string $field, string $current): string
    {
        return array_key_exists($field, $payload) && is_string($payload[$field])
            ? trim($payload[$field]) : $current;
    }

    private static function requireMatchingEtag(array $link): void
    {
        $provided = trim((string)($_SERVER['HTTP_IF_MATCH'] ?? ''));
        if ($provided === '') {
            api_error(428, 'precondition_required');
        }
        if (!hash_equals(self::linkEtag($link), $provided)) {
            api_error(412, 'precondition_failed');
        }
    }

    private static function linkEtag(array $link): string
    {
        return '"' . hash('sha256', (int)$link['id'] . ':' . (string)$link['updated_at']) . '"';
    }

    private static function serializeLink(array $link, array $config): array
    {
        $tags = is_array($link['tags'] ?? null)
            ? array_values($link['tags'])
            : split_stored_tags((string)($link['tags'] ?? ''));
        return [
            'id' => (int)$link['id'],
            'slug' => (string)$link['slug'],
            'short_url' => short_url_base($config, $link) . '/' . rawurlencode((string)$link['slug']),
            'domain' => ($link['short_domain_hostname'] ?? null) ?: null,
            'url' => (string)$link['target_url'],
            'title' => (string)$link['title'],
            'status' => link_status_key($link),
            'active' => (int)$link['is_active'] === 1,
            'favorite' => (int)$link['is_favorite'] === 1,
            'clicks' => (int)$link['clicks'],
            'tags' => $tags,
            'expires_at' => $link['expires_at'],
            'starts_at' => $link['starts_at'],
            'max_clicks' => $link['max_clicks'] === null ? null : (int)$link['max_clicks'],
            'one_time' => (int)$link['is_one_time'] === 1,
            'one_time_mode' => (string)$link['one_time_mode'],
            'campaign_name' => (string)$link['campaign_name'],
            'source' => (string)$link['campaign_source'],
            'medium' => (string)$link['campaign_medium'],
            'content' => (string)$link['campaign_content'],
            'invalid_message' => (string)($link['invalid_message'] ?? ''),
            'fallback_url' => $link['fallback_url'] ?? null,
            'password_protected' => !empty($link['access_password_hash'])
                || (int)($link['access_password_reset_required'] ?? 0) === 1,
            'password_reset_required' => (int)($link['access_password_reset_required'] ?? 0) === 1,
            'created_at' => (string)$link['created_at'],
            'updated_at' => (string)$link['updated_at'],
            'last_accessed_at' => $link['last_accessed_at'],
            'target_health_state' => $link['target_health_state'] ?? null,
            'target_health_reason' => $link['target_health_reason'] ?? null,
            'target_health_checked_at' => $link['target_health_checked_at'] ?? null,
            'etag' => self::linkEtag($link),
        ];
    }

    private static function noContent(?string $etag = null): never
    {
        http_response_code(204);
        header('Cache-Control: no-store');
        if ($etag !== null) {
            header('ETag: ' . $etag);
        }
        exit;
    }
}
