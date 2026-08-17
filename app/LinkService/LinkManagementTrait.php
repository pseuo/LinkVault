<?php

declare(strict_types=1);

trait LinkManagementTrait
{
    public function create(
        string $slug,
        string $targetUrl,
        string $title,
        ?string $expiresAt,
        array $tags = [],
        bool $isFavorite = false,
        ?string $startsAt = null,
        ?int $maxClicks = null,
        bool $isOneTime = false,
        string $oneTimeMode = 'immediate',
        string $campaignName = '',
        string $campaignSource = '',
        string $campaignMedium = '',
        string $campaignContent = '',
        ?string $accessPasswordHash = null,
        string $invalidMessage = '',
        ?string $fallbackUrl = null,
        ?int $shortDomainId = null
    ): int {
        $result = $this->createInternal(
            $slug,
            $targetUrl,
            $title,
            $expiresAt,
            $tags,
            $isFavorite,
            $startsAt,
            $maxClicks,
            $isOneTime,
            $oneTimeMode,
            $campaignName,
            $campaignSource,
            $campaignMedium,
            $campaignContent,
            $accessPasswordHash,
            $invalidMessage,
            $fallbackUrl,
            null,
            null,
            $shortDomainId
        );
        return $result['id'];
    }

    /** @return array{id: int, slug: string, replayed: bool} */

    public function createIdempotent(
        string $requestId,
        string $payloadHash,
        string $slug,
        string $targetUrl,
        string $title,
        ?string $expiresAt,
        array $tags = [],
        bool $isFavorite = false,
        ?string $startsAt = null,
        ?int $maxClicks = null,
        bool $isOneTime = false,
        string $oneTimeMode = 'immediate',
        string $campaignName = '',
        string $campaignSource = '',
        string $campaignMedium = '',
        string $campaignContent = '',
        ?string $accessPasswordHash = null,
        string $invalidMessage = '',
        ?string $fallbackUrl = null,
        ?int $shortDomainId = null
    ): array {
        if (preg_match('/^[a-f0-9]{32}$/', $requestId) !== 1
            || preg_match('/^[a-f0-9]{64}$/', $payloadHash) !== 1) {
            throw new InvalidArgumentException('Invalid create request identifier.');
        }
        return $this->createInternal(
            $slug,
            $targetUrl,
            $title,
            $expiresAt,
            $tags,
            $isFavorite,
            $startsAt,
            $maxClicks,
            $isOneTime,
            $oneTimeMode,
            $campaignName,
            $campaignSource,
            $campaignMedium,
            $campaignContent,
            $accessPasswordHash,
            $invalidMessage,
            $fallbackUrl,
            $requestId,
            $payloadHash,
            $shortDomainId
        );
    }

    /** @return array{status: int, body: string, replayed: bool} */

