<?php

declare(strict_types=1);

final class AnalyticsExportLimitExceeded extends RuntimeException
{
    public function __construct(public readonly int $limit)
    {
        parent::__construct("Analytics export exceeds {$limit} rows.");
    }
}

final class AnalyticsInvalidDateRange extends InvalidArgumentException
{
}

final class AnalyticsReportService
{
    private const EXPORT_MAX_ROWS = 50000;
    private const FILTER_LIMITS = [
        'tag' => 24,
        'campaign' => 100,
        'source' => 100,
        'medium' => 100,
        'referrer' => 180,
        'browser' => 40,
        'operating_system' => 40,
    ];

    private ?string $rankingBoundsTable = null;
    private ?bool $dailyDimensionsReady = null;
    private ?string $materializedRangeTable = null;
    private ?string $materializedRangeSignature = null;
    private ?int $schemaVersion = null;

    public function __construct(private readonly PDO $pdo, private readonly array $config = [])
    {
    }

    /** @return array<string, mixed> */
    public function normalizeRequest(array $input): array
    {
        $presetInput = (string)($input['range'] ?? $input['days'] ?? '30');
        $preset = in_array($presetInput, ['7', '30', '90', 'custom'], true) ? $presetInput : '30';
        $timezone = $this->normalizeTimezone((string)($input['timezone'] ?? 'UTC'));
        $zone = new DateTimeZone($timezone);
        $today = new DateTimeImmutable('today', $zone);

        if ($preset === 'custom') {
            $start = $this->parseDate((string)($input['start'] ?? ''), $zone);
            $end = $this->parseDate((string)($input['end'] ?? ''), $zone);
            if (!$start instanceof DateTimeImmutable || !$end instanceof DateTimeImmutable) {
                throw new AnalyticsInvalidDateRange('Custom analytics dates must use valid YYYY-MM-DD values.');
            }
            if ($start > $end || (int)$start->diff($end)->format('%a') > 3650) {
                throw new AnalyticsInvalidDateRange('Custom analytics date range is invalid or exceeds 3651 days.');
            }
        } else {
            $days = (int)$preset;
            $end = $today;
            $start = $today->modify('-' . ($days - 1) . ' days');
        }

        $days = (int)$start->diff($end)->format('%a') + 1;
        $endExclusive = $end->modify('+1 day');
        $previousEnd = $start->modify('-1 day');
        $previousStart = $start->modify("-{$days} days");
        $filters = [
            'link' => max(0, (int)($input['link'] ?? 0)),
            'tag' => $this->textFilter($input, 'tag'),
            'campaign' => $this->textFilter($input, 'campaign'),
            'source' => $this->textFilter($input, 'source'),
            'medium' => $this->textFilter($input, 'medium'),
            'referrer' => $this->textFilter($input, 'referrer'),
            'browser' => $this->textFilter($input, 'browser'),
            'operating_system' => $this->textFilter($input, 'operating_system'),
            'device' => in_array((string)($input['device'] ?? ''), ['desktop', 'mobile', 'tablet', 'other'], true)
                ? (string)$input['device'] : '',
            'country' => preg_match('/^[A-Za-z]{2,8}$/', (string)($input['country'] ?? '')) === 1
                ? strtoupper((string)$input['country']) : '',
            'traffic' => in_array((string)($input['traffic'] ?? ''), [
                'suspected_human', 'bot', 'scanner', 'automated', 'unknown',
            ], true) ? (string)$input['traffic'] : '',
        ];

        $current = $this->rangeData($start, $endExclusive, $timezone);
        $previous = $this->rangeData($previousStart, $start, $timezone);
        return [
            'preset' => $preset,
            'timezone' => $timezone,
            'days' => $days,
            'start' => $start->format('Y-m-d'),
            'end' => $end->format('Y-m-d'),
            'current' => $current,
            'previous' => $previous,
            'filters' => $filters,
        ];
    }

    /** @return array<string, string|int> */
    public function queryParameters(array $request): array
    {
        $parameters = [
            'section' => 'analytics',
            'range' => (string)$request['preset'],
            'start' => (string)$request['start'],
            'end' => (string)$request['end'],
            'timezone' => (string)$request['timezone'],
        ];
        foreach ((array)$request['filters'] as $key => $value) {
            if ($value !== '' && $value !== null && $value !== 0) {
                $parameters[$key] = is_int($value) ? $value : (string)$value;
            }
        }
        return $parameters;
    }

    /** @return array<string, mixed> */
    public function dashboard(array $request): array
    {
        $cached = $this->readDashboardCache($request);
        if ($cached !== null) {
            return $cached;
        }

        $this->materializeRange((array)$request['current']);
        try {
            $result = $this->computeDashboard($request);
        } finally {
            $this->materializedRangeTable = null;
            $this->materializedRangeSignature = null;
        }
        $this->writeDashboardCache($request, $result);
        return $result;
    }

    /** @return list<array<string, int|string>> */
    public function topLinks(array $request, int $limit = 5): array
    {
        $rows = [];
        foreach ($this->linkTotals((array)$request['current'], (array)$request['filters']) as $row) {
            $rows[] = [
                'link_id' => (int)$row['link_id'],
                'slug' => (string)$row['slug'],
                'title' => (string)$row['title'],
                'requests' => (int)$row['proxy_requests'],
            ];
        }
        usort($rows, static fn (array $left, array $right): int => $right['requests'] <=> $left['requests']
            ?: strcmp($left['slug'], $right['slug']));
        return array_slice($rows, 0, max(1, min(20, $limit)));
    }

    /** @return list<array<string, int|string|float>> */
    public function anomalousSources(array $request, int $limit = 5): array
    {
        [$cte, $params] = $this->sourceCte((array)$request['current']);
        [$where, $filterParams] = $this->filterSql((array)$request['filters']);
        $statement = $this->pdo->prepare($cte . <<<SQL
            SELECT referrer_domain, campaign_source, SUM(clicks) AS requests,
                   SUM(CASE WHEN visitor_kind IN ('bot', 'scanner') THEN clicks ELSE 0 END) AS automated_requests
            FROM analytics_rows r
            WHERE {$where}
            GROUP BY referrer_domain, campaign_source
            HAVING automated_requests > 0
            ORDER BY automated_requests DESC, requests DESC, referrer_domain COLLATE NOCASE ASC
            LIMIT :limit
        SQL);
        foreach (array_merge($params, $filterParams) as $name => $value) {
            $statement->bindValue(':' . $name, $value);
        }
        $statement->bindValue(':limit', max(1, min(20, $limit)), PDO::PARAM_INT);
        $statement->execute();
        return array_map(static fn (array $row): array => [
            'referrer_domain' => (string)$row['referrer_domain'],
            'campaign_source' => (string)$row['campaign_source'],
            'requests' => (int)$row['requests'],
            'automated_requests' => (int)$row['automated_requests'],
            'automated_ratio' => (int)$row['requests'] === 0 ? 0.0
                : round((int)$row['automated_requests'] * 100 / (int)$row['requests'], 1),
        ], $statement->fetchAll());
    }

