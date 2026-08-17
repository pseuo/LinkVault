<?php

declare(strict_types=1);

final class AnalyticsExportJobService
{
    private const MAX_ATTEMPTS = 3;

    public function __construct(
        private readonly PDO $pdo,
        private readonly array $config,
        private readonly AnalyticsReportService $reports,
    ) {
    }

    public function enqueue(string $ownerHash, string $report, array $request): string
    {
        $report = in_array($report, ['filtered', 'trend', 'sources', 'devices', 'countries', 'campaigns'], true)
            ? $report : 'filtered';
        $id = bin2hex(random_bytes(16));
        $now = utc_timestamp();
        $retentionHours = max(1, min(168, (int)($this->config['analytics_export_retention_hours'] ?? 24)));
        $statement = $this->pdo->prepare(<<<'SQL'
            INSERT INTO analytics_export_jobs (
                id, owner_hash, report, request_json, status, available_at, created_at, expires_at
            ) VALUES (
                :id, :owner_hash, :report, :request_json, 'pending', :available_at, :created_at, :expires_at
            )
        SQL);
        with_sqlite_retry(fn () => $statement->execute([
            'id' => $id,
            'owner_hash' => $ownerHash,
            'report' => $report,
            'request_json' => json_encode($this->reports->queryParameters($request), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'available_at' => $now,
            'created_at' => $now,
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + $retentionHours * 3600),
        ]));
        return $id;
    }

