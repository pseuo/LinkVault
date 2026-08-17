<?php

declare(strict_types=1);

final class ApiTokenService
{
    public const ALLOWED_SCOPES = ['links:create', 'links:read', 'links:write', 'links:delete', 'conversions:write'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{id: int, token: string, prefix: string, scopes: list<string>} */
    public function create(
        string $name,
        ?string $expiresAt,
        array $scopes = ['links:create'],
        ?int $quotaRequests = null,
        ?int $quotaWindowSeconds = null,
        string $allowedCidrs = ''
    ): array
    {
        $this->validateInput($name, $expiresAt);
        $scopes = self::normalizeScopes($scopes);
        $allowedCidrs = self::normalizeCidrs($allowedCidrs);
        $this->validateQuota($quotaRequests, $quotaWindowSeconds);
        [$token, $prefix, $hash] = $this->generateToken();
        $statement = $this->pdo->prepare(<<<'SQL'
            INSERT INTO api_tokens (
                name, token_prefix, token_hash, scopes, created_at, expires_at,
                quota_requests, quota_window_seconds, allowed_cidrs
            ) VALUES (
                :name, :token_prefix, :token_hash, :scopes, :created_at, :expires_at,
                :quota_requests, :quota_window_seconds, :allowed_cidrs
            )
        SQL);
        with_sqlite_retry(fn () => $statement->execute([
            'name' => $name,
            'token_prefix' => $prefix,
            'token_hash' => $hash,
            'scopes' => implode(' ', $scopes),
            'created_at' => utc_timestamp(),
            'expires_at' => $expiresAt,
            'quota_requests' => $quotaRequests,
            'quota_window_seconds' => $quotaWindowSeconds,
            'allowed_cidrs' => $allowedCidrs,
        ]));

        return ['id' => (int)$this->pdo->lastInsertId(), 'token' => $token, 'prefix' => $prefix, 'scopes' => $scopes];
    }

    /** @return array{id: int, token: string, prefix: string, rotation_expires_at: string}|null */
    public function rotate(int $id, ?string $expiresAt, int $overlapSeconds): ?array
    {
        $overlapSeconds = max(60, min(86400, $overlapSeconds));
        [$token, $prefix, $hash] = $this->generateToken();

        return with_sqlite_retry(function () use ($id, $expiresAt, $overlapSeconds, $token, $prefix, $hash): ?array {
            $now = utc_timestamp();
            $this->pdo->exec('BEGIN IMMEDIATE');
            try {
                $existing = $this->find($id);
                if (!$existing || $existing['revoked_at'] !== null
                    || $this->isExpired($existing['expires_at'])
                    || $this->isExpired($existing['rotation_expires_at'])
                    || (int)$existing['replacement_count'] > 0) {
                    $this->pdo->rollBack();
                    return null;
                }
                $name = (string)$existing['name'];
                $scopes = (string)$existing['scopes'];
                $this->validateInput($name, $expiresAt);
                $rotationExpiresAt = gmdate('Y-m-d\TH:i:s\Z', time() + $overlapSeconds);
                $retire = $this->pdo->prepare(<<<'SQL'
                    UPDATE api_tokens SET rotation_expires_at = :rotation_expires_at
                    WHERE id = :id AND revoked_at IS NULL AND rotation_expires_at IS NULL
                SQL);
                $retire->execute(['rotation_expires_at' => $rotationExpiresAt, 'id' => $id]);
                if ($retire->rowCount() !== 1) {
                    $this->pdo->rollBack();
                    return null;
                }
                $insert = $this->pdo->prepare(<<<'SQL'
                    INSERT INTO api_tokens (
                        name, token_prefix, token_hash, scopes, created_at, expires_at, rotated_from_id,
                        quota_requests, quota_window_seconds, allowed_cidrs
                    ) VALUES (
                        :name, :token_prefix, :token_hash, :scopes, :created_at, :expires_at, :rotated_from_id,
                        :quota_requests, :quota_window_seconds, :allowed_cidrs
                    )
                SQL);
                $insert->execute([
                    'name' => $name,
                    'token_prefix' => $prefix,
                    'token_hash' => $hash,
                    'scopes' => $scopes,
                    'created_at' => $now,
                    'expires_at' => $expiresAt,
                    'rotated_from_id' => $id,
                    'quota_requests' => $existing['quota_requests'],
                    'quota_window_seconds' => $existing['quota_window_seconds'],
                    'allowed_cidrs' => $existing['allowed_cidrs'],
                ]);
                $newId = (int)$this->pdo->lastInsertId();
                $this->pdo->commit();
                return [
                    'id' => $newId,
                    'token' => $token,
                    'prefix' => $prefix,
                    'rotation_expires_at' => $rotationExpiresAt,
                ];
            } catch (Throwable $exception) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $exception;
            }
        });
    }