    /** @return array<string, mixed> */
    private function computeDashboard(array $request): array
    {
        $filters = (array)$request['filters'];
        $currentTotalsWithKinds = $this->totals((array)$request['current'], $filters);
        $previousTotalsWithKinds = $this->totals((array)$request['previous'], $filters);
        $currentTotals = array_filter(
            $currentTotalsWithKinds,
            static fn (string $metric): bool => !str_starts_with($metric, '_'),
            ARRAY_FILTER_USE_KEY
        );
        $previousTotals = array_filter(
            $previousTotalsWithKinds,
            static fn (string $metric): bool => !str_starts_with($metric, '_'),
            ARRAY_FILTER_USE_KEY
        );
        $deltas = [];
        $percentChanges = [];
        foreach (array_keys($currentTotals) as $metric) {
            $deltas[$metric] = $currentTotals[$metric] - $previousTotals[$metric];
            $percentChanges[$metric] = $previousTotals[$metric] === 0
                ? null
                : round($deltas[$metric] * 100 / $previousTotals[$metric], 1);
        }

        return [
            'request' => $request,
            'periods' => [
                'current' => ['start' => $request['start'], 'end' => $request['end']],
                'previous' => [
                    'start' => $request['previous']['local_start'],
                    'end' => $request['previous']['local_end'],
                ],
            ],
            'totals' => $currentTotals,
            'previous_totals' => $previousTotals,
            'deltas' => $deltas,
            'percent_changes' => $percentChanges,
            'coverage' => $this->dataCoverage($request, $currentTotals),
            'reconciliation' => $this->reconciliation(
                (array)$request['current'],
                $filters,
                $currentTotalsWithKinds
            ),
            'trend' => $this->trend((array)$request['current'], $filters),
            'hours' => $this->hours((array)$request['current'], $filters),
            'devices' => $this->breakdown('device_type', (array)$request['current'], $filters, 8),
            'browsers' => $this->breakdown('browser', (array)$request['current'], $filters, 8),
            'operating_systems' => $this->breakdown('operating_system', (array)$request['current'], $filters, 8),
            'countries' => $this->breakdown('country_code', (array)$request['current'], $filters, 8),
            'referrers' => $this->breakdown('referrer_domain', (array)$request['current'], $filters, 8),
            'campaigns' => $this->campaignReport((array)$request['current'], $filters, 500),
            'rankings' => $this->rankings($request),
        ];
    }

    /**
     * @return array{
     *     links: array<int, array<string, mixed>>,
     *     tags: array<int, string>, campaigns: array<int, string>, sources: array<int, string>,
     *     mediums: array<int, string>, referrers: array<int, string>, countries: array<int, string>
     * }
     */
    public function filterOptions(int $selectedLinkId = 0): array
    {
        $dimensionOptions = static function (PDO $pdo, string $column): array {
            $statement = $pdo->query(<<<SQL
                SELECT label
                FROM (
                    SELECT {$column} AS label FROM visitor_hourly_stats
                    UNION
                    SELECT {$column} AS label FROM visitor_daily_stats
                )
                WHERE label <> ''
                ORDER BY label COLLATE NOCASE ASC
                LIMIT 250
            SQL);
            return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
        };

        $links = [];
        $linkOptions = $this->pdo->prepare(<<<'SQL'
            WITH preferred AS (
                SELECT id, slug, title, campaign_name
                FROM links
                WHERE deleted_at IS NULL AND campaign_name <> ''
                ORDER BY campaign_name COLLATE NOCASE ASC, id DESC
                LIMIT 500
            ), empty_campaigns AS (
                SELECT id, slug, title, campaign_name
                FROM links
                WHERE deleted_at IS NULL AND campaign_name = ''
                ORDER BY id DESC
                LIMIT 500
            ), bounded AS (
                SELECT id, slug, title, campaign_name FROM preferred
                UNION ALL
                SELECT id, slug, title, campaign_name FROM empty_campaigns
                LIMIT 500
            )
            SELECT id, slug, title, campaign_name FROM bounded
            UNION ALL
            SELECT id, slug, title, campaign_name FROM links
            WHERE id = :selected_link_id AND deleted_at IS NULL
              AND NOT EXISTS (SELECT 1 FROM bounded WHERE bounded.id = links.id)
        SQL);
        $linkOptions->execute(['selected_link_id' => max(0, $selectedLinkId)]);
        foreach ($linkOptions as $link) {
            $links[] = [
                'id' => (int)$link['id'],
                'slug' => (string)$link['slug'],
                'title' => (string)$link['title'],
                'campaign_name' => (string)$link['campaign_name'],
            ];
        }

        return [
            'links' => $links,
            'tags' => array_map('strval', $this->pdo->query(
                'SELECT DISTINCT tag FROM link_tags ORDER BY tag COLLATE NOCASE ASC LIMIT 250'
            )->fetchAll(PDO::FETCH_COLUMN)),
            'campaigns' => $dimensionOptions($this->pdo, 'campaign_name'),
            'sources' => $dimensionOptions($this->pdo, 'campaign_source'),
            'mediums' => $dimensionOptions($this->pdo, 'campaign_medium'),
            'referrers' => $dimensionOptions($this->pdo, 'referrer_domain'),
            'countries' => $dimensionOptions($this->pdo, 'country_code'),
        ];
    }

    /** @return list<array{id: int, name: string, parameters: array<string, string|int>, created_at: string, updated_at: string}> */
    public function savedViews(): array
    {
        $views = [];
        foreach ($this->pdo->query(<<<'SQL'
            SELECT id, name, request_json, created_at, updated_at
            FROM saved_analytics_views
            ORDER BY name COLLATE NOCASE ASC, id ASC
        SQL) as $row) {
            try {
                $stored = json_decode((string)$row['request_json'], true, 16, JSON_THROW_ON_ERROR);
                if (!is_array($stored)) {
                    continue;
                }
                $request = $this->normalizeRequest($stored);
                $views[] = [
                    'id' => (int)$row['id'],
                    'name' => (string)$row['name'],
                    'parameters' => $this->queryParameters($request),
                    'created_at' => (string)$row['created_at'],
                    'updated_at' => (string)$row['updated_at'],
                ];
            } catch (Throwable) {
            }
        }
        return $views;
    }

