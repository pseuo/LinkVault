<?php

declare(strict_types=1);

require_once __DIR__ . '/WebhookClient.php';

final class LifecycleWebhookService
{
    public static function enabled(array $config): bool
    {
        return trim((string)($config['lifecycle_webhook_url'] ?? '')) !== ''
            && strlen((string)($config['lifecycle_webhook_signing_secret'] ?? '')) >= 32;
    }

    public static function enqueue(
        PDO $pdo,
        array $config,
        string $eventType,
        int $linkId,
        string $dedupeKey,
        array $details = []
    ): bool {
        if (!self::enabled($config)) {
            return false;
        }
        $linkStatement = $pdo->prepare(<<<'SQL'
            SELECT l.id, l.slug, l.title, l.expires_at, l.is_active, l.target_url,
                   d.hostname AS short_domain_hostname
            FROM links l
            LEFT JOIN short_domains d ON d.id = l.short_domain_id
            WHERE l.id = :id
        SQL);
        $linkStatement->execute(['id' => $linkId]);
        $link = $linkStatement->fetch();
        if (!$link) {
            return false;
        }

        $eventId = bin2hex(random_bytes(16));
        $occurredAt = utc_timestamp();
        $hostname = trim((string)($link['short_domain_hostname'] ?? ''));
        $shortBaseUrl = $hostname === '' ? base_url($config) : 'https://' . $hostname;
        $targetHost = strtolower((string)(parse_url((string)$link['target_url'], PHP_URL_HOST) ?? ''));
        $payload = json_encode([
            'event_id' => $eventId,
            'type' => $eventType,
            'occurred_at' => $occurredAt,
            'data' => [
                'link' => [
                    'id' => (int)$link['id'],
                    'slug' => (string)$link['slug'],
                    'title' => (string)$link['title'],
                    'short_url' => rtrim($shortBaseUrl, '/') . '/' . rawurlencode((string)$link['slug']),
                    'expires_at' => $link['expires_at'],
                    'active' => (int)$link['is_active'] === 1,
                    'target_host' => $targetHost,
                ],
                'details' => $details,
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $insert = $pdo->prepare(<<<'SQL'
            INSERT OR IGNORE INTO webhook_outbox (
                event_id, event_type, link_id, dedupe_key, payload_json,
                status, attempts, available_at, created_at
            ) VALUES (
                :event_id, :event_type, :link_id, :dedupe_key, :payload_json,
                'pending', 0, :available_at, :created_at
            )
        SQL);
        $insert->execute([
            'event_id' => $eventId,
            'event_type' => $eventType,
            'link_id' => $linkId,
            'dedupe_key' => limit_text($dedupeKey, 255),
            'payload_json' => $payload,
            'available_at' => $occurredAt,
            'created_at' => $occurredAt,
        ]);
        return $insert->rowCount() === 1;
    }

    public static function enqueueExpiring(PDO $pdo, array $config, int $days): int
    {
        if (!self::enabled($config)) {
            return 0;
        }
        $now = utc_timestamp();
        $before = gmdate('Y-m-d\TH:i:s\Z', time() + max(1, $days) * 86400);
        $statement = $pdo->prepare(<<<'SQL'
            SELECT id, expires_at FROM links
            WHERE deleted_at IS NULL AND is_active = 1
              AND expires_at IS NOT NULL AND expires_at > :now AND expires_at <= :expires_before
            ORDER BY expires_at ASC, id ASC
        SQL);
        $statement->execute(['now' => $now, 'expires_before' => $before]);
        $queued = 0;
        foreach ($statement as $link) {
            $queued += self::enqueue(
                $pdo,
                $config,
                'link.expiring',
                (int)$link['id'],
                'link.expiring:' . (int)$link['id'] . ':' . (string)$link['expires_at'],
                ['window_days' => max(1, $days)]
            ) ? 1 : 0;
        }
        return $queued;
    }

    public function __construct(
        private readonly PDO $pdo,
        private readonly array $config,
        private readonly ?WebhookClient $client = null
    ) {
    }

    public function dispatch(int $limit = 50): array
    {
        if (!self::enabled($this->config)) {
            return ['delivered' => 0, 'retried' => 0, 'dead' => 0];
        }
        $client = $this->client ?? new WebhookClient();
        $result = ['delivered' => 0, 'retried' => 0, 'dead' => 0];
        for ($index = 0; $index < max(1, min(500, $limit)); $index++) {
            $event = $this->claimNext();
            if ($event === null) {
                break;
            }
            $startedAt = microtime(true);
            $httpStatus = null;
            $deliveryError = null;
            try {
                $timestamp = (string)$event['created_at'];
                $signature = hash_hmac(
                    'sha256',
                    $timestamp . '.' . (string)$event['event_id'] . '.' . (string)$event['payload_json'],
                    (string)$this->config['lifecycle_webhook_signing_secret']
                );
                $httpStatus = $client->postJson(
                    (string)$this->config['lifecycle_webhook_url'],
                    (string)$event['payload_json'],
                    (string)($this->config['lifecycle_webhook_bearer_token'] ?? ''),
                    [
                        'X-LinkVault-Event-ID: ' . (string)$event['event_id'],
                        'X-LinkVault-Event-Type: ' . (string)$event['event_type'],
                        'X-LinkVault-Timestamp: ' . $timestamp,
                        'X-LinkVault-Signature: v1=' . $signature,
                    ]
                );
                if ($httpStatus < 200 || $httpStatus >= 300) {
                    throw new RuntimeException('Webhook returned HTTP ' . $httpStatus . '.');
                }
            } catch (Throwable $exception) {
                $deliveryError = $exception;
            }
            $this->recordAttempt(
                (string)$event['event_id'],
                (int)$event['attempts'] + 1,
                $httpStatus,
                max(0, (int)round((microtime(true) - $startedAt) * 1000)),
                $deliveryError?->getMessage()
            );
            if ($deliveryError === null) {
                $this->complete((string)$event['event_id']);
                $result['delivered']++;
            } else {
                $dead = $this->fail((string)$event['event_id'], (int)$event['attempts'] + 1, $deliveryError->getMessage());
                $result[$dead ? 'dead' : 'retried']++;
            }
        }
        return $result;
    }

    private function claimNext(): ?array
    {
        return with_sqlite_retry(function (): ?array {
            $this->pdo->exec('BEGIN IMMEDIATE');
            try {
                $now = utc_timestamp();
                $statement = $this->pdo->prepare(<<<'SQL'
                    SELECT * FROM webhook_outbox
                    WHERE status = 'pending' AND available_at <= :now
                      AND (leased_until IS NULL OR leased_until <= :now)
                    ORDER BY created_at ASC LIMIT 1
                SQL);
                $statement->execute(['now' => $now]);
                $event = $statement->fetch();
                if (!$event) {
                    $this->pdo->commit();
                    return null;
                }
                $lease = $this->pdo->prepare(<<<'SQL'
                    UPDATE webhook_outbox SET leased_until = :leased_until
                    WHERE event_id = :event_id AND status = 'pending'
                      AND (leased_until IS NULL OR leased_until <= :now)
                SQL);
                $lease->execute([
                    'leased_until' => gmdate('Y-m-d\TH:i:s\Z', time() + 60),
                    'event_id' => $event['event_id'],
                    'now' => $now,
                ]);
                $this->pdo->commit();
                return $lease->rowCount() === 1 ? $event : null;
            } catch (Throwable $exception) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $exception;
            }
        });
    }

    private function complete(string $eventId): void
    {
        $statement = $this->pdo->prepare(<<<'SQL'
            UPDATE webhook_outbox
            SET status = 'delivered', attempts = attempts + 1, delivered_at = :delivered_at,
                leased_until = NULL, last_error = NULL
            WHERE event_id = :event_id AND status = 'pending'
        SQL);
        with_sqlite_retry(fn () => $statement->execute(['delivered_at' => utc_timestamp(), 'event_id' => $eventId]));
    }

    private function fail(string $eventId, int $attempts, string $error): bool
    {
        $dead = $attempts >= 8;
        $delay = min(21600, 30 * (2 ** min(10, max(0, $attempts - 1)))) + random_int(0, 15);
        $statement = $this->pdo->prepare(<<<'SQL'
            UPDATE webhook_outbox
            SET status = :status, attempts = :attempts, available_at = :available_at,
                leased_until = NULL, last_error = :last_error
            WHERE event_id = :event_id AND status = 'pending'
        SQL);
        with_sqlite_retry(fn () => $statement->execute([
            'status' => $dead ? 'dead' : 'pending',
            'attempts' => $attempts,
            'available_at' => gmdate('Y-m-d\TH:i:s\Z', time() + $delay),
            'last_error' => limit_text($error, 300),
            'event_id' => $eventId,
        ]));
        return $dead;
    }

    private function recordAttempt(
        string $eventId,
        int $attemptNumber,
        ?int $httpStatus,
        int $durationMs,
        ?string $error
    ): void {
        $statement = $this->pdo->prepare(<<<'SQL'
            INSERT INTO webhook_delivery_attempts (
                event_id, attempt_number, attempted_at, http_status, duration_ms, error
            ) VALUES (
                :event_id, :attempt_number, :attempted_at, :http_status, :duration_ms, :error
            )
        SQL);
        with_sqlite_retry(function () use (
            $statement, $eventId, $attemptNumber, $httpStatus, $durationMs, $error
        ): void {
            $this->pdo->exec('BEGIN IMMEDIATE');
            try {
                $statement->execute([
                    'event_id' => $eventId,
                    'attempt_number' => $attemptNumber,
                    'attempted_at' => utc_timestamp(),
                    'http_status' => $httpStatus,
                    'duration_ms' => $durationMs,
                    'error' => $error === null ? null : limit_text($error, 300),
                ]);
                $prune = $this->pdo->prepare(<<<'SQL'
                    DELETE FROM webhook_delivery_attempts
                    WHERE event_id = :event_id AND id NOT IN (
                        SELECT id FROM webhook_delivery_attempts
                        WHERE event_id = :event_id_lookup ORDER BY id DESC LIMIT 20
                    )
                SQL);
                $prune->execute(['event_id' => $eventId, 'event_id_lookup' => $eventId]);
                $this->pdo->commit();
            } catch (Throwable $exception) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $exception;
            }
        });
    }

    public function deliveries(string $status = 'all', int $limit = 100): array
    {
        $status = in_array($status, ['pending', 'delivered', 'dead'], true) ? $status : 'all';
        $sql = 'SELECT o.*, l.slug, l.title FROM webhook_outbox o LEFT JOIN links l ON l.id = o.link_id';
        $parameters = [];
        if ($status !== 'all') {
            $sql .= ' WHERE o.status = :status';
            $parameters['status'] = $status;
        }
        $sql .= ' ORDER BY o.created_at DESC LIMIT :item_limit';
        $statement = $this->pdo->prepare($sql);
        foreach ($parameters as $name => $value) {
            $statement->bindValue(':' . $name, $value);
        }
        $statement->bindValue(':item_limit', max(1, min(500, $limit)), PDO::PARAM_INT);
        $statement->execute();
        $deliveries = $statement->fetchAll();
        $attempts = $this->pdo->prepare(<<<'SQL'
            SELECT attempt_number, attempted_at, http_status, duration_ms, error
            FROM webhook_delivery_attempts
            WHERE event_id = :event_id ORDER BY id DESC LIMIT 20
        SQL);
        foreach ($deliveries as &$delivery) {
            $attempts->execute(['event_id' => $delivery['event_id']]);
            $delivery['attempt_history'] = $attempts->fetchAll();
        }
        unset($delivery);
        return $deliveries;
    }

    public function deliveryCounts(): array
    {
        $counts = ['pending' => 0, 'delivered' => 0, 'dead' => 0];
        foreach ($this->pdo->query('SELECT status, COUNT(*) AS total FROM webhook_outbox GROUP BY status') as $row) {
            $counts[(string)$row['status']] = (int)$row['total'];
        }
        return $counts;
    }

    public function replayDead(string $eventId): bool
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $eventId) !== 1) {
            return false;
        }
        $statement = $this->pdo->prepare(<<<'SQL'
            UPDATE webhook_outbox
            SET status = 'pending', attempts = 0, available_at = :available_at,
                leased_until = NULL, last_error = NULL, delivered_at = NULL,
                replay_count = replay_count + 1
            WHERE event_id = :event_id AND status = 'dead'
        SQL);
        with_sqlite_retry(fn () => $statement->execute([
            'available_at' => utc_timestamp(),
            'event_id' => $eventId,
        ]));
        return $statement->rowCount() === 1;
    }
}