    public function revoke(int $id): bool
    {
        $statement = $this->pdo->prepare(<<<'SQL'
            UPDATE api_tokens SET revoked_at = :revoked_at
            WHERE id = :id AND revoked_at IS NULL
        SQL);
        with_sqlite_retry(fn () => $statement->execute(['revoked_at' => utc_timestamp(), 'id' => $id]));
        return $statement->rowCount() === 1;
    }

    /**
     * @return array{
     *     id: int,
     *     name: string,
     *     prefix: string,
     *     outcome: string,
     *     accepted: bool,
     *     scopes: list<string>,
     *     quota_requests: ?int,
     *     quota_window_seconds: ?int,
     *     allowed_cidrs: string
     * }|null
     */
    public function authenticate(string $token): ?array
    {
        if ($token === '') {
            return null;
        }
        $statement = $this->pdo->prepare(<<<'SQL'
            SELECT id, name, token_prefix, scopes, expires_at, revoked_at, rotation_expires_at,
                   quota_requests, quota_window_seconds, allowed_cidrs
            FROM api_tokens WHERE token_hash = :token_hash
        SQL);
        $statement->execute(['token_hash' => hash('sha256', $token)]);
        $matched = $statement->fetch();
        if (!$matched) {
            return null;
        }

        $outcome = 'accepted';
        if ($matched['revoked_at'] !== null) {
            $outcome = 'revoked';
        } elseif ($this->isExpired($matched['expires_at']) || $this->isExpired($matched['rotation_expires_at'])) {
            $outcome = 'expired';
        }
        return [
            'id' => (int)$matched['id'],
            'name' => (string)$matched['name'],
            'prefix' => (string)$matched['token_prefix'],
            'outcome' => $outcome,
            'accepted' => $outcome === 'accepted',
            'scopes' => self::parseStoredScopes((string)$matched['scopes']),
            'quota_requests' => $matched['quota_requests'] === null ? null : (int)$matched['quota_requests'],
            'quota_window_seconds' => $matched['quota_window_seconds'] === null
                ? null : (int)$matched['quota_window_seconds'],
            'allowed_cidrs' => (string)$matched['allowed_cidrs'],
        ];
    }

    public function recordLegacyUsage(string $endpoint, string $requestId): void
    {
        $this->recordUsage(null, 'LINKVAULT_API_TOKEN', 'env', 'accepted', $endpoint, $requestId);
    }

    /** @param array{id: int, name: string, prefix: string, outcome: string} $token */
    public function recordManagedUsage(array $token, string $endpoint, string $requestId, int $failedRecordLimit): void
    {
        $this->recordUsage(
            (int)$token['id'],
            (string)$token['name'],
            (string)$token['prefix'],
            (string)$token['outcome'],
            $endpoint,
            $requestId,
            $failedRecordLimit
        );
    }

    public function hasActiveToken(?string $requiredScope = null): bool
    {
        foreach ($this->pdo->query(<<<'SQL'
            SELECT scopes, expires_at, rotation_expires_at FROM api_tokens WHERE revoked_at IS NULL
        SQL) as $row) {
            if (!$this->isExpired($row['expires_at'] ?? null)
                && !$this->isExpired($row['rotation_expires_at'] ?? null)
                && ($requiredScope === null || in_array($requiredScope, self::parseStoredScopes((string)$row['scopes']), true))) {
                return true;
            }
        }
        return false;
    }