    public function saveView(string $name, array $request): int
    {
        $parameters = $this->queryParameters($request);
        unset($parameters['section']);
        $now = utc_timestamp();
        $statement = $this->pdo->prepare(<<<'SQL'
            INSERT INTO saved_analytics_views (name, request_json, created_at, updated_at)
            VALUES (:name, :request_json, :created_at, :updated_at)
            ON CONFLICT(name) DO UPDATE SET
                request_json = excluded.request_json,
                updated_at = excluded.updated_at
        SQL);
        with_sqlite_retry(fn () => $statement->execute([
            'name' => $name,
            'request_json' => json_encode($parameters, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]));
        $lookup = $this->pdo->prepare('SELECT id FROM saved_analytics_views WHERE name = :name COLLATE NOCASE');
        $lookup->execute(['name' => $name]);
        return (int)$lookup->fetchColumn();
    }

    public function renameSavedView(int $id, string $name): bool
    {
        $statement = $this->pdo->prepare(<<<'SQL'
            UPDATE saved_analytics_views
            SET name = :name, updated_at = :updated_at
            WHERE id = :id
              AND NOT EXISTS (
                  SELECT 1 FROM saved_analytics_views existing
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

    public function deleteSavedView(int $id): bool
    {
        $statement = $this->pdo->prepare('DELETE FROM saved_analytics_views WHERE id = :id');
        with_sqlite_retry(fn () => $statement->execute(['id' => $id]));
        return $statement->rowCount() > 0;
    }

    /** @return array{headers: array<int, string>, rows: iterable<array<int, int|string>>, filename: string} */
    public function export(string $report, array $request, int $maxRows = self::EXPORT_MAX_ROWS): array
    {
        $maxRows = max(1, min(1000000, $maxRows));
        $report = in_array($report, ['filtered', 'trend', 'sources', 'devices', 'countries', 'campaigns'], true)
            ? $report : 'filtered';
        $range = (array)$request['current'];
        $filters = (array)$request['filters'];
        $stamp = gmdate('Ymd-His');

        if ($report === 'trend') {
            $rows = array_map(static fn (array $row): array => [
                (string)$row['accessed_on'],
                (int)$row['proxy_requests'],
                (int)$row['suspected_human_requests'],
                (int)$row['automated_requests'],
                (int)$row['unknown_requests'],
            ], $this->trend($range, $filters));
            $this->assertExportComplete($rows, $maxRows);
            return [
                'headers' => ['日期', '代理请求', '疑似人工', '自动化', '未知'],
                'rows' => $rows,
                'filename' => "linkvault-trend-{$stamp}.csv",
            ];
        }
        if (in_array($report, ['devices', 'countries'], true)) {
            $dimension = $report === 'devices' ? 'device_type' : 'country_code';
            [$cte, $params] = $this->sourceCte($range);
            [$where, $filterParams] = $this->filterSql($filters);
            $rows = $this->streamExportRows(
                $cte . <<<SQL
                    SELECT {$dimension} AS label, SUM(clicks) AS clicks
                    FROM analytics_rows r
                    WHERE {$where}
                    GROUP BY {$dimension}
                SQL,
                " ORDER BY clicks DESC, {$dimension} COLLATE NOCASE ASC",
                array_merge($params, $filterParams),
                static fn (array $row): array => [(string)$row['label'], (int)$row['clicks']],
                $maxRows,
            );
            return [
                'headers' => [$report === 'devices' ? '设备' : '国家/地区', '请求数'],
                'rows' => $rows,
                'filename' => 'linkvault-' . $report . '-' . $stamp . '.csv',
            ];
        }
        if ($report === 'campaigns') {
            [$cte, $params] = $this->sourceCte($range);
            [$where, $filterParams] = $this->filterSql($filters);
            $rows = $this->streamExportRows(
                $cte . <<<SQL
                    SELECT campaign_name, campaign_source, campaign_medium, campaign_content,
                           SUM(clicks) AS proxy_requests,
                           SUM(CASE WHEN visitor_kind = 'suspected_human' THEN clicks ELSE 0 END)
                               AS suspected_human_requests,
                           SUM(CASE WHEN visitor_kind = 'bot' THEN clicks ELSE 0 END) AS bot_requests,
                           SUM(CASE WHEN visitor_kind = 'scanner' THEN clicks ELSE 0 END) AS scanner_requests,
                           SUM(CASE WHEN visitor_kind = 'unknown' THEN clicks ELSE 0 END) AS unknown_requests
                    FROM analytics_rows r
                    WHERE {$where}
                      AND (campaign_name <> '' OR campaign_source <> '' OR campaign_medium <> '' OR campaign_content <> '')
                    GROUP BY campaign_name, campaign_source, campaign_medium, campaign_content
                SQL,
                ' ORDER BY suspected_human_requests DESC, proxy_requests DESC, campaign_name COLLATE NOCASE ASC',
                array_merge($params, $filterParams),
                static fn (array $row): array => [
                    (string)$row['campaign_name'], (string)$row['campaign_source'],
                    (string)$row['campaign_medium'], (string)$row['campaign_content'],
                    (int)$row['proxy_requests'], (int)$row['suspected_human_requests'],
                    (int)$row['bot_requests'], (int)$row['scanner_requests'], (int)$row['unknown_requests'],
                ],
                $maxRows,
            );
            return [
                'headers' => ['活动', '来源', '媒介', '内容', '代理请求', '疑似人工', '机器人', '扫描', '未知'],
                'rows' => $rows,
                'filename' => "linkvault-campaigns-{$stamp}.csv",
            ];
        }
        if ($report === 'sources') {
            [$cte, $params] = $this->sourceCte($range);
            [$where, $filterParams] = $this->filterSql($filters);
            $rows = $this->streamExportRows(
                $cte . <<<SQL
                    SELECT referrer_domain, campaign_source, campaign_medium,
                           SUM(clicks) AS proxy_requests,
                           SUM(CASE WHEN visitor_kind = 'suspected_human' THEN clicks ELSE 0 END)
                               AS suspected_human_requests
                    FROM analytics_rows r
                    WHERE {$where}
                    GROUP BY referrer_domain, campaign_source, campaign_medium
                SQL,
                ' ORDER BY proxy_requests DESC, referrer_domain COLLATE NOCASE ASC',
                array_merge($params, $filterParams),
                static fn (array $row): array => [
                    (string)$row['referrer_domain'], (string)$row['campaign_source'],
                    (string)$row['campaign_medium'], (int)$row['proxy_requests'],
                    (int)$row['suspected_human_requests'],
                ],
                $maxRows,
            );
            return [
                'headers' => ['来源域名', '活动来源', '媒介', '代理请求', '疑似人工'],
                'rows' => $rows,
                'filename' => "linkvault-sources-{$stamp}.csv",
            ];
        }

        [$cte, $params] = $this->sourceCte($range);
        [$where, $filterParams] = $this->filterSql($filters);
        $rows = $this->streamExportRows(
            $cte . <<<SQL
                SELECT r.accessed_on, l.slug, l.title, r.country_code, r.device_type, r.browser,
                       r.operating_system, r.referrer_domain, r.visitor_kind, r.request_kind,
                       r.campaign_name, r.campaign_source, r.campaign_medium, r.campaign_content,
                       SUM(r.clicks) AS clicks
                FROM analytics_rows r
                JOIN links l ON l.id = r.link_id
                WHERE {$where}
                GROUP BY r.accessed_on, r.link_id, r.country_code, r.device_type, r.browser,
                         r.operating_system, r.referrer_domain, r.visitor_kind, r.request_kind,
                         r.campaign_name, r.campaign_source, r.campaign_medium, r.campaign_content
            SQL,
            ' ORDER BY r.accessed_on DESC, clicks DESC, l.slug ASC',
            array_merge($params, $filterParams),
            static fn (array $row): array => [
                (string)$row['accessed_on'], (string)$row['slug'], (string)$row['title'],
                (string)$row['country_code'], (string)$row['device_type'], (string)$row['browser'],
                (string)$row['operating_system'], (string)$row['referrer_domain'],
                (string)$row['visitor_kind'], (string)$row['request_kind'],
                (string)$row['campaign_name'], (string)$row['campaign_source'],
                (string)$row['campaign_medium'], (string)$row['campaign_content'], (int)$row['clicks'],
            ],
            $maxRows,
        );
        return [
            'headers' => [
                '日期', '短码', '标题', '国家/地区', '设备', '浏览器', '操作系统', '来源域名',
                '流量类型', '请求类型', '活动', '活动来源', '媒介', '内容', '请求数',
            ],
            'rows' => $rows,
            'filename' => "linkvault-filtered-{$stamp}.csv",
        ];
    }

    /** @return array<string, int> */
    private function totals(array $range, array $filters): array
    {
        [$cte, $params] = $this->sourceCte($range);
        [$where, $filterParams] = $this->filterSql($filters);
        $statement = $this->pdo->prepare($cte . <<<SQL
            SELECT COALESCE(SUM(clicks), 0) AS proxy_requests,
                   COALESCE(SUM(CASE WHEN visitor_kind = 'suspected_human' THEN clicks ELSE 0 END), 0)
                       AS suspected_human_requests,
                   COALESCE(SUM(CASE WHEN visitor_kind = 'bot' THEN clicks ELSE 0 END), 0)
                       AS bot_requests,
                   COALESCE(SUM(CASE WHEN visitor_kind = 'scanner' THEN clicks ELSE 0 END), 0)
                       AS scanner_requests,
                   COALESCE(SUM(CASE WHEN visitor_kind = 'unknown' THEN clicks ELSE 0 END), 0)
                       AS unknown_requests
            FROM analytics_rows r
            WHERE {$where}
        SQL);
        $statement->execute(array_merge($params, $filterParams));
        $row = $statement->fetch() ?: [];
        return [
            'proxy_requests' => (int)($row['proxy_requests'] ?? 0),
            'suspected_human_requests' => (int)($row['suspected_human_requests'] ?? 0),
            'bot_requests' => (int)($row['bot_requests'] ?? 0),
            'scanner_requests' => (int)($row['scanner_requests'] ?? 0),
            'unknown_requests' => (int)($row['unknown_requests'] ?? 0),
        ];
    }

    private function trend(array $range, array $filters): array
    {
        [$cte, $params] = $this->sourceCte($range);
        [$where, $filterParams] = $this->filterSql($filters);
        $statement = $this->pdo->prepare($cte . <<<SQL
            SELECT bucket, storage_granularity, visitor_kind, SUM(clicks) AS clicks
            FROM analytics_rows r
            WHERE {$where}
            GROUP BY bucket, storage_granularity, visitor_kind
            ORDER BY bucket ASC
        SQL);
        $statement->execute(array_merge($params, $filterParams));
        $zone = new DateTimeZone((string)$range['timezone']);
        $values = [];
        foreach ($statement as $row) {
            try {
                $date = (string)($row['storage_granularity'] ?? '') === 'daily'
                    ? substr((string)$row['bucket'], 0, 10)
                    : (new DateTimeImmutable((string)$row['bucket']))->setTimezone($zone)->format('Y-m-d');
            } catch (Throwable) {
                continue;
            }
            $values[$date] ??= [
                'proxy_requests' => 0,
                'suspected_human_requests' => 0,
                'automated_requests' => 0,
                'unknown_requests' => 0,
            ];
            $clicks = (int)$row['clicks'];
            $values[$date]['proxy_requests'] += $clicks;
            if ($row['visitor_kind'] === 'suspected_human') {
                $values[$date]['suspected_human_requests'] += $clicks;
            } elseif (in_array($row['visitor_kind'], ['bot', 'scanner'], true)) {
                $values[$date]['automated_requests'] += $clicks;
            } else {
                $values[$date]['unknown_requests'] += $clicks;
            }
        }

        $rows = [];
        $date = new DateTimeImmutable((string)$range['local_start'], $zone);
        $end = new DateTimeImmutable((string)$range['local_end'], $zone);
        while ($date <= $end) {
            $key = $date->format('Y-m-d');
            $rows[] = ['accessed_on' => $key] + ($values[$key] ?? [
                'proxy_requests' => 0,
                'suspected_human_requests' => 0,
                'automated_requests' => 0,
                'unknown_requests' => 0,
            ]);
            $date = $date->modify('+1 day');
        }
        return $rows;
    }

    /** @return array<int, int> */
    private function hours(array $range, array $filters): array
    {
        [$where, $filterParams] = $this->filterSql($filters);
        $statement = $this->pdo->prepare(<<<SQL
            SELECT accessed_hour, SUM(clicks) AS clicks
            FROM visitor_hourly_stats r
            WHERE accessed_hour >= :hour_start AND accessed_hour < :hour_end AND {$where}
            GROUP BY accessed_hour
        SQL);
        $statement->execute(array_merge([
            'hour_start' => $range['start_utc'],
            'hour_end' => $range['end_utc'],
        ], $filterParams));
        $zone = new DateTimeZone((string)$range['timezone']);
        $hours = array_fill(0, 24, 0);
        foreach ($statement as $row) {
            try {
                $hour = (int)(new DateTimeImmutable((string)$row['accessed_hour']))->setTimezone($zone)->format('G');
                $hours[$hour] += (int)$row['clicks'];
            } catch (Throwable) {
            }
        }
        return $hours;
    }

    private function breakdown(string $dimension, array $range, array $filters, int $limit): array
    {
        if (!in_array($dimension, [
            'device_type', 'browser', 'operating_system', 'country_code', 'referrer_domain',
        ], true)) {
            throw new InvalidArgumentException('Unsupported analytics dimension.');
        }
        [$cte, $params] = $this->sourceCte($range);
        [$where, $filterParams] = $this->filterSql($filters);
        $limitSql = $limit > 0 ? ' LIMIT ' . min(self::EXPORT_MAX_ROWS + 1, $limit) : ' LIMIT 50001';
        $statement = $this->pdo->prepare($cte . <<<SQL
            SELECT {$dimension} AS label, SUM(clicks) AS clicks
            FROM analytics_rows r
            WHERE {$where}
            GROUP BY {$dimension}
            ORDER BY clicks DESC, {$dimension} COLLATE NOCASE ASC{$limitSql}
        SQL);
        $statement->execute(array_merge($params, $filterParams));
        return $statement->fetchAll();
    }

    private function campaignReport(array $range, array $filters, int $limit): array
    {
        [$cte, $params] = $this->sourceCte($range);
        [$where, $filterParams] = $this->filterSql($filters);
        $limitSql = ' LIMIT ' . ($limit > 0 ? min(self::EXPORT_MAX_ROWS + 1, $limit) : 50001);
        $statement = $this->pdo->prepare($cte . <<<SQL
            SELECT campaign_name, campaign_source, campaign_medium, campaign_content,
                   SUM(clicks) AS proxy_requests,
                   SUM(CASE WHEN visitor_kind = 'suspected_human' THEN clicks ELSE 0 END)
                       AS suspected_human_requests,
                   SUM(CASE WHEN visitor_kind = 'bot' THEN clicks ELSE 0 END) AS bot_requests,
                   SUM(CASE WHEN visitor_kind = 'scanner' THEN clicks ELSE 0 END) AS scanner_requests,
                   SUM(CASE WHEN visitor_kind = 'unknown' THEN clicks ELSE 0 END) AS unknown_requests
            FROM analytics_rows r
            WHERE {$where}
              AND (campaign_name <> '' OR campaign_source <> '' OR campaign_medium <> '' OR campaign_content <> '')
            GROUP BY campaign_name, campaign_source, campaign_medium, campaign_content
            ORDER BY suspected_human_requests DESC, proxy_requests DESC, campaign_name COLLATE NOCASE ASC{$limitSql}
        SQL);
        $statement->execute(array_merge($params, $filterParams));
        return $statement->fetchAll();
    }

    private function rankings(array $request): array
    {
        $filters = (array)$request['filters'];
        $previousById = [];
        foreach ($this->linkTotals((array)$request['previous'], $filters) as $row) {
            $previousById[(int)$row['link_id']] = (int)$row['proxy_requests'];
        }
        $growth = [];
        $decline = [];
        $botShare = [];
        $currentById = [];
        $keepTop = static function (array &$rows, array $row, callable $compare): void {
            $rows[] = $row;
            usort($rows, $compare);
            if (count($rows) > 8) {
                array_pop($rows);
            }
        };
        foreach ($this->linkTotals((array)$request['current'], $filters) as $row) {
            $linkId = (int)$row['link_id'];
            $currentById[$linkId] = (int)$row['proxy_requests'];
            $before = $previousById[$linkId] ?? 0;
            $delta = (int)$row['proxy_requests'] - $before;
            $rankingRow = $row + ['delta' => $delta, 'previous_requests' => $before];
            if ($delta > 0) {
                $keepTop($growth, $rankingRow, static fn (array $a, array $b): int => $b['delta'] <=> $a['delta']);
            } elseif ($delta < 0) {
                $keepTop($decline, $rankingRow, static fn (array $a, array $b): int => $a['delta'] <=> $b['delta']);
            }
            if ((int)$row['proxy_requests'] > 0) {
                $rankingRow['bot_ratio'] = round((int)$row['bot_requests'] * 100 / (int)$row['proxy_requests'], 1);
                $keepTop($botShare, $rankingRow, static fn (array $a, array $b): int => $b['bot_ratio'] <=> $a['bot_ratio']);
            }
        }
        unset($previousById);

        $this->createRankingBounds($filters);
        try {
            $firstTraffic = [];
            $firstBounds = $this->allTimeLinkBounds($filters, true);
            foreach ($firstBounds as $row) {
                $linkId = (int)$row['link_id'];
                $currentRequests = $currentById[$linkId] ?? 0;
                if ($currentRequests > 0 && (string)$row['first_bucket'] >= (string)$request['current']['start_utc']) {
                    $firstTraffic[] = $row + ['proxy_requests' => $currentRequests];
                    if (count($firstTraffic) === 8) {
                        break;
                    }
                }
            }
            $firstBounds->closeCursor();
            unset($firstBounds);
            $longZero = [];
            $zeroBounds = $this->allTimeLinkBounds($filters, false);
            foreach ($zeroBounds as $row) {
                if (($currentById[(int)$row['link_id']] ?? 0) === 0) {
                    $longZero[] = $row;
                    if (count($longZero) === 8) {
                        break;
                    }
                }
            }
            $zeroBounds->closeCursor();
            unset($zeroBounds);
        } finally {
            // The request owns its PDO connection; SQLite reclaims this temp table with it.
            $this->rankingBoundsTable = null;
        }

        return [
            'growth' => $growth,
            'decline' => $decline,
            'bot_share' => $botShare,
            'first_traffic' => $firstTraffic,
            'long_zero' => $longZero,
        ];
    }

    private function linkTotals(array $range, array $filters): iterable
    {
        [$cte, $params] = $this->sourceCte($range);
        [$where, $filterParams] = $this->filterSql($filters);
        $statement = $this->pdo->prepare($cte . <<<SQL
            SELECT r.link_id, l.slug, l.title, SUM(r.clicks) AS proxy_requests,
                   SUM(CASE WHEN r.visitor_kind = 'bot' THEN r.clicks ELSE 0 END) AS bot_requests
            FROM analytics_rows r
            JOIN links l ON l.id = r.link_id
            WHERE {$where}
            GROUP BY r.link_id
        SQL);
        $statement->execute(array_merge($params, $filterParams));
        foreach ($statement as $row) {
            yield $row;
        }
    }

    private function createRankingBounds(array $filters): void
    {
        [$where, $params] = $this->filterSql($filters);
        $dailyTable = $this->dailyDimensionsAreReady()
            ? 'analytics_daily_dimensions'
            : 'visitor_daily_stats';
        $suffix = bin2hex(random_bytes(6));
        $this->rankingBoundsTable = 'analytics_ranking_bounds_' . $suffix;
        $table = $this->rankingBoundsTable;
        $statement = $this->pdo->prepare(<<<SQL
            CREATE TEMP TABLE {$table} AS
            WITH analytics_rows AS (
                SELECT link_id, accessed_hour AS bucket, country_code, device_type, browser,
                       operating_system, referrer_domain, visitor_kind, request_kind,
                       campaign_name, campaign_source, campaign_medium, campaign_content, clicks
                FROM visitor_hourly_stats
                UNION ALL
                SELECT link_id, accessed_on || 'T00:00:00Z', country_code, device_type, browser,
                       operating_system, referrer_domain, visitor_kind, request_kind,
                       campaign_name, campaign_source, campaign_medium, campaign_content, clicks
                FROM {$dailyTable}
            )
            SELECT r.link_id, MIN(r.bucket) AS first_bucket, MAX(r.bucket) AS last_bucket
            FROM analytics_rows r
            WHERE {$where}
            GROUP BY r.link_id
        SQL);
        $statement->execute($params);
        $this->pdo->exec(
            'CREATE INDEX ' . $table . '_first_idx'
            . ' ON ' . $table . ' (first_bucket DESC, link_id DESC)'
        );
        $this->pdo->exec(
            'CREATE INDEX ' . $table . '_last_idx'
            . ' ON ' . $table . ' (last_bucket ASC, link_id DESC)'
        );
    }

    private function allTimeLinkBounds(array $filters, bool $firstTraffic): PDOStatement
    {
        $linkWhere = ['l.deleted_at IS NULL'];
        $linkParams = [];
        if ((int)$filters['link'] > 0) {
            $linkWhere[] = 'l.id = :bounds_link';
            $linkParams['bounds_link'] = (int)$filters['link'];
        }
        if ((string)$filters['tag'] !== '') {
            $linkWhere[] = 'EXISTS (SELECT 1 FROM link_tags bt WHERE bt.link_id = l.id AND bt.tag = :bounds_tag)';
            $linkParams['bounds_tag'] = (string)$filters['tag'];
        }
        $table = $this->rankingBoundsTable;
        if ($table === null) {
            throw new LogicException('Analytics ranking bounds are not initialized.');
        }
        $joinSql = $firstTraffic
            ? 'INNER JOIN ' . $table . ' bounds ON bounds.link_id = l.id'
            : 'LEFT JOIN ' . $table . ' bounds ON bounds.link_id = l.id';
        $linkWhereSql = implode(' AND ', $linkWhere);
        $orderSql = $firstTraffic
            ? 'bounds.first_bucket DESC, l.id DESC'
            : "COALESCE(bounds.last_bucket, '') ASC, l.id DESC";
        $statement = $this->pdo->prepare(<<<SQL
            SELECT l.id AS link_id, l.slug, l.title, bounds.first_bucket, bounds.last_bucket
            FROM links l {$joinSql}
            WHERE {$linkWhereSql}
            ORDER BY {$orderSql}
        SQL);
        $statement->execute($linkParams);
        return $statement;
    }

    private function reconciliation(array $range, array $filters, array $totals): array
    {
        foreach ([
            'campaign', 'source', 'medium', 'referrer', 'browser', 'operating_system',
            'device', 'country', 'traffic',
        ] as $filter) {
            if ($filters[$filter] !== '' && $filters[$filter] !== null) {
                return ['available' => false];
            }
        }
        $conditions = ['accessed_on >= :since', 'accessed_on < :until'];
        $archiveConditions = ['accessed_on >= :archive_since', 'accessed_on < :archive_until'];
        $params = [
            'since' => $range['daily_start'],
            'until' => $range['daily_end'],
            'archive_since' => $range['daily_start'],
            'archive_until' => $range['daily_end'],
        ];
        if ((int)$filters['link'] > 0) {
            $conditions[] = 'link_id = :link_id';
            $archiveConditions[] = 'link_id = :archive_link_id';
            $params['link_id'] = (int)$filters['link'];
            $params['archive_link_id'] = (int)$filters['link'];
        }
        if ((string)$filters['tag'] !== '') {
            $conditions[] = 'EXISTS (SELECT 1 FROM link_tags t WHERE t.link_id = link_daily_stats.link_id AND t.tag = :tag)';
            $archiveConditions[] = 'EXISTS (SELECT 1 FROM link_tags t WHERE t.link_id = archived.link_id AND t.tag = :archive_tag)';
            $params['tag'] = (string)$filters['tag'];
            $params['archive_tag'] = (string)$filters['tag'];
        }
        $statement = $this->pdo->prepare(<<<SQL
            SELECT COALESCE(SUM(clicks), 0)
            FROM (
                SELECT clicks FROM link_daily_stats WHERE 
        SQL . implode(' AND ', $conditions) . <<<SQL
                UNION ALL
                SELECT clicks FROM link_daily_stats_archive archived WHERE 
        SQL . implode(' AND ', $archiveConditions) . <<<SQL
                  AND EXISTS (SELECT 1 FROM links WHERE links.id = archived.link_id)
            ) rows
        SQL);
        $statement->execute($params);
        $redirects = (int)$statement->fetchColumn();
        $difference = (int)$totals['proxy_requests'] - $redirects;
        return [
            'available' => true,
            'redirects' => $redirects,
            'proxy_requests' => (int)$totals['proxy_requests'],
            'difference' => $difference,
            'difference_percent' => $redirects === 0 ? null : round($difference * 100 / $redirects, 1),
            'difference_excluding_head' => $difference - (int)($totals['_head_requests'] ?? 0),
            'get_requests' => (int)($totals['_get_requests'] ?? 0),
            'head_requests' => (int)($totals['_head_requests'] ?? 0),
            'confirmation_requests' => (int)($totals['_confirmation_requests'] ?? 0),
            'legacy_unknown_requests' => (int)($totals['_legacy_unknown_requests'] ?? 0),
        ];
    }

    /** @return array{0: string, 1: array<string, int|string>} */
    private function sourceCte(array $range): array
    {
        $signature = $this->rangeSignature($range);
        if ($this->materializedRangeTable !== null && $this->materializedRangeSignature === $signature) {
            $table = $this->materializedRangeTable;
            return ["WITH analytics_rows AS (SELECT * FROM {$table})", []];
        }
        if ($this->dailyDimensionsAreReady()) {
            return [<<<'SQL'
                WITH analytics_rows AS (
                    SELECT link_id, accessed_hour AS bucket, substr(accessed_hour, 1, 10) AS accessed_on,
                           'hourly' AS storage_granularity,
                           country_code, device_type, browser, operating_system, referrer_domain,
                           visitor_kind, request_kind, campaign_name, campaign_source, campaign_medium,
                           campaign_content, clicks
                    FROM visitor_hourly_stats
                    WHERE accessed_hour >= :hourly_start AND accessed_hour < :hourly_end
                      AND (accessed_hour < :daily_start_hour OR accessed_hour >= :daily_end_hour)
                    UNION ALL
                    SELECT link_id, accessed_on || 'T00:00:00Z' AS bucket, accessed_on,
                           'daily' AS storage_granularity,
                           country_code, device_type, browser, operating_system, referrer_domain,
                           visitor_kind, request_kind, campaign_name, campaign_source, campaign_medium,
                           campaign_content, clicks
                    FROM analytics_daily_dimensions
                    WHERE accessed_on >= :daily_start AND accessed_on < :daily_end
                )
            SQL, [
                'hourly_start' => (string)$range['start_utc'],
                'hourly_end' => (string)$range['end_utc'],
                'daily_start_hour' => (string)$range['daily_start'] . 'T00:00:00Z',
                'daily_end_hour' => (string)$range['daily_end'] . 'T00:00:00Z',
                'daily_start' => (string)$range['daily_start'],
                'daily_end' => (string)$range['daily_end'],
            ]];
        }
        return [<<<'SQL'
            WITH analytics_rows AS (
                SELECT link_id, accessed_hour AS bucket, substr(accessed_hour, 1, 10) AS accessed_on,
                       'hourly' AS storage_granularity,
                       country_code, device_type, browser, operating_system, referrer_domain,
                       visitor_kind, request_kind, campaign_name, campaign_source, campaign_medium,
                       campaign_content, clicks
                FROM visitor_hourly_stats
                WHERE accessed_hour >= :hourly_start AND accessed_hour < :hourly_end
                UNION ALL
                SELECT link_id, accessed_on || 'T00:00:00Z' AS bucket, accessed_on,
                       'daily' AS storage_granularity,
                       country_code, device_type, browser, operating_system, referrer_domain,
                       visitor_kind, request_kind, campaign_name, campaign_source, campaign_medium,
                       campaign_content, clicks
                FROM visitor_daily_stats
                WHERE accessed_on >= :daily_start AND accessed_on < :daily_end
            )
        SQL, [
            'hourly_start' => (string)$range['start_utc'],
            'hourly_end' => (string)$range['end_utc'],
            'daily_start' => (string)$range['daily_start'],
            'daily_end' => (string)$range['daily_end'],
        ]];
    }

    private function dailyDimensionsAreReady(): bool
    {
        if ($this->dailyDimensionsReady !== null) {
            return $this->dailyDimensionsReady;
        }
        $statement = $this->pdo->query("SELECT status FROM analytics_rollup_state WHERE id = 1");
        return $this->dailyDimensionsReady = $statement->fetchColumn() === 'ready';
    }

    /** @return array{0: string, 1: array<string, int|string>} */
    private function filterSql(array $filters): array
    {
        $where = ['1 = 1'];
        $params = [];
        $equals = [
            'link' => 'link_id',
            'campaign' => 'campaign_name',
            'source' => 'campaign_source',
            'medium' => 'campaign_medium',
            'referrer' => 'referrer_domain',
            'browser' => 'browser',
            'operating_system' => 'operating_system',
            'device' => 'device_type',
            'country' => 'country_code',
        ];
        foreach ($equals as $filter => $column) {
            if ($filters[$filter] === '' || $filters[$filter] === 0) {
                continue;
            }
            $where[] = "r.{$column} = :filter_{$filter}";
            $params['filter_' . $filter] = $filters[$filter];
        }
        if ((string)$filters['tag'] !== '') {
            $where[] = 'EXISTS (SELECT 1 FROM link_tags ft WHERE ft.link_id = r.link_id AND ft.tag = :filter_tag)';
            $params['filter_tag'] = (string)$filters['tag'];
        }
        if ($filters['traffic'] === 'automated') {
            $where[] = "r.visitor_kind IN ('bot', 'scanner')";
        } elseif ((string)$filters['traffic'] !== '') {
            $where[] = 'r.visitor_kind = :filter_traffic';
            $params['filter_traffic'] = (string)$filters['traffic'];
        }
        return [implode(' AND ', $where), $params];
    }

    /** @return array<string, string> */
    private function rangeData(DateTimeImmutable $start, DateTimeImmutable $endExclusive, string $timezone): array
    {
        $utc = new DateTimeZone('UTC');
        $utcStart = $start->setTimezone($utc);
        $utcEnd = $endExclusive->setTimezone($utc);
        $dailyStart = $utcStart->setTime(0, 0);
        if ($utcStart != $dailyStart) {
            $dailyStart = $dailyStart->modify('+1 day');
        }
        $dailyEnd = $utcEnd->setTime(0, 0);
        return [
            'timezone' => $timezone,
            'local_start' => $start->format('Y-m-d'),
            'local_end' => $endExclusive->modify('-1 day')->format('Y-m-d'),
            'start_utc' => $utcStart->format('Y-m-d\TH:i:s\Z'),
            'end_utc' => $utcEnd->format('Y-m-d\TH:i:s\Z'),
            'daily_start' => $dailyStart->format('Y-m-d'),
            'daily_end' => $dailyEnd->format('Y-m-d'),
        ];
    }

    private function materializeRange(array $range): void
    {
        $maximum = max(0, min(2000000, (int)($this->config['analytics_materialize_max_rows'] ?? 250000)));
        if ($maximum === 0) {
            return;
        }
        [$cte, $params] = $this->sourceCte($range);
        $count = $this->pdo->prepare($cte . ' SELECT COUNT(*) FROM analytics_rows');
        $count->execute($params);
        if ((int)$count->fetchColumn() > $maximum) {
            return;
        }
        $table = 'analytics_range_' . bin2hex(random_bytes(6));
        $create = $this->pdo->prepare("CREATE TEMP TABLE {$table} AS " . $cte . ' SELECT * FROM analytics_rows');
        $create->execute($params);
        $this->materializedRangeTable = $table;
        $this->materializedRangeSignature = $this->rangeSignature($range);
    }

    private function rangeSignature(array $range): string
    {
        return hash('sha256', json_encode($range, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function dashboardCachePath(array $request): ?string
    {
        $ttl = max(0, min(3600, (int)($this->config['analytics_report_cache_seconds'] ?? 0)));
        $directory = rtrim((string)($this->config['analytics_report_cache_directory'] ?? ''), '/\\');
        $databasePath = (string)($this->config['database_path'] ?? '');
        if ($ttl === 0 || $directory === '' || $databasePath === '' || !is_file($databasePath)) {
            return null;
        }
        clearstatcache(true, $databasePath);
        clearstatcache(true, $databasePath . '-wal');
        $fingerprint = [
            'database_mtime' => (int)(@filemtime($databasePath) ?: 0),
            'database_size' => (int)(@filesize($databasePath) ?: 0),
            'wal_mtime' => (int)(@filemtime($databasePath . '-wal') ?: 0),
            'wal_size' => (int)(@filesize($databasePath . '-wal') ?: 0),
            'schema' => $this->schemaVersion ??= (int)$this->pdo->query('PRAGMA user_version')->fetchColumn(),
        ];
        $key = hash('sha256', json_encode([$request, $fingerprint], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        return $directory . DIRECTORY_SEPARATOR . $key . '.json';
    }

    private function readDashboardCache(array $request): ?array
    {
        $path = $this->dashboardCachePath($request);
        if ($path === null || !is_file($path)) {
            return null;
        }
        $ttl = max(0, min(3600, (int)($this->config['analytics_report_cache_seconds'] ?? 0)));
        if ((int)(filemtime($path) ?: 0) < time() - $ttl) {
            @unlink($path);
            return null;
        }
        try {
            $cached = json_decode((string)file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
            return is_array($cached) ? $cached : null;
        } catch (Throwable) {
            @unlink($path);
            return null;
        }
    }

    private function writeDashboardCache(array $request, array $result): void
    {
        $path = $this->dashboardCachePath($request);
        if ($path === null) {
            return;
        }
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            return;
        }
        $payload = json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $temporary = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (@file_put_contents($temporary, $payload, LOCK_EX) === false) {
            return;
        }
        @chmod($temporary, 0600);
        if (!@rename($temporary, $path)) {
            @unlink($temporary);
        }
        if (random_int(1, 100) === 1) {
            foreach (glob($directory . DIRECTORY_SEPARATOR . '*.json') ?: [] as $candidate) {
                if ((int)(filemtime($candidate) ?: 0) < time() - 3600) {
                    @unlink($candidate);
                }
            }
        }
    }

    /** @return array<string, int|string|null> */
    private function dataCoverage(array $request, array $totals): array
    {
        $bounds = $this->pdo->query(<<<'SQL'
            SELECT
                (SELECT MIN(substr(accessed_hour, 1, 10)) FROM visitor_hourly_stats) AS hourly_start,
                (SELECT MAX(substr(accessed_hour, 1, 10)) FROM visitor_hourly_stats) AS hourly_end,
                (SELECT MIN(accessed_on) FROM visitor_daily_stats) AS daily_start,
                (SELECT MAX(accessed_on) FROM visitor_daily_stats) AS daily_end
        SQL)->fetch() ?: [];
        $hourlyDays = max(1, min(36500, (int)($this->config['analytics_hourly_retention_days'] ?? 90)));
        $retentionDays = max($hourlyDays, min(36500, (int)($this->config['analytics_retention_days'] ?? 365)));
        $today = new DateTimeImmutable('today', new DateTimeZone('UTC'));
        $hourlyCutoff = $today->modify("-{$hourlyDays} days")->format('Y-m-d');
        $retentionCutoff = $today->modify("-{$retentionDays} days")->format('Y-m-d');
        $requestedStart = (string)$request['start'];
        $requestedEnd = (string)$request['end'];
        $retentionState = $requestedEnd < $retentionCutoff
            ? 'fully_pruned'
            : ($requestedStart < $retentionCutoff ? 'partially_pruned' : 'retained');
        $storedStarts = array_values(array_filter([
            $bounds['hourly_start'] ?? null,
            $bounds['daily_start'] ?? null,
        ], static fn (mixed $value): bool => is_string($value) && $value !== ''));
        $storedEnds = array_values(array_filter([
            $bounds['hourly_end'] ?? null,
            $bounds['daily_end'] ?? null,
        ], static fn (mixed $value): bool => is_string($value) && $value !== ''));
        $hasTraffic = (int)($totals['proxy_requests'] ?? 0) > 0;

        return [
            'retention_state' => $retentionState,
            'result_state' => $hasTraffic
                ? 'has_traffic'
                : ($retentionState === 'retained' ? 'zero_traffic' : 'data_pruned'),
            'retention_days' => $retentionDays,
            'retention_start' => $retentionCutoff,
            'actual_start' => $storedStarts ? min($storedStarts) : null,
            'actual_end' => $storedEnds ? max($storedEnds) : null,
            'hourly_retention_days' => $hourlyDays,
            'hourly_retention_start' => $hourlyCutoff,
            'hourly_actual_start' => is_string($bounds['hourly_start'] ?? null) ? $bounds['hourly_start'] : null,
            'hourly_actual_end' => is_string($bounds['hourly_end'] ?? null) ? $bounds['hourly_end'] : null,
            'query_covered_start' => max($requestedStart, $retentionCutoff),
            'hourly_query_covered_start' => max($requestedStart, $hourlyCutoff),
            'archived_timezone' => 'UTC',
        ];
    }

    private function assertExportComplete(array $rows, int $maxRows): void
    {
        if (count($rows) > $maxRows) {
            throw new AnalyticsExportLimitExceeded($maxRows);
        }
    }

    /**
     * @param array<string, int|string> $params
     * @param callable(array<string, mixed>): array<int, int|string> $mapRow
     * @return iterable<array<int, int|string>>
     */
    private function streamExportRows(
        string $sql,
        string $orderBy,
        array $params,
        callable $mapRow,
        int $maxRows,
    ): iterable
    {
        $limitCheck = $this->pdo->prepare(
            'SELECT 1 FROM (' . $sql . ') export_rows LIMIT 1 OFFSET ' . $maxRows
        );
        $limitCheck->execute($params);
        if ($limitCheck->fetchColumn() !== false) {
            throw new AnalyticsExportLimitExceeded($maxRows);
        }
        $limitCheck->closeCursor();

        $statement = $this->pdo->prepare(
            $sql . $orderBy . ' LIMIT ' . $maxRows
        );
        $statement->execute($params);
        return (static function () use ($statement, $mapRow): Generator {
            while (($row = $statement->fetch()) !== false) {
                yield $mapRow($row);
            }
            $statement->closeCursor();
        })();
    }

    private function normalizeTimezone(string $timezone): string
    {
        $timezone = trim($timezone);
        if ($timezone === 'UTC') {
            return 'UTC';
        }
        return strlen($timezone) <= 64 && in_array($timezone, DateTimeZone::listIdentifiers(), true)
            ? $timezone
            : 'UTC';
    }

    private function parseDate(string $value, DateTimeZone $timezone): ?DateTimeImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, $timezone);
        $errors = DateTimeImmutable::getLastErrors();
        return $date instanceof DateTimeImmutable
            && ($errors === false || ((int)$errors['warning_count'] === 0 && (int)$errors['error_count'] === 0))
            && $date->format('Y-m-d') === $value
            ? $date
            : null;
    }

    private function textFilter(array $input, string $key): string
    {
        $value = trim((string)($input[$key] ?? ''));
        $limit = self::FILTER_LIMITS[$key];
        return function_exists('mb_substr') ? mb_substr($value, 0, $limit) : substr($value, 0, $limit);
    }
}
