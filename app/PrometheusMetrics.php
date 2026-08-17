<?php

declare(strict_types=1);

final class PrometheusMetrics
{
    public static function render(PDO $pdo, array $status): string
    {
        $redirectRequests = self::scalar($pdo, 'SELECT COALESCE(SUM(clicks), 0) FROM links');
        $exportBacklog = self::scalar(
            $pdo,
            "SELECT COUNT(*) FROM analytics_export_jobs WHERE status IN ('pending', 'running')"
        );
        $webhook = (array)($status['lifecycle_webhook'] ?? []);
        $analytics = (array)($status['analytics'] ?? []);
        $target = (array)($status['target_health'] ?? []);
        $writeLock = (array)($status['write_lock'] ?? []);
        $targetProcessed = max(0, (int)($target['processed'] ?? 0));
        $targetIssues = max(0, (int)($target['issues'] ?? 0));
        $targetFailureRatio = $targetProcessed > 0 ? $targetIssues / $targetProcessed : 0.0;
        $redirectLatency = 0;
        foreach ((array)($status['synthetic_monitor']['probes'] ?? []) as $probe) {
            if (($probe['id'] ?? null) === 'canary' && is_int($probe['latency_ms'] ?? null)) {
                $redirectLatency = max(0, (int)$probe['latency_ms']);
                break;
            }
        }

        $metrics = [
            ['linkvault_requests_total', 'counter', 'Completed public redirect requests.', $redirectRequests, ['route' => 'redirect']],
            ['linkvault_redirect_latency_seconds', 'gauge', 'Latest synthetic redirect latency.', $redirectLatency / 1000, ['source' => 'canary']],
            ['linkvault_sqlite_lock_wait_seconds', 'gauge', 'SQLite lock wait observed in the status window.', max(0, (int)($writeLock['average_wait_ms'] ?? 0)) / 1000, ['stat' => 'average']],
            ['linkvault_sqlite_lock_wait_seconds', 'gauge', 'SQLite lock wait observed in the status window.', max(0, (int)($writeLock['max_wait_ms'] ?? 0)) / 1000, ['stat' => 'maximum']],
            ['linkvault_sqlite_lock_failures', 'gauge', 'SQLite lock failures in the status window.', max(0, (int)($writeLock['failure_count'] ?? 0)), []],
            ['linkvault_queue_backlog', 'gauge', 'Pending work by queue.', $exportBacklog, ['queue' => 'analytics_exports']],
            ['linkvault_queue_backlog', 'gauge', 'Pending work by queue.', max(0, (int)($webhook['pending'] ?? 0)), ['queue' => 'webhook_outbox']],
            ['linkvault_webhook_dead_letters', 'gauge', 'Lifecycle webhook dead letters.', max(0, (int)($webhook['dead'] ?? 0)), []],
            ['linkvault_backup_age_seconds', 'gauge', 'Age of the latest successful backup, or -1 when unavailable.', self::age((array)($status['local_backup'] ?? [])), ['location' => 'local']],
            ['linkvault_backup_age_seconds', 'gauge', 'Age of the latest successful backup, or -1 when unavailable.', self::age((array)($status['remote_backup'] ?? [])), ['location' => 'remote']],
            ['linkvault_analytics_lag_seconds', 'gauge', 'Analytics consumer lag, or -1 when unavailable.', is_int($analytics['consumer_lag_seconds'] ?? null) ? max(0, (int)$analytics['consumer_lag_seconds']) : -1, []],
            ['linkvault_analytics_backlog_bytes', 'gauge', 'Unprocessed analytics log bytes.', max(0, (int)($analytics['backlog_bytes'] ?? 0)), []],
            ['linkvault_target_check_failure_ratio', 'gauge', 'Failure ratio in the latest target check batch.', $targetFailureRatio, []],
            ['linkvault_target_check_backlog', 'gauge', 'Due target checks not processed in the latest batch.', max(0, (int)($target['backlog'] ?? 0)), []],
        ];

        $lines = [];
        $documented = [];
        foreach ($metrics as [$name, $type, $help, $value, $labels]) {
            if (!isset($documented[$name])) {
                $lines[] = '# HELP ' . $name . ' ' . $help;
                $lines[] = '# TYPE ' . $name . ' ' . $type;
                $documented[$name] = true;
            }
            $labelText = '';
            if ($labels) {
                $pairs = [];
                foreach ($labels as $label => $labelValue) {
                    $escaped = str_replace(["\\", "\n", '"'], ["\\\\", "\\n", '\\"'], (string)$labelValue);
                    $pairs[] = $label . '="' . $escaped . '"';
                }
                $labelText = '{' . implode(',', $pairs) . '}';
            }
            $lines[] = $name . $labelText . ' ' . self::number((float)$value);
        }
        return implode("\n", $lines) . "\n";
    }

    private static function scalar(PDO $pdo, string $sql): int
    {
        try {
            return max(0, (int)$pdo->query($sql)->fetchColumn());
        } catch (Throwable) {
            return 0;
        }
    }

    private static function age(array $backup): int
    {
        $completedAt = (int)($backup['completed_at'] ?? 0);
        return $completedAt > 0 && $completedAt <= time() ? time() - $completedAt : -1;
    }

    private static function number(float $value): string
    {
        return is_finite($value) ? rtrim(rtrim(sprintf('%.6F', $value), '0'), '.') : '0';
    }
}