    /** @return array{tokens: array<int, array<string, mixed>>, total: int, page: int, page_size: int} */
    public function listTokens(int $page = 1, int $pageSize = 25): array
    {
        $pageSize = max(5, min(100, $pageSize));
        $total = (int)$this->pdo->query('SELECT COUNT(*) FROM api_tokens')->fetchColumn();
        $page = min(max(1, $page), max(1, (int)ceil($total / $pageSize)));
        $statement = $this->pdo->prepare(<<<'SQL'
            SELECT id, name, token_prefix, created_at, expires_at, last_used_at,
                   use_count, revoked_at, rotated_from_id, rotation_expires_at, scopes,
                   quota_requests, quota_window_seconds, allowed_cidrs
            FROM api_tokens ORDER BY created_at DESC, id DESC LIMIT :page_size OFFSET :offset
        SQL);
        $statement->bindValue(':page_size', $pageSize, PDO::PARAM_INT);
        $statement->bindValue(':offset', ($page - 1) * $pageSize, PDO::PARAM_INT);
        $statement->execute();
        return [
            'tokens' => $statement->fetchAll(),
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
        ];
    }

    public function recentUsage(int $limit = 50): array
    {
        $statement = $this->pdo->prepare(<<<'SQL'
            SELECT id, token_id, token_name, token_prefix, used_at, outcome, endpoint, request_id
            FROM api_token_usage ORDER BY used_at DESC, id DESC LIMIT :record_limit
        SQL);
        $statement->bindValue(':record_limit', max(1, min(200, $limit)), PDO::PARAM_INT);
        $statement->execute();
        return $statement->fetchAll();
    }

    public function alerts(int $limit = 50): array
    {
        $statement = $this->pdo->prepare(<<<'SQL'
            SELECT a.*, t.name AS token_name, t.token_prefix
            FROM api_token_alerts a
            JOIN api_tokens t ON t.id = a.token_id
            ORDER BY a.last_seen_at DESC LIMIT :record_limit
        SQL);
        $statement->bindValue(':record_limit', max(1, min(200, $limit)), PDO::PARAM_INT);
        $statement->execute();
        return $statement->fetchAll();
    }

    public function recordAlert(int $tokenId, string $type, string $endpoint, string $clientIp): void
    {
        if (!in_array($type, ['cidr_denied', 'rate_limited'], true)) {
            throw new InvalidArgumentException('Invalid API token alert type.');
        }
        $now = utc_timestamp();
        $statement = $this->pdo->prepare(<<<'SQL'
            INSERT INTO api_token_alerts (
                token_id, alert_type, occurrence_count, first_seen_at, last_seen_at,
                last_endpoint, last_client_ip
            ) VALUES (
                :token_id, :alert_type, 1, :first_seen_at, :last_seen_at,
                :last_endpoint, :last_client_ip
            )
            ON CONFLICT(token_id, alert_type) DO UPDATE SET
                occurrence_count = occurrence_count + 1,
                last_seen_at = excluded.last_seen_at,
                last_endpoint = excluded.last_endpoint,
                last_client_ip = excluded.last_client_ip
        SQL);
        with_sqlite_retry(fn () => $statement->execute([
            'token_id' => $tokenId,
            'alert_type' => $type,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'last_endpoint' => limit_text($endpoint, 120),
            'last_client_ip' => limit_text($clientIp, 45),
        ]));
    }

    public function clearAlerts(): int
    {
        $statement = $this->pdo->prepare('DELETE FROM api_token_alerts');
        with_sqlite_retry(fn () => $statement->execute());
        return $statement->rowCount();
    }

    public static function clientAllowed(string $clientIp, string $storedCidrs): bool
    {
        if (trim($storedCidrs) === '') {
            return true;
        }
        $address = @inet_pton($clientIp);
        if (!is_string($address)) {
            return false;
        }
        foreach (explode(',', $storedCidrs) as $cidr) {
            [$network, $prefix] = explode('/', $cidr, 2);
            $networkBytes = @inet_pton($network);
            if (!is_string($networkBytes) || strlen($networkBytes) !== strlen($address)) {
                continue;
            }
            $bits = (int)$prefix;
            $wholeBytes = intdiv($bits, 8);
            $remainingBits = $bits % 8;
            if (substr($address, 0, $wholeBytes) !== substr($networkBytes, 0, $wholeBytes)) {
                continue;
            }
            if ($remainingBits === 0) {
                return true;
            }
            $mask = (0xFF << (8 - $remainingBits)) & 0xFF;
            if ((ord($address[$wholeBytes]) & $mask) === (ord($networkBytes[$wholeBytes]) & $mask)) {
                return true;
            }
        }
        return false;
    }