    /** @return array<string, mixed>|null */
    public function status(string $id, string $ownerHash): ?array
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $id) !== 1) {
            return null;
        }
        $statement = $this->pdo->prepare(<<<'SQL'
            SELECT id, report, status, row_count, size_bytes, last_error, created_at,
                   started_at, completed_at, expires_at, artifact_name, download_name
            FROM analytics_export_jobs
            WHERE id = :id AND owner_hash = :owner_hash
        SQL);
        $statement->execute(['id' => $id, 'owner_hash' => $ownerHash]);
        $job = $statement->fetch();
        return is_array($job) ? $job : null;
    }

    /** @return array<string, mixed> */
    public function process(int $limit = 5): array
    {
        $this->cleanupExpired();
        $result = ['completed' => 0, 'retried' => 0, 'failed' => 0];
        for ($index = 0; $index < max(1, min(50, $limit)); $index++) {
            $job = $this->claimNext();
            if ($job === null) {
                break;
            }
            try {
                $this->generate($job);
                $result['completed']++;
            } catch (Throwable $exception) {
                $failed = $this->fail(
                    (string)$job['id'],
                    (string)$job['lease_token'],
                    (int)$job['attempts'] + 1,
                    $exception
                );
                $result[$failed ? 'failed' : 'retried']++;
            }
        }
        return $result;
    }

    public function cleanupExpired(): int
    {
        $expireActive = $this->pdo->prepare(<<<'SQL'
            UPDATE analytics_export_jobs
            SET status = 'failed', leased_until = NULL, lease_token = NULL,
                last_error = 'Export expired before completion.'
            WHERE expires_at <= :now AND status IN ('pending', 'running')
        SQL);
        with_sqlite_retry(fn () => $expireActive->execute(['now' => utc_timestamp()]));
        $statement = $this->pdo->prepare(<<<'SQL'
            SELECT id, artifact_name FROM analytics_export_jobs
            WHERE expires_at <= :now AND status IN ('completed', 'failed')
            ORDER BY expires_at ASC LIMIT 500
        SQL);
        $statement->execute(['now' => utc_timestamp()]);
        $ids = [];
        foreach ($statement as $job) {
            $ids[] = (string)$job['id'];
            $name = (string)($job['artifact_name'] ?? '');
            if (preg_match('/^[a-f0-9]{32}(?:\.[a-f0-9]{32})?\.csv$/D', $name) === 1) {
                @unlink($this->exportDirectory() . DIRECTORY_SEPARATOR . $name);
            }
        }
        if ($ids === []) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $delete = $this->pdo->prepare("DELETE FROM analytics_export_jobs WHERE id IN ({$placeholders})");
        with_sqlite_retry(fn () => $delete->execute($ids));
        return $delete->rowCount();
    }

    /** @return array<string, mixed>|null */
    private function claimNext(): ?array
    {
        return with_sqlite_retry(function (): ?array {
            $this->pdo->exec('BEGIN IMMEDIATE');
            try {
                $now = utc_timestamp();
                $statement = $this->pdo->prepare(<<<'SQL'
                    SELECT * FROM analytics_export_jobs
                    WHERE status IN ('pending', 'running') AND available_at <= :now
                      AND (leased_until IS NULL OR leased_until <= :now)
                      AND expires_at > :now
                    ORDER BY created_at ASC LIMIT 1
                SQL);
                $statement->execute(['now' => $now]);
                $job = $statement->fetch();
                if (!is_array($job)) {
                    $this->pdo->commit();
                    return null;
                }
                $leaseSeconds = $this->configuredLeaseSeconds();
                $leaseToken = bin2hex(random_bytes(16));
                $lease = $this->pdo->prepare(<<<'SQL'
                    UPDATE analytics_export_jobs
                    SET status = 'running', leased_until = :leased_until, lease_token = :lease_token,
                        started_at = COALESCE(started_at, :started_at)
                    WHERE id = :id AND status IN ('pending', 'running')
                      AND (leased_until IS NULL OR leased_until <= :now)
                SQL);
                $lease->execute([
                    'leased_until' => gmdate('Y-m-d\TH:i:s\Z', time() + $leaseSeconds),
                    'lease_token' => $leaseToken,
                    'started_at' => $now,
                    'id' => $job['id'],
                    'now' => $now,
                ]);
                $this->pdo->commit();
                if ($lease->rowCount() !== 1) {
                    return null;
                }
                $job['lease_token'] = $leaseToken;
                $job['leased_until'] = gmdate('Y-m-d\TH:i:s\Z', time() + $leaseSeconds);
                return $job;
            } catch (Throwable $exception) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $exception;
            }
        });
    }

    /** @param array<string, mixed> $job */
    private function generate(array $job): void
    {
        $stored = json_decode((string)$job['request_json'], true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($stored)) {
            throw new RuntimeException('Stored analytics export request is invalid.');
        }
        $request = $this->reports->normalizeRequest($stored);
        $maxRows = max(1, min(1000000, (int)($this->config['analytics_export_max_rows'] ?? 500000)));
        $this->renewLease((string)$job['id'], (string)$job['lease_token']);
        $export = $this->reports->export((string)$job['report'], $request, $maxRows);
        $this->renewLease((string)$job['id'], (string)$job['lease_token']);
        $directory = $this->exportDirectory();
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create analytics export directory.');
        }
        $artifact = (string)$job['id'] . '.' . (string)$job['lease_token'] . '.csv';
        $temporary = $directory . DIRECTORY_SEPARATOR . $artifact . '.tmp';
        $final = $directory . DIRECTORY_SEPARATOR . $artifact;
        $output = fopen($temporary, 'wb');
        if (!is_resource($output)) {
            throw new RuntimeException('Cannot open analytics export artifact.');
        }
        $rows = 0;
        $renewAt = time() + max(1, intdiv($this->configuredLeaseSeconds(), 3));
        $writeError = null;
        try {
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, $export['headers']);
            foreach ($export['rows'] as $row) {
                fputcsv($output, array_map(
                    static fn (int|string $value): int|string => is_string($value) ? csv_safe_cell($value) : $value,
                    $row
                ));
                $rows++;
                if (time() >= $renewAt) {
                    $this->renewLease((string)$job['id'], (string)$job['lease_token']);
                    $renewAt = time() + max(1, intdiv($this->configuredLeaseSeconds(), 3));
                }
            }
        } catch (Throwable $exception) {
            $writeError = $exception;
        } finally {
            fclose($output);
        }
        if ($writeError instanceof Throwable) {
            @unlink($temporary);
            throw $writeError;
        }
        try {
            chmod($temporary, 0600);
            $this->renewLease((string)$job['id'], (string)$job['lease_token']);
            if (!rename($temporary, $final)) {
                throw new RuntimeException('Cannot publish analytics export artifact.');
            }
        } catch (Throwable $exception) {
            @unlink($temporary);
            throw $exception;
        }
        $size = filesize($final);
        $statement = $this->pdo->prepare(<<<'SQL'
            UPDATE analytics_export_jobs
            SET status = 'completed', attempts = attempts + 1, leased_until = NULL, lease_token = NULL,
                row_count = :row_count, artifact_name = :artifact_name,
                download_name = :download_name, size_bytes = :size_bytes,
                last_error = NULL, completed_at = :completed_at
            WHERE id = :id AND status = 'running' AND lease_token = :lease_token
        SQL);
        with_sqlite_retry(fn () => $statement->execute([
            'row_count' => $rows,
            'artifact_name' => $artifact,
            'download_name' => (string)$export['filename'],
            'size_bytes' => is_int($size) ? $size : 0,
            'completed_at' => utc_timestamp(),
            'id' => $job['id'],
            'lease_token' => $job['lease_token'],
        ]));
        if ($statement->rowCount() !== 1) {
            @unlink($final);
            throw new RuntimeException('Analytics export lease expired before completion.');
        }
    }

    private function fail(string $id, string $leaseToken, int $attempts, Throwable $error): bool
    {
        $failed = $attempts >= self::MAX_ATTEMPTS || $error instanceof AnalyticsExportLimitExceeded;
        $statement = $this->pdo->prepare(<<<'SQL'
            UPDATE analytics_export_jobs
            SET status = :status, attempts = :attempts, available_at = :available_at,
                leased_until = NULL, lease_token = NULL, last_error = :last_error
            WHERE id = :id AND status = 'running' AND lease_token = :lease_token
        SQL);
        with_sqlite_retry(fn () => $statement->execute([
            'status' => $failed ? 'failed' : 'pending',
            'attempts' => $attempts,
            'available_at' => gmdate('Y-m-d\TH:i:s\Z', time() + min(900, 30 * (2 ** max(0, $attempts - 1)))),
            'last_error' => limit_text($error->getMessage(), 300),
            'id' => $id,
            'lease_token' => $leaseToken,
        ]));
        return $failed;
    }

    public function artifactPath(array $job): ?string
    {
        $name = (string)($job['artifact_name'] ?? '');
        if (preg_match('/^[a-f0-9]{32}(?:\.[a-f0-9]{32})?\.csv$/D', $name) !== 1) {
            return null;
        }
        $path = $this->exportDirectory() . DIRECTORY_SEPARATOR . $name;
        return is_file($path) ? $path : null;
    }

    private function exportDirectory(): string
    {
        return rtrim((string)($this->config['analytics_export_directory'] ?? dirname(__DIR__) . '/data/analytics-exports'), '/\\');
    }

    private function configuredLeaseSeconds(): int
    {
        return max(60, min(3600, (int)($this->config['analytics_export_lease_seconds'] ?? 900)));
    }

    private function renewLease(string $id, string $leaseToken): void
    {
        $statement = $this->pdo->prepare(<<<'SQL'
            UPDATE analytics_export_jobs
            SET leased_until = :leased_until
            WHERE id = :id AND status = 'running' AND lease_token = :lease_token
        SQL);
        with_sqlite_retry(fn () => $statement->execute([
            'leased_until' => gmdate('Y-m-d\TH:i:s\Z', time() + $this->configuredLeaseSeconds()),
            'id' => $id,
            'lease_token' => $leaseToken,
        ]));
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Analytics export lease expired before completion.');
        }
    }
}
