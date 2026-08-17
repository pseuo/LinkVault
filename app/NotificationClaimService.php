<?php

declare(strict_types=1);

final class NotificationClaimService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function claim(string $type, string $dedupeKey, int $leaseSeconds = 300): bool
    {
        if ($type === '' || $dedupeKey === '' || $leaseSeconds < 1 || $leaseSeconds > 3600) {
            throw new InvalidArgumentException('Invalid notification claim.');
        }

        return (bool)with_sqlite_retry(function () use ($type, $dedupeKey, $leaseSeconds): bool {
            $now = utc_timestamp();
            $statement = $this->pdo->prepare(<<<'SQL'
                INSERT INTO notification_claims (
                    notification_type, dedupe_key, leased_until, completed_at, last_error, updated_at
                ) VALUES (
                    :notification_type, :dedupe_key, :leased_until, NULL, NULL, :updated_at
                )
                ON CONFLICT(notification_type, dedupe_key) DO UPDATE SET
                    leased_until = excluded.leased_until,
                    last_error = NULL,
                    updated_at = excluded.updated_at
                WHERE notification_claims.completed_at IS NULL
                  AND notification_claims.leased_until <= :now
            SQL);
            $statement->execute([
                'notification_type' => $type,
                'dedupe_key' => $dedupeKey,
                'leased_until' => gmdate('Y-m-d\TH:i:s\Z', time() + $leaseSeconds),
                'updated_at' => $now,
                'now' => $now,
            ]);
            return $statement->rowCount() === 1;
        });
    }

    public function complete(string $type, string $dedupeKey): void
    {
        $statement = $this->pdo->prepare(<<<'SQL'
            UPDATE notification_claims
            SET completed_at = :completed_at, leased_until = :completed_at, updated_at = :completed_at
            WHERE notification_type = :notification_type AND dedupe_key = :dedupe_key
              AND completed_at IS NULL
        SQL);
        with_sqlite_retry(fn () => $statement->execute([
            'completed_at' => utc_timestamp(),
            'notification_type' => $type,
            'dedupe_key' => $dedupeKey,
        ]));
    }

    public function release(string $type, string $dedupeKey, string $error): void
    {
        $statement = $this->pdo->prepare(<<<'SQL'
            UPDATE notification_claims
            SET leased_until = :released_at, last_error = :last_error, updated_at = :released_at
            WHERE notification_type = :notification_type AND dedupe_key = :dedupe_key
              AND completed_at IS NULL
        SQL);
        with_sqlite_retry(fn () => $statement->execute([
            'released_at' => utc_timestamp(),
            'last_error' => limit_text($error, 300),
            'notification_type' => $type,
            'dedupe_key' => $dedupeKey,
        ]));
    }
}