    public function enforceUsageRetention(int $retentionDays, int $batchSize = 500): int
    {
        if ($retentionDays < 1 || $retentionDays > 3650 || $batchSize < 1 || $batchSize > 5000) {
            throw new InvalidArgumentException('Invalid API token usage retention policy.');
        }
        $statement = $this->pdo->prepare(<<<'SQL'
            DELETE FROM api_token_usage
            WHERE rowid IN (
                SELECT rowid FROM api_token_usage
                WHERE used_at < :cutoff ORDER BY used_at ASC, rowid ASC LIMIT :batch_size
            )
        SQL);
        $deleted = 0;
        $cutoff = gmdate('Y-m-d\TH:i:s\Z', time() - $retentionDays * 86400);
        do {
            $statement->bindValue(':cutoff', $cutoff);
            $statement->bindValue(':batch_size', $batchSize, PDO::PARAM_INT);
            with_sqlite_retry(fn () => $statement->execute());
            $batchDeleted = $statement->rowCount();
            $deleted += $batchDeleted;
        } while ($batchDeleted === $batchSize);
        return $deleted;
    }

    public function enforceTokenRetention(int $retentionDays, int $batchSize = 500): int
    {
        if ($retentionDays < 1 || $retentionDays > 3650 || $batchSize < 1 || $batchSize > 5000) {
            throw new InvalidArgumentException('Invalid API token retention policy.');
        }
        $statement = $this->pdo->prepare(<<<'SQL'
            DELETE FROM api_tokens
            WHERE id IN (
                SELECT id FROM api_tokens
                WHERE (
                    (revoked_at IS NOT NULL AND revoked_at < :cutoff)
                    OR (expires_at IS NOT NULL AND expires_at < :cutoff)
                    OR (rotation_expires_at IS NOT NULL AND rotation_expires_at < :cutoff)
                )
                AND NOT EXISTS (
                    SELECT 1 FROM conversion_events
                    WHERE conversion_events.token_id = api_tokens.id
                )
                ORDER BY id ASC LIMIT :batch_size
            )
        SQL);
        $deleted = 0;
        $cutoff = gmdate('Y-m-d\TH:i:s\Z', time() - $retentionDays * 86400);
        do {
            $statement->bindValue(':cutoff', $cutoff);
            $statement->bindValue(':batch_size', $batchSize, PDO::PARAM_INT);
            with_sqlite_retry(fn () => $statement->execute());
            $batchDeleted = $statement->rowCount();
            $deleted += $batchDeleted;
        } while ($batchDeleted === $batchSize);
        return $deleted;
    }

    private function find(int $id): ?array
    {
        $statement = $this->pdo->prepare(<<<'SQL'
            SELECT t.id, t.name, t.scopes, t.expires_at, t.revoked_at, t.rotation_expires_at,
                   t.quota_requests, t.quota_window_seconds, t.allowed_cidrs,
                   (SELECT COUNT(*) FROM api_tokens child WHERE child.rotated_from_id = t.id) AS replacement_count
            FROM api_tokens t WHERE t.id = :id
        SQL);
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();
        return $row ?: null;
    }

    private function validateInput(string $name, ?string $expiresAt): void
    {
        if ($name === '' || text_length($name) > 60 || preg_match('/[\x00-\x1F\x7F]/u', $name) === 1) {
            throw new InvalidArgumentException('Invalid API token name.');
        }
        if ($expiresAt !== null) {
            try {
                if (new DateTimeImmutable($expiresAt) <= new DateTimeImmutable('now', new DateTimeZone('UTC'))) {
                    throw new InvalidArgumentException('API token expiration must be in the future.');
                }
            } catch (InvalidArgumentException $exception) {
                throw $exception;
            } catch (Throwable) {
                throw new InvalidArgumentException('Invalid API token expiration.');
            }
        }
    }

    public static function normalizeScopes(array $scopes): array
    {
        $normalized = [];
        foreach ($scopes as $scope) {
            if (!is_string($scope) || !in_array($scope, self::ALLOWED_SCOPES, true)) {
                throw new InvalidArgumentException('Invalid API token scope.');
            }
            $normalized[$scope] = true;
        }
        $result = array_values(array_filter(
            self::ALLOWED_SCOPES,
            static fn (string $scope): bool => isset($normalized[$scope])
        ));
        if (!$result) {
            throw new InvalidArgumentException('At least one API token scope is required.');
        }
        return $result;
    }

