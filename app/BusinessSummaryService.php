<?php

declare(strict_types=1);

final class BusinessSummaryService
{
    public function __construct(private readonly PDO $pdo, private readonly array $config)
    {
    }

    /** @return array<string, mixed> */
    public function build(string $frequency, ?DateTimeImmutable $now = null): array
    {
        $period = $this->period($frequency, $now);
        $reports = new AnalyticsReportService($this->pdo, $this->config);
        $request = $reports->normalizeRequest([
            'range' => 'custom',
            'start' => $period['start'],
            'end' => $period['end'],
            'timezone' => 'UTC',
        ]);
        $dashboard = $reports->dashboard($request);
        $links = new LinkService($this->pdo);
        $thresholds = linkvault_maintenance_thresholds($this->config);
        $evaluatedAt = utc_timestamp();

        return [
            'service' => 'linkvault',
            'event' => 'linkvault_business_summary',
            'occurred_at' => gmdate('c'),
            'base_url' => (string)($this->config['base_url'] ?? ''),
            'frequency' => $frequency,
            'period' => $period,
            'analytics' => [
                'totals' => $dashboard['totals'],
                'previous_totals' => $dashboard['previous_totals'],
                'deltas' => $dashboard['deltas'],
                'percent_changes' => $dashboard['percent_changes'],
                'trend' => $dashboard['trend'],
                'top_links' => $reports->topLinks($request),
                'anomalous_sources' => $reports->anomalousSources($request),
                'coverage' => $dashboard['coverage'],
            ],
            'new_links' => $this->newLinks($period),
            'anomalies' => array_values(array_map(
                static fn (array $anomaly): array => [
                    'type' => $anomaly['type'],
                    'title' => $anomaly['title'],
                    'context' => $anomaly['context'],
                ],
                array_filter((new AnalyticsAnomalyService($this->pdo, $this->config))->detect(),
                    static fn (array $anomaly): bool => !empty($anomaly['active']))
            )),
            'link_health' => $links->maintenanceCounts(
                $thresholds['expiring_days'],
                $thresholds['stale_days'],
                $thresholds['quota_percent'],
                $evaluatedAt
            ),
            'target_health' => linkvault_target_health_status($this->config),
            'backup' => linkvault_backup_maintenance_summary($this->config),
        ];
    }

    /** @return array{start: string, end: string, previous_start: string, previous_end: string} */
    private function period(string $frequency, ?DateTimeImmutable $now): array
    {
        $today = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));
        $today = $today->setTime(0, 0);
        if ($frequency === 'weekly') {
            $end = $today->modify('monday this week')->modify('-1 day');
            $start = $end->modify('-6 days');
        } elseif ($frequency === 'monthly') {
            $end = $today->modify('first day of this month')->modify('-1 day');
            $start = $end->modify('first day of this month');
        } else {
            throw new InvalidArgumentException('Business summary frequency must be weekly or monthly.');
        }
        $days = (int)$start->diff($end)->format('%a') + 1;
        $previousEnd = $start->modify('-1 day');
        return [
            'start' => $start->format('Y-m-d'),
            'end' => $end->format('Y-m-d'),
            'previous_start' => $previousEnd->modify('-' . ($days - 1) . ' days')->format('Y-m-d'),
            'previous_end' => $previousEnd->format('Y-m-d'),
        ];
    }

    /** @return array{current: int, previous: int, delta: int} */
    private function newLinks(array $period): array
    {
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM links WHERE created_at >= :start AND created_at < :end');
        $count->execute([
            'start' => $period['start'] . 'T00:00:00Z',
            'end' => (new DateTimeImmutable($period['end'], new DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d\T00:00:00\Z'),
        ]);
        $current = (int)$count->fetchColumn();
        $count->execute([
            'start' => $period['previous_start'] . 'T00:00:00Z',
            'end' => $period['start'] . 'T00:00:00Z',
        ]);
        $previous = (int)$count->fetchColumn();
        return ['current' => $current, 'previous' => $previous, 'delta' => $current - $previous];
    }
}
