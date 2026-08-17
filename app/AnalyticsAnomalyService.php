<?php

declare(strict_types=1);

final class AnalyticsAnomalyService
{
    public function __construct(private readonly PDO $pdo, private readonly array $config)
    {
    }

    /** @return array<int, array<string, mixed>> */
    public function detect(): array
    {
        $closedEnd = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $closedEnd = $closedEnd->setTime((int)$closedEnd->format('H'), 0);
        $latestStart = $closedEnd->modify('-1 hour');
        $baselineStart = $latestStart->modify('-24 hours');
        $zeroHours = max(2, min(72, (int)($this->config['analytics_anomaly_zero_hours'] ?? 6)));
        $zeroStart = $closedEnd->modify("-{$zeroHours} hours");
        $zeroBaselineStart = $zeroStart->modify('-24 hours');
        $minimum = max(1, (int)($this->config['analytics_anomaly_min_requests'] ?? 20));
        $spikeFactor = max(1.1, (float)($this->config['analytics_anomaly_spike_factor'] ?? 3));
        $botThreshold = min(1, max(0.01, (float)($this->config['analytics_anomaly_bot_ratio'] ?? 0.8)));

        $latest = $this->counts($latestStart, $closedEnd);
        $baseline = $this->counts($baselineStart, $latestStart);
        $zeroWindow = $this->counts($zeroStart, $closedEnd);
        $zeroBaseline = $this->counts($zeroBaselineStart, $zeroStart);
        $baselineAverage = $baseline['requests'] / 24;
        $botRatio = $latest['requests'] > 0 ? $latest['bots'] / $latest['requests'] : 0.0;
        $runtime = linkvault_analytics_status($this->config);
        $dataComplete = !empty($runtime['data_complete']);

        return [
            [
                'type' => 'traffic_spike',
                'title' => '访问突然暴增',
                'active' => $dataComplete && $latest['requests'] >= $minimum
                    && $baselineAverage > 0
                    && $latest['requests'] >= $baselineAverage * $spikeFactor,
                'value' => (string)$latest['requests'],
                'context' => [
                    'closed_hour' => $latestStart->format('c'),
                    'requests' => $latest['requests'],
                    'baseline_hourly_average' => round($baselineAverage, 2),
                    'factor' => $baselineAverage > 0 ? round($latest['requests'] / $baselineAverage, 2) : null,
                ],
            ],
            [
                'type' => 'traffic_zero',
                'title' => '访问持续归零',
                'active' => $dataComplete && $zeroWindow['requests'] === 0
                    && $zeroBaseline['requests'] >= $minimum,
                'value' => (string)$zeroWindow['requests'],
                'context' => [
                    'hours' => $zeroHours,
                    'requests' => $zeroWindow['requests'],
                    'previous_24h_requests' => $zeroBaseline['requests'],
                ],
            ],
            [
                'type' => 'bot_ratio',
                'title' => '机器人比例异常',
                'active' => $dataComplete && $latest['requests'] >= $minimum && $botRatio >= $botThreshold,
                'value' => number_format($botRatio, 4, '.', ''),
                'context' => [
                    'closed_hour' => $latestStart->format('c'),
                    'requests' => $latest['requests'],
                    'bot_requests' => $latest['bots'],
                    'bot_ratio' => round($botRatio, 4),
                    'threshold' => $botThreshold,
                ],
            ],
            [
                'type' => 'aggregation_stopped',
                'title' => '分析任务停止',
                'active' => !$dataComplete,
                'value' => (string)($runtime['collection_state'] ?? $runtime['reason'] ?? 'ok'),
                'context' => [
                    'reason' => $runtime['reason'] ?? null,
                    'collection_state' => $runtime['collection_state'] ?? null,
                    'last_success_at' => $runtime['last_success_at'] ?? $runtime['completed_at'] ?? null,
                    'consecutive_failures' => (int)($runtime['consecutive_failures'] ?? 0),
                    'backlog_bytes' => (int)($runtime['backlog_bytes'] ?? 0),
                ],
            ],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function pending(array $anomalies): array
    {
        $states = [];
        foreach ($this->pdo->query('SELECT * FROM analytics_alert_state') as $row) {
            $states[(string)$row['anomaly_type']] = $row;
        }
        $cooldown = max(300, (int)($this->config['analytics_anomaly_cooldown_seconds'] ?? 21600));
        $pending = [];
        foreach ($anomalies as $anomaly) {
            if (empty($anomaly['active'])) {
                continue;
            }
            $state = $states[(string)$anomaly['type']] ?? null;
            $lastNotified = 0;
            if (is_array($state) && is_string($state['last_notified_at'] ?? null)) {
                $parsed = strtotime((string)$state['last_notified_at']);
                $lastNotified = $parsed === false ? 0 : $parsed;
            }
            if (!is_array($state) || (int)$state['is_active'] === 0 || $lastNotified <= time() - $cooldown) {
                $pending[] = $anomaly;
            }
        }
        return $pending;
    }

    public function synchronize(array $anomalies, array $notifiedTypes): void
    {
        $notified = array_fill_keys($notifiedTypes, true);
        $statement = $this->pdo->prepare(<<<'SQL'
            INSERT INTO analytics_alert_state (
                anomaly_type, is_active, last_notified_at, last_value, updated_at
            ) VALUES (
                :anomaly_type, :is_active, :last_notified_at, :last_value, :updated_at
            )
            ON CONFLICT(anomaly_type) DO UPDATE SET
                is_active = excluded.is_active,
                last_notified_at = COALESCE(excluded.last_notified_at, analytics_alert_state.last_notified_at),
                last_value = excluded.last_value,
                updated_at = excluded.updated_at
        SQL);
        $now = gmdate('c');
        $this->pdo->beginTransaction();
        try {
            foreach ($anomalies as $anomaly) {
                $type = (string)$anomaly['type'];
                $statement->execute([
                    'anomaly_type' => $type,
                    'is_active' => empty($anomaly['active']) ? 0 : 1,
                    'last_notified_at' => isset($notified[$type]) ? $now : null,
                    'last_value' => substr((string)$anomaly['value'], 0, 200),
                    'updated_at' => $now,
                ]);
            }
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @return array{requests: int, bots: int} */
    private function counts(DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $statement = $this->pdo->prepare(<<<'SQL'
            SELECT COALESCE(SUM(clicks), 0) AS requests,
                   COALESCE(SUM(CASE WHEN visitor_kind = 'bot' THEN clicks ELSE 0 END), 0)
                       AS bots
            FROM visitor_hourly_stats
            WHERE accessed_hour >= :start AND accessed_hour < :end
        SQL);
        $statement->execute([
            'start' => $start->format('Y-m-d\TH:00:00\Z'),
            'end' => $end->format('Y-m-d\TH:00:00\Z'),
        ]);
        $row = $statement->fetch() ?: [];
        return ['requests' => (int)($row['requests'] ?? 0), 'bots' => (int)($row['bots'] ?? 0)];
    }
}