    public static function parseStoredScopes(string $scopes): array
    {
        return self::normalizeScopes(array_values(array_filter(explode(' ', trim($scopes)))));
    }

    public static function normalizeCidrs(string $cidrs): string
    {
        $normalized = [];
        foreach (preg_split('/[\s,]+/', trim($cidrs)) ?: [] as $cidr) {
            if ($cidr === '') {
                continue;
            }
            if (!str_contains($cidr, '/')) {
                $cidr .= str_contains($cidr, ':') ? '/128' : '/32';
            }
            [$network, $prefix] = explode('/', $cidr, 2);
            $bytes = @inet_pton($network);
            $maximum = is_string($bytes) ? (strlen($bytes) === 4 ? 32 : (strlen($bytes) === 16 ? 128 : -1)) : -1;
            if ($maximum < 0 || !ctype_digit($prefix) || (int)$prefix > $maximum) {
                throw new InvalidArgumentException('Invalid API token CIDR restriction.');
            }
            $normalized[inet_ntop($bytes) . '/' . (int)$prefix] = true;
        }
        $result = implode(',', array_keys($normalized));
        if (strlen($result) > 2000) {
            throw new InvalidArgumentException('API token CIDR restrictions are too long.');
        }
        return $result;
    }

    private function validateQuota(?int $requests, ?int $windowSeconds): void
    {
        if (($requests === null) !== ($windowSeconds === null)
            || ($requests !== null && ($requests < 1 || $requests > 1000000))
            || ($windowSeconds !== null && ($windowSeconds < 1 || $windowSeconds > 86400))) {
            throw new InvalidArgumentException('Invalid API token quota.');
        }
    }

    /** @return array{string, string, string} */
    private function generateToken(): array
    {
        $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $token = 'slt_' . $secret;
        return [$token, substr($token, 0, 12), hash('sha256', $token)];
    }

    private function isExpired(mixed $expiresAt): bool
    {
        if (!is_string($expiresAt) || $expiresAt === '') {
            return false;
        }
        try {
            return new DateTimeImmutable($expiresAt) <= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        } catch (Throwable) {
            return true;
        }
    }

    private function recordUsage(
        ?int $tokenId,
        string $name,
        string $prefix,
        string $outcome,
        string $endpoint,
        string $requestId,
        int $failedRecordLimit = 1000
    ): void {
        with_sqlite_retry(function () use (
            $tokenId,
            $name,
            $prefix,
            $outcome,
            $endpoint,
            $requestId,
            $failedRecordLimit
        ): void {
            $now = utc_timestamp();
            $this->pdo->exec('BEGIN IMMEDIATE');
            try {
                $insert = $this->pdo->prepare(<<<'SQL'
                    INSERT INTO api_token_usage (
                        token_id, token_name, token_prefix, used_at, outcome, endpoint, request_id
                    ) VALUES (
                        :token_id, :token_name, :token_prefix, :used_at, :outcome, :endpoint, :request_id
                    )
                SQL);
                $insert->execute([
                    'token_id' => $tokenId,
                    'token_name' => limit_text($name, 60),
                    'token_prefix' => limit_text($prefix, 16),
                    'used_at' => $now,
                    'outcome' => $outcome,
                    'endpoint' => limit_text($endpoint, 120),
                    'request_id' => limit_text($requestId, 64),
                ]);
                if ($tokenId !== null && $outcome === 'accepted') {
                    $update = $this->pdo->prepare(<<<'SQL'
                        UPDATE api_tokens
                        SET last_used_at = :last_used_at, use_count = use_count + 1
                        WHERE id = :id
                    SQL);
                    $update->execute(['last_used_at' => $now, 'id' => $tokenId]);
                } elseif ($outcome !== 'accepted') {
                    $prune = $this->pdo->prepare(<<<'SQL'
                        DELETE FROM api_token_usage
                        WHERE outcome <> 'accepted'
                          AND id NOT IN (
                              SELECT id FROM api_token_usage
                              WHERE outcome <> 'accepted'
                              ORDER BY used_at DESC, id DESC LIMIT :record_limit
                          )
                    SQL);
                    $prune->bindValue(':record_limit', max(1, min(100000, $failedRecordLimit)), PDO::PARAM_INT);
                    $prune->execute();
                }
                $this->pdo->commit();
            } catch (Throwable $exception) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $exception;
            }
        });
    }
}