    public function shortenApiIdempotent(
        string $keyHash,
        string $payloadHash,
        int $retentionSeconds,
        string $shortBaseUrl,
        string $slug,
        string $targetUrl,
        string $title,
        ?string $expiresAt,
        array $tags,
        bool $isFavorite,
        ?string $startsAt,
        ?int $maxClicks,
        bool $isOneTime,
        string $oneTimeMode,
        bool $force,
        string $campaignName = '',
        string $campaignSource = '',
        string $campaignMedium = '',
        string $campaignContent = '',
        ?int $shortDomainId = null
    ): array {
        if (preg_match('/^[a-f0-9]{64}$/', $keyHash) !== 1
            || preg_match('/^[a-f0-9]{64}$/', $payloadHash) !== 1) {
            throw new InvalidArgumentException('Invalid idempotency digest.');
        }

        return with_sqlite_retry(function () use (
            $keyHash,
            $payloadHash,
            $retentionSeconds,
            $shortBaseUrl,
            $slug,
            $targetUrl,
            $title,
            $expiresAt,
            $tags,
            $isFavorite,
            $startsAt,
            $maxClicks,
            $isOneTime,
            $oneTimeMode,
            $force,
            $campaignName,
            $campaignSource,
            $campaignMedium,
            $campaignContent,
            $shortDomainId
        ): array {
            $operation = 'shorten:v1';
            $nowEpoch = time();
            $this->pdo->exec('BEGIN IMMEDIATE');
            try {
                $lookup = $this->pdo->prepare(<<<'SQL'
                    SELECT payload_hash, response_status, response_body, expires_at
                    FROM idempotency_requests
                    WHERE operation = :operation AND key_hash = :key_hash
                SQL);
                $lookup->execute(['operation' => $operation, 'key_hash' => $keyHash]);
                $existingRequest = $lookup->fetch();
                if ($existingRequest && (int)$existingRequest['expires_at'] <= $nowEpoch) {
                    $deleteExpired = $this->pdo->prepare(<<<'SQL'
                        DELETE FROM idempotency_requests
                        WHERE operation = :operation AND key_hash = :key_hash AND expires_at <= :now
                    SQL);
                    $deleteExpired->execute([
                        'operation' => $operation,
                        'key_hash' => $keyHash,
                        'now' => $nowEpoch,
                    ]);
                    $existingRequest = false;
                }
                if ($existingRequest) {
                    if (!hash_equals((string)$existingRequest['payload_hash'], $payloadHash)) {
                        throw new IdempotencyConflict('The idempotency key was reused with different input.');
                    }
                    $this->pdo->commit();
                    return [
                        'status' => (int)$existingRequest['response_status'],
                        'body' => (string)$existingRequest['response_body'],
                        'replayed' => true,
                    ];
                }

                $duplicates = $this->findDuplicates($targetUrl, 1, $shortDomainId);
                if ($duplicates && !$force) {
                    $linkId = (int)$duplicates[0]['id'];
                    $responseSlug = (string)$duplicates[0]['slug'];
                    $status = 200;
                    $duplicate = true;
                } else {
                    $now = utc_timestamp();
                    $statement = $this->pdo->prepare(<<<'SQL'
                        INSERT INTO links (
                            slug, target_url, title, expires_at, is_favorite, starts_at,
                            max_clicks, is_one_time, one_time_mode, campaign_name, campaign_source,
                            campaign_medium, campaign_content, short_domain_id, created_at, updated_at
                        ) VALUES (
                            :slug, :target_url, :title, :expires_at, :is_favorite, :starts_at,
                            :max_clicks, :is_one_time, :one_time_mode, :campaign_name, :campaign_source,
                            :campaign_medium, :campaign_content, :short_domain_id, :created_at, :updated_at
                        )
                    SQL);
                    $statement->execute([
                        'slug' => $slug,
                        'target_url' => $targetUrl,
                        'title' => limit_text($title, 120),
                        'expires_at' => $expiresAt,
                        'is_favorite' => $isFavorite ? 1 : 0,
                        'starts_at' => $startsAt,
                        'max_clicks' => $maxClicks,
                        'is_one_time' => $isOneTime ? 1 : 0,
                        'one_time_mode' => $isOneTime && $oneTimeMode === 'confirm' ? 'confirm' : 'immediate',
                        'campaign_name' => limit_text($campaignName, 100),
                        'campaign_source' => limit_text($campaignSource, 100),
                        'campaign_medium' => limit_text($campaignMedium, 100),
                        'campaign_content' => limit_text($campaignContent, 100),
                        'short_domain_id' => $shortDomainId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $linkId = (int)$this->pdo->lastInsertId();
                    $responseSlug = $slug;
                    $this->replaceTags($linkId, $tags);
                    $created = $this->findById($linkId);
                    $this->addHistory($linkId, 'created', null, link_status_key($created ?: []), $now);
                    $this->enqueueLifecycle('link.created', $linkId, 'link.created:' . $linkId);
                    $status = 201;
                    $duplicate = false;
                }

                $body = json_encode([
                    'short_url' => rtrim($shortBaseUrl, '/') . '/' . rawurlencode($responseSlug),
                    'id' => $linkId,
                    'duplicate' => $duplicate,
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                $store = $this->pdo->prepare(<<<'SQL'
                    INSERT INTO idempotency_requests (
                        operation, key_hash, payload_hash, response_status, response_body, created_at, expires_at
                    ) VALUES (
                        :operation, :key_hash, :payload_hash, :response_status, :response_body, :created_at, :expires_at
                    )
                SQL);
                $store->execute([
                    'operation' => $operation,
                    'key_hash' => $keyHash,
                    'payload_hash' => $payloadHash,
                    'response_status' => $status,
                    'response_body' => $body,
                    'created_at' => $nowEpoch,
                    'expires_at' => $nowEpoch + max(60, $retentionSeconds),
                ]);
                $this->pdo->commit();
                return ['status' => $status, 'body' => $body, 'replayed' => false];
            } catch (Throwable $exception) {
                $this->rollback();
                throw $exception;
            }
        });
    }

    /** @return array{id: int, slug: string, replayed: bool} */

    private function createInternal(
        string $slug,
        string $targetUrl,
        string $title,
        ?string $expiresAt,
        array $tags,
        bool $isFavorite,
        ?string $startsAt,
        ?int $maxClicks,
        bool $isOneTime,
        string $oneTimeMode,
        string $campaignName,
        string $campaignSource,
        string $campaignMedium,
        string $campaignContent,
        ?string $accessPasswordHash,
        string $invalidMessage,
        ?string $fallbackUrl,
        ?string $requestId,
        ?string $payloadHash,
        ?int $shortDomainId
    ): array {
        return with_sqlite_retry(function () use (
            $slug,
            $targetUrl,
            $title,
            $expiresAt,
            $tags,
            $isFavorite,
            $startsAt,
            $maxClicks,
            $isOneTime,
            $oneTimeMode,
            $campaignName,
            $campaignSource,
            $campaignMedium,
            $campaignContent,
            $accessPasswordHash,
            $invalidMessage,
            $fallbackUrl,
            $requestId,
            $payloadHash,
            $shortDomainId
        ): array {
            $now = utc_timestamp();
            if ($requestId !== null) {
                $this->pdo->exec('BEGIN IMMEDIATE');
            } else {
                $this->pdo->beginTransaction();
            }
            try {
                if ($requestId !== null) {
                    $existing = $this->pdo->prepare(<<<'SQL'
                        SELECT r.payload_hash, l.id, l.slug
                        FROM create_requests r
                        INNER JOIN links l ON l.id = r.link_id
                        WHERE r.request_id = :request_id
                    SQL);
                    $existing->execute(['request_id' => $requestId]);
                    $previous = $existing->fetch();
                    if ($previous) {
                        if (!hash_equals((string)$previous['payload_hash'], (string)$payloadHash)) {
                            throw new IdempotencyConflict('The create request identifier was reused with different input.');
                        }
                        $this->pdo->commit();
                        return [
                            'id' => (int)$previous['id'],
                            'slug' => (string)$previous['slug'],
                            'replayed' => true,
                        ];
                    }
                }
                $statement = $this->pdo->prepare(<<<'SQL'
                    INSERT INTO links (
                        slug, target_url, title, expires_at, is_favorite, starts_at,
                        max_clicks, is_one_time, one_time_mode, campaign_name, campaign_source,
                        campaign_medium, campaign_content, access_password_hash, invalid_message,
                        fallback_url, short_domain_id, created_at, updated_at
                    ) VALUES (
                        :slug, :target_url, :title, :expires_at, :is_favorite, :starts_at,
                        :max_clicks, :is_one_time, :one_time_mode, :campaign_name, :campaign_source,
                        :campaign_medium, :campaign_content, :access_password_hash, :invalid_message,
                        :fallback_url, :short_domain_id, :created_at, :updated_at
                    )
                SQL);
                $statement->execute([
                    'slug' => $slug,
                    'target_url' => $targetUrl,
                    'title' => limit_text($title, 120),
                    'expires_at' => $expiresAt,
                    'is_favorite' => $isFavorite ? 1 : 0,
                    'starts_at' => $startsAt,
                    'max_clicks' => $maxClicks,
                    'is_one_time' => $isOneTime ? 1 : 0,
                    'one_time_mode' => $isOneTime && $oneTimeMode === 'confirm' ? 'confirm' : 'immediate',
                    'campaign_name' => limit_text($campaignName, 100),
                    'campaign_source' => limit_text($campaignSource, 100),
                    'campaign_medium' => limit_text($campaignMedium, 100),
                    'campaign_content' => limit_text($campaignContent, 100),
                    'access_password_hash' => $accessPasswordHash,
                    'invalid_message' => $invalidMessage,
                    'fallback_url' => $fallbackUrl,
                    'short_domain_id' => $shortDomainId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $id = (int)$this->pdo->lastInsertId();
                $this->replaceTags($id, $tags);
                $created = $this->findById($id);
                $this->addHistory($id, 'created', null, link_status_key($created ?: []), $now);
                $this->enqueueLifecycle('link.created', $id, 'link.created:' . $id);
                if ($requestId !== null) {
                    $request = $this->pdo->prepare(<<<'SQL'
                        INSERT INTO create_requests (request_id, payload_hash, link_id, created_at)
                        VALUES (:request_id, :payload_hash, :link_id, :created_at)
                    SQL);
                    $request->execute([
                        'request_id' => $requestId,
                        'payload_hash' => $payloadHash,
                        'link_id' => $id,
                        'created_at' => $now,
                    ]);
                }
                $this->pdo->commit();
                return ['id' => $id, 'slug' => $slug, 'replayed' => false];
            } catch (Throwable $exception) {
                $this->rollback();
                throw $exception;
            }
        });
    }

    public function update(
        int $id,
        string $targetUrl,
        string $title,
        ?string $expiresAt,
        string $expectedUpdatedAt,
        array $tags = [],
        bool $isFavorite = false,
        ?string $startsAt = null,
        ?int $maxClicks = null,
        bool $isOneTime = false,
        string $oneTimeMode = 'immediate',
        string $campaignName = '',
        string $campaignSource = '',
        string $campaignMedium = '',
        string $campaignContent = '',
        ?string $accessPasswordHash = null,
        bool $removeAccessPassword = false,
        string $invalidMessage = '',
        ?string $fallbackUrl = null,
        ?string $slug = null,
        bool $preserveOldSlug = true
    ): bool {
        return (bool)with_sqlite_retry(function () use (
            $id,
            $targetUrl,
            $title,
            $expiresAt,
            $expectedUpdatedAt,
            $tags,
            $isFavorite,
            $startsAt,
            $maxClicks,
            $isOneTime,
            $oneTimeMode,
            $campaignName,
            $campaignSource,
            $campaignMedium,
            $campaignContent,
            $accessPasswordHash,
            $removeAccessPassword,
            $invalidMessage,
            $fallbackUrl,
            $slug,
            $preserveOldSlug
        ): bool {
            $this->pdo->beginTransaction();
            try {
                $before = $this->findById($id);
                if (!$before || !empty($before['deleted_at']) || (string)$before['updated_at'] !== $expectedUpdatedAt) {
                    $this->pdo->rollBack();
                    return false;
                }
                if ((int)($before['access_password_reset_required'] ?? 0) === 1
                    && $accessPasswordHash === null) {
                    $this->pdo->rollBack();
                    return false;
                }
                $nextSlug = $slug === null ? (string)$before['slug'] : trim($slug);
                if (!valid_slug($nextSlug)) {
                    throw new InvalidArgumentException('Invalid short code.');
                }
                $slugChanged = !hash_equals((string)$before['slug'], $nextSlug);
                if ($slugChanged) {
                    $collision = $this->pdo->prepare(<<<'SQL'
                        SELECT 1 FROM links WHERE slug = :slug AND id <> :id
                        UNION ALL
                        SELECT 1 FROM link_aliases WHERE alias = :slug AND link_id <> :id
                        LIMIT 1
                    SQL);
                    $collision->execute(['slug' => $nextSlug, 'id' => $id]);
                    if ($collision->fetchColumn()) {
                        throw new InvalidArgumentException('Short code is already in use.');
                    }
                    $releaseOwnAlias = $this->pdo->prepare(
                        'DELETE FROM link_aliases WHERE alias = :alias AND link_id = :link_id'
                    );
                    $releaseOwnAlias->execute(['alias' => $nextSlug, 'link_id' => $id]);
                }
                $beforeStatus = link_status_key($before);
                $now = utc_timestamp();
                $passwordAssignment = $removeAccessPassword
                    ? 'access_password_hash = NULL, access_password_reset_required = 0,'
                    : ($accessPasswordHash !== null
                        ? 'access_password_hash = :access_password_hash, access_password_reset_required = 0,'
                        : '');
                $statement = $this->pdo->prepare(<<<SQL
                    UPDATE links
                    SET slug = :slug,
                        target_url = :target_url,
                        title = :title,
                        expires_at = :expires_at,
                        is_favorite = :is_favorite,
                        starts_at = :starts_at,
                        max_clicks = :max_clicks,
                        is_one_time = :is_one_time,
                        one_time_mode = :one_time_mode,
                        campaign_name = :campaign_name,
                        campaign_source = :campaign_source,
                        campaign_medium = :campaign_medium,
                        campaign_content = :campaign_content,
                        {$passwordAssignment}
                        invalid_message = :invalid_message,
                        fallback_url = :fallback_url,
                        updated_at = :updated_at
                    WHERE id = :id AND deleted_at IS NULL AND updated_at = :expected_updated_at
                SQL);
                $parameters = [
                    'slug' => $nextSlug,
                    'target_url' => $targetUrl,
                    'title' => limit_text($title, 120),
                    'expires_at' => $expiresAt,
                    'is_favorite' => $isFavorite ? 1 : 0,
                    'starts_at' => $startsAt,
                    'max_clicks' => $maxClicks,
                    'is_one_time' => $isOneTime ? 1 : 0,
                    'one_time_mode' => $isOneTime && $oneTimeMode === 'confirm' ? 'confirm' : 'immediate',
                    'campaign_name' => limit_text($campaignName, 100),
                    'campaign_source' => limit_text($campaignSource, 100),
                    'campaign_medium' => limit_text($campaignMedium, 100),
                    'campaign_content' => limit_text($campaignContent, 100),
                    'invalid_message' => $invalidMessage,
                    'fallback_url' => $fallbackUrl,
                    'updated_at' => $now,
                    'expected_updated_at' => $expectedUpdatedAt,
                    'id' => $id,
                ];
                if ($accessPasswordHash !== null) {
                    $parameters['access_password_hash'] = $accessPasswordHash;
                }
                $statement->execute($parameters);
                if ($statement->rowCount() === 0) {
                    $this->pdo->rollBack();
                    return false;
                }
                if ($slugChanged && $preserveOldSlug) {
                    $alias = $this->pdo->prepare(
                        'INSERT INTO link_aliases (alias, link_id, created_at) VALUES (:alias, :link_id, :created_at)'
                    );
                    $alias->execute([
                        'alias' => (string)$before['slug'],
                        'link_id' => $id,
                        'created_at' => $now,
                    ]);
                }
                $this->replaceTags($id, $tags);
                $after = $this->findById($id);
                $afterStatus = link_status_key($after ?: []);
                if ($beforeStatus !== $afterStatus) {
                    $this->addHistory($id, 'settings_updated', $beforeStatus, $afterStatus, $now);
                }
                $this->pdo->commit();
                return true;
            } catch (Throwable $exception) {
                $this->rollback();
                throw $exception;
            }
        });
    }

    public function toggle(int $id, bool $desiredState, string $expectedUpdatedAt): bool
    {
        return (bool)with_sqlite_retry(function () use ($id, $desiredState, $expectedUpdatedAt): bool {
            $this->pdo->beginTransaction();
            try {
                $before = $this->findById($id);
                if (!$before || !empty($before['deleted_at']) || (string)$before['updated_at'] !== $expectedUpdatedAt) {
                    $this->pdo->rollBack();
                    return false;
                }
                if ($desiredState && (int)($before['access_password_reset_required'] ?? 0) === 1) {
                    $this->pdo->rollBack();
                    return false;
                }
                $now = utc_timestamp();
                $statement = $this->pdo->prepare(<<<'SQL'
                    UPDATE links
                    SET is_active = :is_active, updated_at = :updated_at
                    WHERE id = :id AND deleted_at IS NULL AND updated_at = :expected_updated_at
                SQL);
                $statement->execute([
                    'is_active' => $desiredState ? 1 : 0,
                    'updated_at' => $now,
                    'expected_updated_at' => $expectedUpdatedAt,
                    'id' => $id,
                ]);
                if ($statement->rowCount() === 0) {
                    $this->pdo->rollBack();
                    return false;
                }
                $after = $this->findById($id);
                $this->recordStatusChange(
                    $id,
                    $desiredState ? 'enabled' : 'disabled',
                    link_status_key($before),
                    link_status_key($after ?: []),
                    $now
                );
                if ((int)$before['is_active'] === 1 && !$desiredState) {
                    $this->enqueueLifecycle('link.disabled', $id, 'link.disabled:' . $id . ':' . $now);
                }
                $this->pdo->commit();
                return true;
            } catch (Throwable $exception) {
                $this->rollback();
                throw $exception;
            }
        });
    }

    public function setActiveForAbuse(int $id, bool $desiredState): bool
    {
        return (bool)with_sqlite_retry(function () use ($id, $desiredState): bool {
            $ownsTransaction = !$this->pdo->inTransaction();
            if ($ownsTransaction) {
                $this->pdo->beginTransaction();
            }
            try {
                $before = $this->findById($id);
                if (!$before || !empty($before['deleted_at'])) {
                    if ($ownsTransaction) {
                        $this->pdo->rollBack();
                    }
                    return false;
                }
                $now = utc_timestamp();
                $statement = $this->pdo->prepare('UPDATE links SET is_active = :active, updated_at = :updated_at WHERE id = :id AND deleted_at IS NULL');
                $statement->execute(['active' => $desiredState ? 1 : 0, 'updated_at' => $now, 'id' => $id]);
                $after = $this->findById($id);
                $this->recordStatusChange($id, $desiredState ? 'enabled_by_report' : 'disabled_by_report', link_status_key($before), link_status_key($after ?: []), $now);
                if (!$desiredState && (int)$before['is_active'] === 1) {
                    $this->enqueueLifecycle('link.disabled', $id, 'link.disabled:abuse:' . $id . ':' . $now, ['reason' => 'abuse_report']);
                }
                if ($ownsTransaction) {
                    $this->pdo->commit();
                }
                return $statement->rowCount() > 0;
            } catch (Throwable $exception) {
                if ($ownsTransaction) {
                    $this->rollback();
                }
                throw $exception;
            }
        });
    }

    public function setFavorite(int $id, bool $isFavorite): bool
    {
        $statement = $this->pdo->prepare(<<<'SQL'
            UPDATE links SET is_favorite = :is_favorite, updated_at = :updated_at
            WHERE id = :id AND deleted_at IS NULL
        SQL);
        with_sqlite_retry(fn () => $statement->execute([
            'is_favorite' => $isFavorite ? 1 : 0,
            'updated_at' => utc_timestamp(),
            'id' => $id,
        ]));
        return $statement->rowCount() > 0;
    }

    public function clearExpiredAt(int $id): bool
    {
        return (bool)with_sqlite_retry(function () use ($id): bool {
            $this->pdo->beginTransaction();
            try {
                $before = $this->findById($id);
                if (!$before || !empty($before['deleted_at']) || !link_is_expired($before)) {
                    $this->pdo->rollBack();
                    return false;
                }
                $now = utc_timestamp();
                $statement = $this->pdo->prepare(<<<'SQL'
                    UPDATE links SET expires_at = NULL, updated_at = :updated_at
                    WHERE id = :id AND deleted_at IS NULL AND expires_at IS NOT NULL AND expires_at <= :expired_at
                SQL);
                $statement->execute(['updated_at' => $now, 'expired_at' => $now, 'id' => $id]);
                if ($statement->rowCount() === 0) {
                    $this->pdo->rollBack();
                    return false;
                }
                $after = $this->findById($id);
                $this->recordStatusChange(
                    $id,
                    'expiration_cleared',
                    link_status_key($before),
                    link_status_key($after ?: []),
                    $now
                );
                $this->pdo->commit();
                return true;
            } catch (Throwable $exception) {
                $this->rollback();
                throw $exception;
            }
        });
    }

    public function softDelete(int $id, ?string $expectedUpdatedAt = null): bool
    {
        return $this->changeDeletedState($id, true, $expectedUpdatedAt);
    }

    public function restore(int $id): bool
    {
        return $this->changeDeletedState($id, false);
    }

    public function purge(int $id): bool
    {
        return (bool)with_sqlite_retry(function () use ($id): bool {
            $this->pdo->beginTransaction();
            try {
                $statement = $this->pdo->prepare('DELETE FROM links WHERE id = :id AND deleted_at IS NOT NULL');
                $statement->execute(['id' => $id]);
                $purged = $statement->rowCount() > 0;
                $this->pdo->commit();
                return $purged;
            } catch (Throwable $exception) {
                $this->rollback();
                throw $exception;
            }
        });
    }

    public function find(string $slug): ?array
    {
        $domainId = current_short_domain_id();
        $statement = $this->pdo->prepare(<<<'SQL'
            SELECT l.*, d.hostname AS short_domain_hostname
            FROM links l LEFT JOIN short_domains d ON d.id = l.short_domain_id
            WHERE l.slug = :slug
              AND ((:domain_id IS NULL AND l.short_domain_id IS NULL) OR l.short_domain_id = :domain_id)
        SQL);
        $statement->execute(['slug' => $slug, 'domain_id' => $domainId]);
        $link = $statement->fetch();
        if ($link) {
            return $link;
        }

        $statement = $this->pdo->prepare(<<<'SQL'
            SELECT l.*, d.hostname AS short_domain_hostname
            FROM link_aliases a
            INNER JOIN links l ON l.id = a.link_id
            LEFT JOIN short_domains d ON d.id = l.short_domain_id
            WHERE a.alias = :slug
              AND ((:domain_id IS NULL AND l.short_domain_id IS NULL) OR l.short_domain_id = :domain_id)
        SQL);
        $statement->execute(['slug' => $slug, 'domain_id' => $domainId]);
        $link = $statement->fetch();
        return $link ?: null;
    }

    public function aliasesForLink(int $linkId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT alias, created_at FROM link_aliases WHERE link_id = :link_id ORDER BY created_at DESC, alias ASC'
        );
        $statement->execute(['link_id' => $linkId]);
        return $statement->fetchAll();
    }

    /** @return array{id: int, slug: string, replayed: true}|null */

    public function findCreateReplay(string $requestId, string $payloadHash): ?array
    {
        if (preg_match('/^[a-f0-9]{32}$/', $requestId) !== 1
            || preg_match('/^[a-f0-9]{64}$/', $payloadHash) !== 1) {
            return null;
        }
        $statement = $this->pdo->prepare(<<<'SQL'
            SELECT l.id, l.slug
            FROM create_requests r
            INNER JOIN links l ON l.id = r.link_id
            WHERE r.request_id = :request_id AND r.payload_hash = :payload_hash
        SQL);
        $statement->execute(['request_id' => $requestId, 'payload_hash' => $payloadHash]);
        $link = $statement->fetch();
        return $link ? ['id' => (int)$link['id'], 'slug' => (string)$link['slug'], 'replayed' => true] : null;
    }

    public function findDuplicates(string $targetUrl, int $limit = 5, ?int $shortDomainId = null): array
    {
        $now = utc_timestamp();
        $statement = $this->pdo->prepare(<<<'SQL'
            SELECT l.*, d.hostname AS short_domain_hostname,
                   d.is_enabled AS short_domain_is_enabled,
                   d.verified_at AS short_domain_verified_at,
                   COALESCE((SELECT GROUP_CONCAT(tag, ', ') FROM link_tags WHERE link_id = l.id), '') AS tags
            FROM links l LEFT JOIN short_domains d ON d.id = l.short_domain_id
            WHERE l.target_url = :target_url
              AND l.deleted_at IS NULL
              AND l.is_active = 1
              AND (l.starts_at IS NULL OR julianday(l.starts_at) <= julianday(:available_at))
              AND (l.expires_at IS NULL OR julianday(l.expires_at) > julianday(:available_at))
              AND (l.max_clicks IS NULL OR l.clicks < l.max_clicks)
              AND (l.is_one_time = 0 OR l.clicks = 0)
              AND l.access_password_hash IS NULL
              AND ((:short_domain_id IS NULL AND l.short_domain_id IS NULL) OR l.short_domain_id = :short_domain_id)
            ORDER BY l.id DESC
        SQL);
        $statement->bindValue(':target_url', $targetUrl);
        $statement->bindValue(':available_at', $now);
        $statement->bindValue(':short_domain_id', $shortDomainId, $shortDomainId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $statement->execute();
        $duplicates = [];
        $limit = max(1, $limit);
        while ($link = $statement->fetch()) {
            if (!valid_slug((string)$link['slug']) || !link_is_available($link)) {
                continue;
            }
            $duplicates[] = $link;
            if (count($duplicates) >= $limit) {
                break;
            }
        }
        return $duplicates;
    }

    public function findTargetDuplicates(string $targetUrl, int $limit = 20, ?int $shortDomainId = null): array
    {
        $statement = $this->pdo->prepare(<<<'SQL'
            SELECT l.*, d.hostname AS short_domain_hostname,
                   d.is_enabled AS short_domain_is_enabled,
                   d.verified_at AS short_domain_verified_at,
                   COALESCE((SELECT GROUP_CONCAT(tag, ', ') FROM link_tags WHERE link_id = l.id), '') AS tags
            FROM links l LEFT JOIN short_domains d ON d.id = l.short_domain_id
            WHERE l.target_url = :target_url
              AND ((:short_domain_id IS NULL AND l.short_domain_id IS NULL) OR l.short_domain_id = :short_domain_id)
            ORDER BY l.id DESC
            LIMIT :item_limit
        SQL);
        $statement->bindValue(':target_url', $targetUrl);
        $statement->bindValue(':short_domain_id', $shortDomainId, $shortDomainId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $statement->bindValue(':item_limit', max(1, min(100, $limit)), PDO::PARAM_INT);
        $statement->execute();
        return $statement->fetchAll();
    }

    /** Records a completed application redirect; proxy requests and HEAD probes are separate metrics. */

    public function recordRedirect(int $id, string $now, int $maxAttempts = 3): bool
    {
        return (bool)with_sqlite_retry(function () use ($id, $now): bool {
            $this->pdo->beginTransaction();
            try {
                $statement = $this->pdo->prepare(<<<'SQL'
                    UPDATE links
                    SET clicks = clicks + 1, last_accessed_at = :last_accessed_at
                    WHERE id = :id
                      AND deleted_at IS NULL
                      AND is_active = 1
                      AND access_password_reset_required = 0
                      AND (starts_at IS NULL OR julianday(starts_at) <= julianday(:starts_at))
                      AND (expires_at IS NULL OR julianday(expires_at) > julianday(:expires_at))
                      AND (max_clicks IS NULL OR clicks < max_clicks)
                      AND (is_one_time = 0 OR clicks = 0)
                SQL);
                $statement->execute([
                    'last_accessed_at' => $now,
                    'starts_at' => $now,
                    'expires_at' => $now,
                    'id' => $id,
                ]);
                if ($statement->rowCount() === 0) {
                    $this->pdo->rollBack();
                    return false;
                }

                $daily = $this->pdo->prepare(<<<'SQL'
                    INSERT INTO link_daily_stats (link_id, accessed_on, clicks)
                    VALUES (:link_id, :accessed_on, 1)
                    ON CONFLICT(link_id, accessed_on) DO UPDATE SET clicks = clicks + 1
                SQL);
                $daily->execute(['link_id' => $id, 'accessed_on' => substr($now, 0, 10)]);

                $after = $this->findById($id);
                if ($after && link_is_exhausted($after)) {
                    $this->addHistory($id, 'click_limit_reached', 'active', 'exhausted', $now);
                }
                $this->pdo->commit();
                return true;
            } catch (Throwable $exception) {
                $this->rollback();
                throw $exception;
            }
        }, max(1, $maxAttempts));
    }

    public function listForAdmin(
        string $view,
        string $search,
        int $page,
        int $pageSize,
        string $status = 'all',
        string $sort = 'created_desc',
        string $tag = '',
        bool $favoritesOnly = false
    ): array {
        [$where, $params] = $this->adminFilter($view, $search, $status, $tag, $favoritesOnly);
        $count = $this->pdo->prepare("SELECT COUNT(*) FROM links l WHERE {$where}");
        $count->execute($params);
        $total = (int)$count->fetchColumn();
        $maxPage = max(1, (int)ceil($total / $pageSize));
        $page = min(max(1, $page), $maxPage);
        $offset = ($page - 1) * $pageSize;
        $pageRows = min($pageSize, max(0, $total - $offset));
        $reverseOffset = max(0, $total - $offset - $pageRows);
        $reversePage = $reverseOffset < $offset;
        $queryOffset = $reversePage ? $reverseOffset : $offset;
        $orderBy = $reversePage ? $this->adminReverseOrder($sort) : $this->adminOrder($sort);
        $statement = $this->pdo->prepare(<<<SQL
            SELECT l.*, d.hostname AS short_domain_hostname,
                d.is_enabled AS short_domain_is_enabled,
                d.verified_at AS short_domain_verified_at,
                h.state AS target_health_state,
                h.reason AS target_health_reason,
                h.checked_at AS target_health_checked_at
            FROM links l LEFT JOIN short_domains d ON d.id = l.short_domain_id
            LEFT JOIN target_health h ON h.link_id = l.id
            WHERE {$where}
            ORDER BY {$orderBy}
            LIMIT :page_size OFFSET :offset
        SQL);
        foreach ($params as $name => $value) {
            $statement->bindValue(':' . $name, $value);
        }
        $statement->bindValue(':page_size', $pageSize, PDO::PARAM_INT);
        $statement->bindValue(':offset', $queryOffset, PDO::PARAM_INT);
        $statement->execute();

        $links = $statement->fetchAll();
        if ($reversePage) {
            $links = array_reverse($links);
        }
        $linksById = [];
        foreach ($links as $index => &$link) {
            $link['recent_clicks'] = 0;
            $link['tags'] = '';
            $linksById[(int)$link['id']] = $index;
        }
        unset($link);

        if ($linksById !== []) {
            $placeholders = implode(',', array_fill(0, count($linksById), '?'));
            $ids = array_keys($linksById);

            $recent = $this->pdo->prepare(<<<SQL
                SELECT link_id, SUM(clicks) AS recent_clicks
                FROM link_daily_stats
                WHERE accessed_on >= ? AND link_id IN ({$placeholders})
                GROUP BY link_id
            SQL);
            $recent->execute(array_merge([gmdate('Y-m-d', strtotime('-6 days'))], $ids));
            foreach ($recent->fetchAll() as $row) {
                $id = (int)$row['link_id'];
                if (isset($linksById[$id])) {
                    $links[$linksById[$id]]['recent_clicks'] = (int)$row['recent_clicks'];
                }
            }

            $tags = $this->pdo->prepare(<<<SQL
                SELECT link_id, tag FROM link_tags
                WHERE link_id IN ({$placeholders})
                ORDER BY link_id, tag COLLATE NOCASE
            SQL);
            $tags->execute($ids);
            $tagsByLink = [];
            foreach ($tags->fetchAll() as $row) {
                $tagsByLink[(int)$row['link_id']][] = (string)$row['tag'];
            }
            foreach ($tagsByLink as $id => $linkTags) {
                if (isset($linksById[$id])) {
                    $links[$linksById[$id]]['tags'] = implode("\x1F", $linkTags);
                }
            }
        }

        return ['links' => $links, 'total' => $total, 'page' => $page];
    }

    public function allTags(string $view = 'active'): array
    {
        $deletedCondition = $view === 'trash' ? 'l.deleted_at IS NOT NULL' : 'l.deleted_at IS NULL';
        return $this->pdo->query(<<<SQL
            SELECT t.tag, COUNT(*) AS link_count
            FROM link_tags t INNER JOIN links l ON l.id = t.link_id
            WHERE {$deletedCondition}
            GROUP BY t.tag ORDER BY t.tag COLLATE NOCASE ASC
        SQL)->fetchAll();
    }

    public function getAdminLink(int $id): ?array
    {
        $statement = $this->pdo->prepare(<<<'SQL'
            SELECT l.*, d.hostname AS short_domain_hostname,
                   d.is_enabled AS short_domain_is_enabled,
                   d.verified_at AS short_domain_verified_at,
                   h.state AS target_health_state,
                   h.reason AS target_health_reason,
                   h.checked_at AS target_health_checked_at,
                   COALESCE((
                        SELECT GROUP_CONCAT(tag, X'1F')
                       FROM (SELECT tag FROM link_tags WHERE link_id = l.id ORDER BY tag COLLATE NOCASE)
                   ), '') AS tags
            FROM links l LEFT JOIN short_domains d ON d.id = l.short_domain_id
            LEFT JOIN target_health h ON h.link_id = l.id WHERE l.id = :id
        SQL);
        $statement->execute(['id' => $id]);
        $link = $statement->fetch();
        return $link ?: null;
    }

    public function bulk(array $ids, string $action): int
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn (int $id): bool => $id > 0
        )));
        if (!$ids || count($ids) > 1000 || !in_array($action, [
            'favorite', 'unfavorite', 'enable', 'disable', 'delete', 'restore', 'purge',
        ], true)) {
            return 0;
        }

        return (int)with_sqlite_retry(function () use ($ids, $action): int {
            $this->pdo->beginTransaction();
            try {
                $changed = 0;
                foreach ($ids as $id) {
                    $before = $this->findById($id);
                    if (!$before) {
                        continue;
                    }
                    $now = utc_timestamp();
                    if ($action === 'purge') {
                        if (empty($before['deleted_at'])) {
                            continue;
                        }
                        $statement = $this->pdo->prepare('DELETE FROM links WHERE id = :id AND deleted_at IS NOT NULL');
                        $statement->execute(['id' => $id]);
                        $changed += $statement->rowCount();
                        continue;
                    }

                    [$setSql, $params, $event] = match ($action) {
                        'favorite' => ['is_favorite = 1', [], null],
                        'unfavorite' => ['is_favorite = 0', [], null],
                        'enable' => ['is_active = 1', [], 'enabled'],
                        'disable' => ['is_active = 0', [], 'disabled'],
                        'delete' => ['deleted_at = :deleted_at', ['deleted_at' => $now], 'deleted'],
                        'restore' => ['deleted_at = NULL', [], 'restored'],
                    };
                    $requiresDeleted = $action === 'restore' ? 'deleted_at IS NOT NULL' : 'deleted_at IS NULL';
                    $statement = $this->pdo->prepare(
                        "UPDATE links SET {$setSql}, updated_at = :updated_at WHERE id = :id AND {$requiresDeleted}"
                    );
                    $statement->execute(array_merge($params, ['updated_at' => $now, 'id' => $id]));
                    if ($statement->rowCount() === 0) {
                        continue;
                    }
                    $changed++;
                    if ($event !== null) {
                        $after = $this->findById($id);
                        $this->recordStatusChange(
                            $id,
                            $event,
                            link_status_key($before),
                            link_status_key($after ?: []),
                            $now
                        );
                    }
                }
                $this->pdo->commit();
                return $changed;
            } catch (Throwable $exception) {
                $this->rollback();
                throw $exception;
            }
        });
    }

    /** @return array{status: string, migrated: int} */

    public function retireShortDomain(int $sourceId, ?int $destinationId): array
    {
        return with_sqlite_retry(function () use ($sourceId, $destinationId): array {
            $this->pdo->exec('BEGIN IMMEDIATE');
            try {
                $source = $this->pdo->prepare('SELECT hostname, is_enabled FROM short_domains WHERE id = :id');
                $source->execute(['id' => $sourceId]);
                $sourceDomain = $source->fetch();
                if (!$sourceDomain) {
                    $this->pdo->rollBack();
                    return ['status' => 'not_found', 'migrated' => 0];
                }
                if ($destinationId === $sourceId) {
                    $this->pdo->rollBack();
                    return ['status' => 'same_domain', 'migrated' => 0];
                }
                if ($destinationId !== null) {
                    $destination = $this->pdo->prepare(<<<'SQL'
                        SELECT 1 FROM short_domains
                        WHERE id = :id AND verified_at IS NOT NULL AND is_enabled = 1
                    SQL);
                    $destination->execute(['id' => $destinationId]);
                    if (!$destination->fetchColumn()) {
                        $this->pdo->rollBack();
                        return ['status' => 'invalid_destination', 'migrated' => 0];
                    }
                }

                $now = utc_timestamp();
                $count = $this->pdo->prepare('SELECT COUNT(*) FROM links WHERE short_domain_id = :source_id');
                $count->execute(['source_id' => $sourceId]);
                $totalCount = (int)$count->fetchColumn();
                $disable = $this->pdo->prepare(
                    'UPDATE short_domains SET is_enabled = 0, updated_at = :updated_at WHERE id = :id'
                );
                $disable->execute(['updated_at' => $now, 'id' => $sourceId]);
                $job = $this->pdo->prepare(<<<'SQL'
                    INSERT INTO short_domain_retirement_jobs (
                        source_id, source_hostname, destination_id, status, total_count,
                        migrated_count, attempt_count, created_at, updated_at
                    ) VALUES (
                        :source_id, :source_hostname, :destination_id, 'pending', :total_count,
                        0, 0, :created_at, :updated_at
                    )
                    ON CONFLICT(source_id) DO UPDATE SET
                        destination_id = excluded.destination_id,
                        status = 'pending',
                        total_count = excluded.total_count,
                        migrated_count = 0,
                        attempt_count = 0,
                        last_error = NULL,
                        completed_at = NULL,
                        updated_at = excluded.updated_at
                SQL);
                $job->bindValue(':source_id', $sourceId, PDO::PARAM_INT);
                $job->bindValue(':source_hostname', (string)$sourceDomain['hostname']);
                $job->bindValue(':destination_id', $destinationId, $destinationId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
                $job->bindValue(':total_count', $totalCount, PDO::PARAM_INT);
                $job->bindValue(':created_at', $now);
                $job->bindValue(':updated_at', $now);
                $job->execute();
                $this->pdo->commit();
                return ['status' => 'queued', 'migrated' => 0];
            } catch (Throwable $exception) {
                $this->rollback();
                throw $exception;
            }
        });
    }

    /** @return array{status: string, migrated: int} */
    public function processShortDomainRetirementBatch(int $batchSize = 200): array
    {
        $batchSize = max(10, min(400, $batchSize));
        return with_sqlite_retry(function () use ($batchSize): array {
            $job = null;
            $this->pdo->exec('BEGIN IMMEDIATE');
            try {
                $job = $this->pdo->query(<<<'SQL'
                    SELECT id, source_id, destination_id, status
                    FROM short_domain_retirement_jobs
                    WHERE status IN ('pending', 'running')
                    ORDER BY created_at ASC, id ASC LIMIT 1
                SQL)->fetch();
                if (!$job) {
                    $this->pdo->commit();
                    return ['status' => 'idle', 'migrated' => 0];
                }
                $now = utc_timestamp();
                if ((string)$job['status'] === 'pending') {
                    $attempt = $this->pdo->prepare(<<<'SQL'
                        UPDATE short_domain_retirement_jobs
                        SET status = 'running', attempt_count = attempt_count + 1, updated_at = :updated_at
                        WHERE id = :id AND status = 'pending'
                    SQL);
                    $attempt->execute(['updated_at' => $now, 'id' => (int)$job['id']]);
                }
                if ($job['destination_id'] !== null) {
                    $destination = $this->pdo->prepare(<<<'SQL'
                        SELECT 1 FROM short_domains
                        WHERE id = :id AND verified_at IS NOT NULL AND is_enabled = 1
                    SQL);
                    $destination->execute(['id' => (int)$job['destination_id']]);
                    if (!$destination->fetchColumn()) {
                        $failed = $this->pdo->prepare(<<<'SQL'
                            UPDATE short_domain_retirement_jobs
                            SET status = 'failed', last_error = :last_error,
                                updated_at = :updated_at, completed_at = :completed_at
                            WHERE id = :id
                        SQL);
                        $failed->execute([
                            'last_error' => 'Retirement destination no longer exists or is unavailable.',
                            'updated_at' => $now,
                            'completed_at' => $now,
                            'id' => (int)$job['id'],
                        ]);
                        $this->pdo->commit();
                        return ['status' => 'failed', 'migrated' => 0];
                    }
                }
                $ids = $this->pdo->prepare(<<<'SQL'
                    SELECT id FROM links WHERE short_domain_id = :source_id ORDER BY id ASC LIMIT :batch_size
                SQL);
                $ids->bindValue(':source_id', (int)$job['source_id'], PDO::PARAM_INT);
                $ids->bindValue(':batch_size', $batchSize, PDO::PARAM_INT);
                $ids->execute();
                $linkIds = array_map('intval', $ids->fetchAll(PDO::FETCH_COLUMN));
                $migrated = 0;
                if ($linkIds) {
                    $placeholders = implode(',', array_fill(0, count($linkIds), '?'));
                    $update = $this->pdo->prepare(
                        "UPDATE links SET short_domain_id = ?, updated_at = ? WHERE id IN ({$placeholders}) AND short_domain_id = ?"
                    );
                    $update->execute(array_merge([$job['destination_id'], $now], $linkIds, [(int)$job['source_id']]));
                    $migrated = $update->rowCount();
                }
                $remaining = $this->pdo->prepare('SELECT 1 FROM links WHERE short_domain_id = :source_id LIMIT 1');
                $remaining->execute(['source_id' => (int)$job['source_id']]);
                $completed = !$remaining->fetchColumn();
                if ($completed) {
                    $delete = $this->pdo->prepare('DELETE FROM short_domains WHERE id = :id');
                    $delete->execute(['id' => (int)$job['source_id']]);
                }
                $updateJob = $this->pdo->prepare(<<<'SQL'
                    UPDATE short_domain_retirement_jobs
                    SET status = :status, migrated_count = migrated_count + :migrated,
                        updated_at = :updated_at, completed_at = :completed_at
                    WHERE id = :id
                SQL);
                $updateJob->execute([
                    'status' => $completed ? 'completed' : 'running',
                    'migrated' => $migrated,
                    'updated_at' => $now,
                    'completed_at' => $completed ? $now : null,
                    'id' => (int)$job['id'],
                ]);
                $this->pdo->commit();
                return ['status' => $completed ? 'completed' : 'running', 'migrated' => $migrated];
            } catch (Throwable $exception) {
                $this->rollback();
                if (is_array($job)) {
                    $failed = $this->pdo->prepare(<<<'SQL'
                        UPDATE short_domain_retirement_jobs
                        SET status = 'failed', last_error = :last_error, updated_at = :updated_at
                        WHERE id = :id AND status IN ('pending', 'running')
                    SQL);
                    with_sqlite_retry(fn () => $failed->execute([
                        'last_error' => limit_text($exception->getMessage(), 300),
                        'updated_at' => utc_timestamp(),
                        'id' => (int)$job['id'],
                    ]));
                }
                throw $exception;
            }
        });
    }

    public function controlShortDomainRetirement(int $jobId, string $action): bool
    {
        $transitions = [
            'pause' => [['pending', 'running'], 'paused'],
            'resume' => [['paused'], 'pending'],
            'retry' => [['failed'], 'pending'],
            'cancel' => [['pending', 'running', 'paused', 'failed'], 'canceled'],
        ];
        if (!isset($transitions[$action])) {
            return false;
        }
        [$from, $to] = $transitions[$action];
        $placeholders = implode(',', array_fill(0, count($from), '?'));
        $statement = $this->pdo->prepare(
            "UPDATE short_domain_retirement_jobs SET status = ?, last_error = NULL, updated_at = ? "
            . "WHERE id = ? AND status IN ({$placeholders})"
        );
        with_sqlite_retry(fn () => $statement->execute(array_merge([$to, utc_timestamp(), $jobId], $from)));
        return $statement->rowCount() === 1;
    }

    public function shortDomainRetirementJobs(int $limit = 50): array
    {
        $statement = $this->pdo->prepare(<<<'SQL'
            SELECT j.*, d.hostname AS destination_hostname,
                   MAX(0, j.total_count - j.migrated_count) AS remaining_count
            FROM short_domain_retirement_jobs j
            LEFT JOIN short_domains d ON d.id = j.destination_id
            ORDER BY j.updated_at DESC, j.id DESC LIMIT :record_limit
        SQL);
        $statement->bindValue(':record_limit', max(1, min(200, $limit)), PDO::PARAM_INT);
        $statement->execute();
        return $statement->fetchAll();
    }

    public function savedFilters(): array
    {
        return $this->pdo->query(<<<'SQL'
            SELECT id, name, view, search, status, sort, tag, favorites_only, created_at, updated_at
            FROM saved_filters ORDER BY name COLLATE NOCASE ASC, id ASC
        SQL)->fetchAll();
    }

    public function saveFilter(
        string $name,
        string $view,
        string $search,
        string $status,
        string $sort,
        string $tag,
        bool $favoritesOnly
    ): int {
        $now = utc_timestamp();
        $statement = $this->pdo->prepare(<<<'SQL'
            INSERT INTO saved_filters (
                name, view, search, status, sort, tag, favorites_only, created_at, updated_at
            ) VALUES (
                :name, :view, :search, :status, :sort, :tag, :favorites_only, :created_at, :updated_at
            )
            ON CONFLICT(name) DO UPDATE SET
                view = excluded.view,
                search = excluded.search,
                status = excluded.status,
                sort = excluded.sort,
                tag = excluded.tag,
                favorites_only = excluded.favorites_only,
                updated_at = excluded.updated_at
        SQL);
        with_sqlite_retry(fn () => $statement->execute([
            'name' => $name,
            'view' => $view,
            'search' => $search,
            'status' => $status,
            'sort' => $sort,
            'tag' => $tag,
            'favorites_only' => $favoritesOnly ? 1 : 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]));
        $lookup = $this->pdo->prepare('SELECT id FROM saved_filters WHERE name = :name COLLATE NOCASE');
        $lookup->execute(['name' => $name]);
        return (int)$lookup->fetchColumn();
    }

    public function deleteSavedFilter(int $id): bool
    {
        $statement = $this->pdo->prepare('DELETE FROM saved_filters WHERE id = :id');
        with_sqlite_retry(fn () => $statement->execute(['id' => $id]));
        return $statement->rowCount() > 0;
    }

    public function renameSavedFilter(int $id, string $name): bool
    {
        $statement = $this->pdo->prepare(<<<'SQL'
            UPDATE saved_filters
            SET name = :name, updated_at = :updated_at
            WHERE id = :id
              AND NOT EXISTS (
                  SELECT 1 FROM saved_filters existing
                  WHERE existing.id <> :existing_id AND existing.name = :existing_name COLLATE NOCASE
              )
        SQL);
        with_sqlite_retry(fn () => $statement->execute([
            'name' => $name,
            'updated_at' => utc_timestamp(),
            'id' => $id,
            'existing_id' => $id,
            'existing_name' => $name,
        ]));
        return $statement->rowCount() > 0;
    }

    /** @return array{processed: int, archived: int, deleted: int, cutoff: string, archive_deleted: int, archive_cutoff: string} */
}
