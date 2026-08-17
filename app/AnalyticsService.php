<?php

declare(strict_types=1);

final class AnalyticsService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string, mixed> */
    public function dashboard(int $days, ?int $linkId = null): array
    {
        $days = in_array($days, [7, 30, 90], true) ? $days : 30;
        $utc = new DateTimeZone('UTC');
        $today = new DateTimeImmutable('today', $utc);
        $currentStart = $today->modify('-' . ($days - 1) . ' days');
        $currentEnd = $today->modify('+1 day');
        $previousStart = $currentStart->modify("-{$days} days");
        $since = $currentStart->format('Y-m-d\T00:00:00\Z');
        $until = $currentEnd->format('Y-m-d\T00:00:00\Z');
        $previousSince = $previousStart->format('Y-m-d\T00:00:00\Z');
        [$linkSql, $params] = $this->linkFilter($linkId);
        $params['previous_since'] = $previousSince;
        $params['current_since'] = $since;
        $params['current_until'] = $until;

        $totals = $this->pdo->prepare(<<<SQL
            SELECT COALESCE(SUM(CASE WHEN accessed_hour >= :current_since THEN clicks ELSE 0 END), 0)
                       AS current_proxy_requests,
                   COALESCE(SUM(CASE WHEN accessed_hour >= :current_since
                       AND visitor_kind = 'suspected_human' THEN clicks ELSE 0 END), 0)
                       AS current_suspected_human_requests,
                   COALESCE(SUM(CASE WHEN accessed_hour >= :current_since
                       AND visitor_kind = 'bot' THEN clicks ELSE 0 END), 0) AS current_bot_requests,
                   COALESCE(SUM(CASE WHEN accessed_hour >= :current_since
                       AND visitor_kind = 'scanner' THEN clicks ELSE 0 END), 0) AS current_scanner_requests,
                   COALESCE(SUM(CASE WHEN accessed_hour >= :current_since
                       AND visitor_kind = 'unknown' THEN clicks ELSE 0 END), 0) AS current_unknown_requests,
                   COALESCE(SUM(CASE WHEN accessed_hour < :current_since THEN clicks ELSE 0 END), 0)
                       AS previous_proxy_requests,
                   COALESCE(SUM(CASE WHEN accessed_hour < :current_since
                       AND visitor_kind = 'suspected_human' THEN clicks ELSE 0 END), 0)
                       AS previous_suspected_human_requests,
                   COALESCE(SUM(CASE WHEN accessed_hour < :current_since
                       AND visitor_kind = 'bot' THEN clicks ELSE 0 END), 0) AS previous_bot_requests,
                   COALESCE(SUM(CASE WHEN accessed_hour < :current_since
                       AND visitor_kind = 'scanner' THEN clicks ELSE 0 END), 0) AS previous_scanner_requests,
                   COALESCE(SUM(CASE WHEN accessed_hour < :current_since
                       AND visitor_kind = 'unknown' THEN clicks ELSE 0 END), 0) AS previous_unknown_requests,
                   COALESCE(SUM(CASE WHEN accessed_hour >= :current_since
                       AND request_kind = 'redirect_get' THEN clicks ELSE 0 END), 0) AS get_requests,
                   COALESCE(SUM(CASE WHEN accessed_hour >= :current_since
                       AND request_kind = 'redirect_head' THEN clicks ELSE 0 END), 0) AS head_requests,
                   COALESCE(SUM(CASE WHEN accessed_hour >= :current_since
                       AND request_kind = 'confirmation_post' THEN clicks ELSE 0 END), 0)
                       AS confirmation_requests,
                   COALESCE(SUM(CASE WHEN accessed_hour >= :current_since
                       AND request_kind = 'legacy_unknown' THEN clicks ELSE 0 END), 0)
                       AS legacy_unknown_requests
            FROM visitor_hourly_stats
            WHERE accessed_hour >= :previous_since AND accessed_hour < :current_until{$linkSql}
        SQL);
        $totals->execute($params);
        $totalRow = $totals->fetch() ?: [];
        $currentTotals = [];
        $previousTotals = [];
        $deltas = [];
        $percentChanges = [];
        foreach ([
            'proxy_requests', 'suspected_human_requests', 'bot_requests',
            'scanner_requests', 'unknown_requests',
        ] as $metric) {
            $currentTotals[$metric] = (int)($totalRow['current_' . $metric] ?? 0);
            $previousTotals[$metric] = (int)($totalRow['previous_' . $metric] ?? 0);
            $deltas[$metric] = $currentTotals[$metric] - $previousTotals[$metric];
            $percentChanges[$metric] = $previousTotals[$metric] === 0
                ? null
                : round($deltas[$metric] * 100 / $previousTotals[$metric], 1);
        }

        $redirects = $this->redirectCount(
            $currentStart->format('Y-m-d'),
            $currentEnd->format('Y-m-d'),
            $linkId
        );
        $proxyRequests = $currentTotals['proxy_requests'];
        $headRequests = (int)($totalRow['head_requests'] ?? 0);
        $redirectDifference = $proxyRequests - $redirects;

        $trendStatement = $this->pdo->prepare(<<<SQL
            SELECT substr(accessed_hour, 1, 10) AS accessed_on,
                   SUM(CASE WHEN visitor_kind = 'suspected_human' THEN clicks ELSE 0 END)
                       AS suspected_human_requests,
                   SUM(CASE WHEN visitor_kind IN ('bot', 'scanner') THEN clicks ELSE 0 END)
                       AS automated_requests,
                   SUM(CASE WHEN visitor_kind = 'unknown' THEN clicks ELSE 0 END) AS unknown_requests
            FROM visitor_hourly_stats
            WHERE accessed_hour >= :since AND accessed_hour < :until{$linkSql}
            GROUP BY substr(accessed_hour, 1, 10)
        SQL);
        [, $trendParams] = $this->linkFilter($linkId);
        $trendParams['since'] = $since;
        $trendParams['until'] = $until;
        $trendStatement->execute($trendParams);
        $trendByDate = [];
        foreach ($trendStatement->fetchAll() as $row) {
            $trendByDate[(string)$row['accessed_on']] = [
                'suspected_human_requests' => (int)$row['suspected_human_requests'],
                'automated_requests' => (int)$row['automated_requests'],
                'unknown_requests' => (int)$row['unknown_requests'],
            ];
        }
        $trend = [];
        for ($offset = $days - 1; $offset >= 0; $offset--) {
            $date = $today->modify("-{$offset} days")->format('Y-m-d');
            $trend[] = array_merge(
                ['accessed_on' => $date],
                $trendByDate[$date] ?? [
                    'suspected_human_requests' => 0,
                    'automated_requests' => 0,
                    'unknown_requests' => 0,
                ]
            );
        }

        $hoursStatement = $this->pdo->prepare(<<<SQL
            SELECT CAST(substr(accessed_hour, 12, 2) AS INTEGER) AS utc_hour, SUM(clicks) AS clicks
            FROM visitor_hourly_stats
            WHERE accessed_hour >= :since AND accessed_hour < :until
              AND visitor_kind = 'suspected_human'{$linkSql}
            GROUP BY substr(accessed_hour, 12, 2)
        SQL);
        $hoursStatement->execute($trendParams);
        $hours = array_fill(0, 24, 0);
        foreach ($hoursStatement->fetchAll() as $row) {
            $hour = (int)$row['utc_hour'];
            if ($hour >= 0 && $hour <= 23) {
                $hours[$hour] = (int)$row['clicks'];
            }
        }

        return [
            'days' => $days,
            'periods' => [
                'current' => [
                    'start' => $currentStart->format('Y-m-d'),
                    'end' => $today->format('Y-m-d'),
                ],
                'previous' => [
                    'start' => $previousStart->format('Y-m-d'),
                    'end' => $currentStart->modify('-1 day')->format('Y-m-d'),
                ],
            ],
            'totals' => $currentTotals,
            'previous_totals' => $previousTotals,
            'deltas' => $deltas,
            'percent_changes' => $percentChanges,
            'reconciliation' => [
                'redirects' => $redirects,
                'proxy_requests' => $proxyRequests,
                'difference' => $redirectDifference,
                'difference_percent' => $redirects === 0
                    ? null
                    : round($redirectDifference * 100 / $redirects, 1),
                'difference_excluding_head' => $proxyRequests - $headRequests - $redirects,
                'get_requests' => (int)($totalRow['get_requests'] ?? 0),
                'head_requests' => $headRequests,
                'confirmation_requests' => (int)($totalRow['confirmation_requests'] ?? 0),
                'legacy_unknown_requests' => (int)($totalRow['legacy_unknown_requests'] ?? 0),
            ],
            'trend' => $trend,
            'hours' => $hours,
            'devices' => $this->breakdown('device_type', $since, $until, $linkId),
            'browsers' => $this->breakdown('browser', $since, $until, $linkId),
            'operating_systems' => $this->breakdown('operating_system', $since, $until, $linkId),
            'countries' => $this->breakdown('country_code', $since, $until, $linkId),
            'referrers' => $this->breakdown('referrer_domain', $since, $until, $linkId),
            'campaigns' => $this->campaignReport($days, $linkId),
        ];
    }

    public function linkOptions(): array
    {
        return $this->pdo->query(<<<'SQL'
            SELECT id, slug, title, campaign_name
            FROM links
            WHERE deleted_at IS NULL
            ORDER BY CASE WHEN campaign_name = '' THEN 1 ELSE 0 END,
                     campaign_name COLLATE NOCASE ASC, id DESC
        SQL)->fetchAll();
    }

    public function campaignReport(int $days, ?int $linkId = null): array
    {
        $days = in_array($days, [7, 30, 90], true) ? $days : 30;
        $utc = new DateTimeZone('UTC');
        $today = new DateTimeImmutable('today', $utc);
        $since = $today->modify('-' . ($days - 1) . ' days')->format('Y-m-d\T00:00:00\Z');
        $until = $today->modify('+1 day')->format('Y-m-d\T00:00:00\Z');
        [$linkSql, $params] = $this->linkFilter($linkId);
        $params['since'] = $since;
        $params['until'] = $until;
        $statement = $this->pdo->prepare(<<<SQL
            SELECT campaign_name, campaign_source, campaign_medium, campaign_content,
                   SUM(clicks) AS proxy_requests,
                   SUM(CASE WHEN visitor_kind = 'suspected_human' THEN clicks ELSE 0 END)
                       AS suspected_human_requests,
                   SUM(CASE WHEN visitor_kind = 'bot' THEN clicks ELSE 0 END) AS bot_requests,
                   SUM(CASE WHEN visitor_kind = 'scanner' THEN clicks ELSE 0 END) AS scanner_requests,
                   SUM(CASE WHEN visitor_kind = 'unknown' THEN clicks ELSE 0 END) AS unknown_requests
            FROM visitor_hourly_stats
            WHERE accessed_hour >= :since AND accessed_hour < :until{$linkSql}
              AND (campaign_name <> '' OR campaign_source <> '' OR campaign_medium <> '' OR campaign_content <> '')
            GROUP BY campaign_name, campaign_source, campaign_medium, campaign_content
            ORDER BY suspected_human_requests DESC, proxy_requests DESC, campaign_name COLLATE NOCASE ASC
            LIMIT 500
        SQL);
        $statement->execute($params);
        return $statement->fetchAll();
    }

    /**
     * Ingests Nginx analytics JSON or Caddy's standard JSON access log. Detailed
     * user agents are classified in memory and are never stored in SQLite.
     *
     * @return array{version: int, inode: string, offset: int, observed_size: int, active_backlog_bytes: int, backlog_bytes: int, completed_at: int, log_exists: bool, complete: bool, read: int, accepted: int, skipped: int}
     */
    public function ingestFile(string $logPath, string $statePath, int $maxLines = 100000): array
    {
        $startedAt = hrtime(true);
        $sourcePath = realpath($logPath) ?: $logPath;
        $maxLines = max(1, min(1000000, $maxLines));
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $activeExists = is_file($logPath);
            $activeHandle = $activeExists ? fopen($logPath, 'rb') : false;
            if (!is_resource($activeHandle) && $activeExists) {
                throw new RuntimeException('Cannot open the analytics access log.');
            }
            $source = null;
            $handle = false;
            try {
                $databaseState = $this->readDatabaseState($sourcePath);
                $source = $this->openIngestSource($logPath, $activeHandle, $databaseState);
                if ($source === null) {
                    $durationMs = max(0, (int)round((hrtime(true) - $startedAt) / 1_000_000));
                    $result = $this->successfulState($statePath, [
                        'version' => 1,
                        'inode' => '',
                        'offset' => 0,
                        'observed_size' => 0,
                        'active_backlog_bytes' => 0,
                        'backlog_bytes' => 0,
                        'completed_at' => time(),
                        'log_exists' => false,
                        'complete' => true,
                        'read' => 0,
                        'accepted' => 0,
                        'skipped' => 0,
                        'duration_ms' => $durationMs,
                        'lock_wait_ms' => 0,
                        'throughput_per_second' => 0,
                    ], null);
                    $this->writeState($statePath, $result);
                    return $result;
                }

                $handle = $source['handle'];
                $stat = fstat($handle) ?: [];
                $inode = (string)($stat['ino'] ?? '');
                $size = max(0, (int)($stat['size'] ?? 0));
                $state = $databaseState ?? $this->readState($statePath);
                $offset = (int)($state['offset'] ?? $state['byte_offset'] ?? 0);
                if (!empty($source['reset']) || (string)($state['inode'] ?? '') !== $inode
                    || $offset < 0 || $offset > $size) {
                    $offset = 0;
                }

                $batch = $this->readLogBatch($handle, $offset, $maxLines, !$source['active']);
                $observedStat = fstat($handle) ?: $stat;
                $observedSize = max(0, (int)($observedStat['size'] ?? $size));
                $checkpointHash = $this->checkpointHash($handle, $batch['offset']);
                $prepared = $this->prepareIngestBatch($batch['events']);
                $commit = $this->commitIngestBatch(
                    $prepared['aggregates'],
                    $prepared['accepted'],
                    $sourcePath,
                    $inode,
                    $batch['offset'],
                    $checkpointHash,
                    $databaseState
                );
                if ($commit === null) {
                    continue;
                }
                $accepted = $commit['accepted'];

                $skipped = $batch['skipped'] + count($batch['events']) - $accepted;
                $latestEventAt = null;
                foreach ($batch['events'] as $event) {
                    $eventTime = (int)floor((int)($event['accessed_at_ms'] ?? 0) / 1000);
                    if ($eventTime > 0 && ($latestEventAt === null || $eventTime > $latestEventAt)) {
                        $latestEventAt = $eventTime;
                    }
                }
                $activeStat = is_resource($activeHandle) ? (fstat($activeHandle) ?: []) : [];
                $activeSize = max(0, (int)($activeStat['size'] ?? 0));
                $activeBacklogBytes = !$source['active'] ? $activeSize : 0;
                $backlogBytes = max(0, $observedSize - $batch['offset']) + $activeBacklogBytes;
                $durationMs = max(0, (int)round((hrtime(true) - $startedAt) / 1_000_000));
                $result = $this->successfulState($statePath, [
                    'version' => 1,
                    'inode' => $inode,
                    'offset' => $batch['offset'],
                    'observed_size' => $observedSize,
                    'active_backlog_bytes' => $activeBacklogBytes,
                    'backlog_bytes' => $backlogBytes,
                    'completed_at' => time(),
                    'log_exists' => true,
                    'complete' => $batch['offset'] >= $observedSize
                        && ($source['active'] || $activeSize === 0),
                    'read' => $batch['read'],
                    'accepted' => $accepted,
                    'skipped' => $skipped,
                    'duration_ms' => $durationMs,
                    'lock_wait_ms' => $commit['lock_wait_ms'],
                    'throughput_per_second' => $durationMs > 0
                        ? (int)round($batch['read'] * 1000 / $durationMs)
                        : $batch['read'],
                ], $latestEventAt);
                // This file is an operational health marker; SQLite is the authoritative ingest position.
                $this->writeState($statePath, $result);
                return $result;
            } finally {
                if (is_array($source) && $source['close'] && is_resource($handle)) {
                    fclose($handle);
                }
                if (is_resource($activeHandle)) {
                    fclose($activeHandle);
                }
            }
        }
        throw new RuntimeException('Analytics ingest position changed concurrently.');
    }

    /**
     * @param resource|false $activeHandle
     * @return array{handle: resource, active: bool, close: bool, reset: bool}|null
     */
    private function openIngestSource(string $logPath, $activeHandle, ?array $state): ?array
    {
        $activeStat = is_resource($activeHandle) ? (fstat($activeHandle) ?: []) : [];
        $activeInode = (string)($activeStat['ino'] ?? '');
        $activeSize = max(0, (int)($activeStat['size'] ?? 0));
        $stateInode = (string)($state['inode'] ?? '');
        $stateOffset = max(0, (int)($state['byte_offset'] ?? $state['offset'] ?? 0));

        if ($stateInode !== '' && $stateInode !== $activeInode) {
            foreach (glob($logPath . '.*') ?: [] as $rotatedPath) {
                if (!is_file($rotatedPath)
                    || preg_match('/\.(?:bz2|gz|xz|zst)$/i', $rotatedPath) === 1) {
                    continue;
                }
                $rotatedHandle = fopen($rotatedPath, 'rb');
                if (!is_resource($rotatedHandle)) {
                    continue;
                }
                $rotatedStat = fstat($rotatedHandle) ?: [];
                if ((string)($rotatedStat['ino'] ?? '') === $stateInode
                    && $stateOffset < max(0, (int)($rotatedStat['size'] ?? 0))
                    && $this->checkpointMatches($rotatedHandle, $stateOffset, (string)($state['checkpoint_hash'] ?? ''))) {
                    return ['handle' => $rotatedHandle, 'active' => false, 'close' => true, 'reset' => false];
                }
                fclose($rotatedHandle);
            }
        }

        $checkpoint = (string)($state['checkpoint_hash'] ?? '');
        $activeRewritten = $stateInode !== '' && $stateInode === $activeInode && $stateOffset > 0
            && $checkpoint !== '' && !$this->checkpointMatches($activeHandle, $stateOffset, $checkpoint);
        if ($activeRewritten) {
            $legacyPath = $logPath . '.1';
            $legacyHandle = is_file($legacyPath) ? fopen($legacyPath, 'rb') : false;
            if (is_resource($legacyHandle)) {
                $legacyStat = fstat($legacyHandle) ?: [];
                if ($stateOffset < max(0, (int)($legacyStat['size'] ?? 0))
                    && $this->checkpointMatches($legacyHandle, $stateOffset, $checkpoint)) {
                    return ['handle' => $legacyHandle, 'active' => false, 'close' => true, 'reset' => false];
                }
                fclose($legacyHandle);
            }
            return is_resource($activeHandle)
                ? ['handle' => $activeHandle, 'active' => true, 'close' => false, 'reset' => true]
                : null;
        }

        // Best-effort migration recovery for cursors created before content checkpoints existed.
        if ($checkpoint === '' && $stateInode !== '' && $stateInode === $activeInode && $stateOffset > $activeSize) {
            $legacyPath = $logPath . '.1';
            $legacyHandle = is_file($legacyPath) ? fopen($legacyPath, 'rb') : false;
            if (is_resource($legacyHandle)) {
                $legacyStat = fstat($legacyHandle) ?: [];
                if ($stateOffset < max(0, (int)($legacyStat['size'] ?? 0))) {
                    return ['handle' => $legacyHandle, 'active' => false, 'close' => true, 'reset' => false];
                }
                fclose($legacyHandle);
            }
        }

        return is_resource($activeHandle)
            ? ['handle' => $activeHandle, 'active' => true, 'close' => false, 'reset' => false]
            : null;
    }

    /** @param resource|false $handle */
    private function checkpointMatches($handle, int $offset, string $expected): bool
    {
        if ($expected === '') {
            return true;
        }
        if (!is_resource($handle)) {
            return false;
        }
        $stat = fstat($handle) ?: [];
        if (max(0, (int)($stat['size'] ?? 0)) < $offset) {
            return false;
        }
        return hash_equals($expected, $this->checkpointHash($handle, $offset));
    }

    /** @param resource $handle */
    private function checkpointHash($handle, int $offset): string
    {
        if ($offset <= 0) {
            return '';
        }
        $position = ftell($handle);
        $length = min(4096, $offset);
        if (fseek($handle, $offset - $length) !== 0) {
            throw new RuntimeException('Cannot seek to the analytics cursor checkpoint.');
        }
        $contents = fread($handle, $length);
        if (!is_string($contents) || strlen($contents) !== $length) {
            throw new RuntimeException('Cannot read the analytics cursor checkpoint.');
        }
        if (is_int($position)) {
            fseek($handle, $position);
        }
        return hash('sha256', $contents);
    }

    /**
     * @param resource $handle
     * @return array{events: array<int, array<string, mixed>>, read: int, skipped: int, offset: int}
     */
    private function readLogBatch($handle, int $offset, int $maxLines, bool $finalized = false): array
    {
        if (fseek($handle, $offset) !== 0) {
            throw new RuntimeException('Cannot seek in the analytics access log.');
        }
        $events = [];
        $read = 0;
        $skipped = 0;
        $nextOffset = $offset;
        while ($read < $maxLines) {
            $lineStart = ftell($handle);
            $line = fgets($handle);
            if ($line === false) {
                break;
            }
            if (!str_ends_with($line, "\n") && feof($handle) && !$finalized) {
                $nextOffset = is_int($lineStart) ? $lineStart : $nextOffset;
                break;
            }
            $read++;
            $position = ftell($handle);
            $nextOffset = is_int($position) ? $position : $nextOffset;
            try {
                $entry = json_decode(trim($line), true, 32, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                $skipped++;
                continue;
            }
            $event = is_array($entry) ? $this->normalizeLogEntry($entry) : null;
            if ($event === null) {
                $skipped++;
                continue;
            }
            $events[] = $event;
        }
        return ['events' => $events, 'read' => $read, 'skipped' => $skipped, 'offset' => $nextOffset];
    }

    /** @return array{cutoff: string, hourly_rows_rolled_up: int, hourly_rows_deleted: int, daily_rows_deleted: int} */
    public function rollupAndRetain(int $hourlyDays, int $retentionDays, int $batchSize = 500): array
    {
        if ($hourlyDays < 1 || $hourlyDays > 36500
            || $retentionDays < $hourlyDays || $retentionDays > 36500
            || $batchSize < 1 || $batchSize > 5000) {
            throw new InvalidArgumentException('Analytics retention configuration is invalid.');
        }
        $cutoffDate = gmdate('Y-m-d', strtotime("-{$hourlyDays} days"));
        $cutoff = $cutoffDate . 'T00:00:00Z';
        $retentionCutoff = gmdate('Y-m-d', strtotime("-{$retentionDays} days"));
        $hourlyKey = 'link_id, accessed_hour, country_code, device_type, browser, operating_system, '
            . 'referrer_domain, visitor_kind, request_kind, campaign_name, campaign_source, '
            . 'campaign_medium, campaign_content';
        $hourlyRowsDeleted = 0;
        do {
            $batchDeleted = with_sqlite_retry(function () use ($cutoff, $batchSize, $hourlyKey): int {
                $this->pdo->exec('BEGIN IMMEDIATE');
                try {
                    $batch = "SELECT {$hourlyKey} FROM visitor_hourly_stats "
                        . 'WHERE accessed_hour < :cutoff ORDER BY accessed_hour ASC LIMIT :batch_size';
                    $rollup = $this->pdo->prepare(<<<SQL
                        INSERT INTO visitor_daily_stats (
                            link_id, accessed_on, country_code, device_type, browser, operating_system,
                            referrer_domain, visitor_kind, request_kind, campaign_name, campaign_source,
                            campaign_medium, campaign_content, clicks
                        )
                        SELECT link_id, substr(accessed_hour, 1, 10), country_code, device_type, browser,
                               operating_system, referrer_domain, visitor_kind, request_kind, campaign_name,
                               campaign_source, campaign_medium, campaign_content, SUM(clicks)
                        FROM visitor_hourly_stats
                        WHERE ({$hourlyKey}) IN ({$batch})
                        GROUP BY link_id, substr(accessed_hour, 1, 10), country_code, device_type, browser,
                                 operating_system, referrer_domain, visitor_kind, request_kind, campaign_name,
                                 campaign_source, campaign_medium, campaign_content
                        ON CONFLICT (
                            link_id, accessed_on, country_code, device_type, browser, operating_system,
                            referrer_domain, visitor_kind, request_kind, campaign_name, campaign_source,
                            campaign_medium, campaign_content
                        ) DO UPDATE SET clicks = clicks + excluded.clicks
                    SQL);
                    $rollup->bindValue(':cutoff', $cutoff);
                    $rollup->bindValue(':batch_size', $batchSize, PDO::PARAM_INT);
                    $rollup->execute();
                    $delete = $this->pdo->prepare(
                        "DELETE FROM visitor_hourly_stats WHERE ({$hourlyKey}) IN ({$batch})"
                    );
                    $delete->bindValue(':cutoff', $cutoff);
                    $delete->bindValue(':batch_size', $batchSize, PDO::PARAM_INT);
                    $delete->execute();
                    $count = $delete->rowCount();
                    $this->pdo->commit();
                    return $count;
                } catch (Throwable $exception) {
                    if ($this->pdo->inTransaction()) {
                        $this->pdo->rollBack();
                    }
                    throw $exception;
                }
            });
            $hourlyRowsDeleted += $batchDeleted;
        } while ($batchDeleted === $batchSize);

        $dailyKey = 'link_id, accessed_on, country_code, device_type, browser, operating_system, '
            . 'referrer_domain, visitor_kind, request_kind, campaign_name, campaign_source, '
            . 'campaign_medium, campaign_content';
        $deleteDaily = $this->pdo->prepare(<<<SQL
            DELETE FROM visitor_daily_stats
            WHERE ({$dailyKey}) IN (
                SELECT {$dailyKey} FROM visitor_daily_stats
                WHERE accessed_on < :retention_cutoff
                ORDER BY accessed_on ASC LIMIT :batch_size
            )
        SQL);
        $dailyRowsDeleted = 0;
        do {
            $deleteDaily->bindValue(':retention_cutoff', $retentionCutoff);
            $deleteDaily->bindValue(':batch_size', $batchSize, PDO::PARAM_INT);
            with_sqlite_retry(fn () => $deleteDaily->execute());
            $batchDeleted = $deleteDaily->rowCount();
            $dailyRowsDeleted += $batchDeleted;
        } while ($batchDeleted === $batchSize);

        $deleteDimensions = $this->pdo->prepare(<<<'SQL'
            DELETE FROM analytics_daily_dimensions
            WHERE (link_id, accessed_on, country_code, device_type, browser, operating_system,
                   referrer_domain, visitor_kind, request_kind, campaign_name, campaign_source,
                   campaign_medium, campaign_content) IN (
                SELECT link_id, accessed_on, country_code, device_type, browser, operating_system,
                       referrer_domain, visitor_kind, request_kind, campaign_name, campaign_source,
                       campaign_medium, campaign_content
                FROM analytics_daily_dimensions
                WHERE accessed_on < :retention_cutoff
                ORDER BY accessed_on ASC LIMIT :batch_size
            )
        SQL);
        do {
            $deleteDimensions->bindValue(':retention_cutoff', $retentionCutoff);
            $deleteDimensions->bindValue(':batch_size', $batchSize, PDO::PARAM_INT);
            with_sqlite_retry(fn () => $deleteDimensions->execute());
            $dimensionsDeleted = $deleteDimensions->rowCount();
        } while ($dimensionsDeleted === $batchSize);

        return [
            'cutoff' => $cutoffDate,
            'hourly_rows_rolled_up' => $hourlyRowsDeleted,
            'hourly_rows_deleted' => $hourlyRowsDeleted,
            'daily_rows_deleted' => $dailyRowsDeleted,
        ];
    }

    private function redirectCount(string $since, string $until, ?int $linkId): int
    {
        $currentLinkSql = $linkId !== null && $linkId > 0 ? ' AND link_id = :current_link_id' : '';
        $archiveLinkSql = $linkId !== null && $linkId > 0 ? ' AND link_id = :archive_link_id' : '';
        $statement = $this->pdo->prepare(<<<SQL
            SELECT COALESCE(SUM(clicks), 0)
            FROM (
                SELECT clicks
                FROM link_daily_stats
                WHERE accessed_on >= :current_since AND accessed_on < :current_until{$currentLinkSql}
                UNION ALL
                SELECT clicks
                FROM link_daily_stats_archive archived
                WHERE accessed_on >= :archive_since AND accessed_on < :archive_until{$archiveLinkSql}
                  AND EXISTS (SELECT 1 FROM links WHERE links.id = archived.link_id)
            ) redirect_rows
        SQL);
        $params = [
            'current_since' => $since,
            'current_until' => $until,
            'archive_since' => $since,
            'archive_until' => $until,
        ];
        if ($linkId !== null && $linkId > 0) {
            $params['current_link_id'] = $linkId;
            $params['archive_link_id'] = $linkId;
        }
        $statement->execute($params);
        return (int)$statement->fetchColumn();
    }

    private function breakdown(string $dimension, string $since, string $until, ?int $linkId): array
    {
        if (!in_array($dimension, [
            'device_type', 'browser', 'operating_system', 'country_code', 'referrer_domain',
        ], true)) {
            throw new InvalidArgumentException('Unsupported analytics dimension.');
        }
        [$linkSql, $params] = $this->linkFilter($linkId);
        $params['since'] = $since;
        $params['until'] = $until;
        $statement = $this->pdo->prepare(<<<SQL
            SELECT {$dimension} AS label, SUM(clicks) AS clicks
            FROM visitor_hourly_stats
            WHERE accessed_hour >= :since AND accessed_hour < :until
              AND visitor_kind = 'suspected_human'{$linkSql}
            GROUP BY {$dimension}
            ORDER BY clicks DESC, {$dimension} COLLATE NOCASE ASC
            LIMIT 8
        SQL);
        $statement->execute($params);
        return $statement->fetchAll();
    }

    /** @return array{0: string, 1: array<string, int>} */
    private function linkFilter(?int $linkId): array
    {
        return $linkId !== null && $linkId > 0
            ? [' AND link_id = :link_id', ['link_id' => $linkId]]
            : ['', []];
    }

    private function normalizeLogEntry(array $entry): ?array
    {
        $request = is_array($entry['request'] ?? null) ? $entry['request'] : [];
        $headers = is_array($request['headers'] ?? null) ? $request['headers'] : [];
        $method = strtoupper((string)($entry['method'] ?? $request['method'] ?? ''));
        $uri = (string)($entry['uri'] ?? $request['uri'] ?? '');
        $status = (int)($entry['status'] ?? 0);
        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path)
            || preg_match('#^/([A-Za-z0-9_-]{3,64})(/confirm)?$#', $path, $matches) !== 1) {
            return null;
        }
        $isConfirmation = ($matches[2] ?? '') === '/confirm';
        if (($isConfirmation && ($method !== 'POST' || $status !== 303))
            || (!$isConfirmation && (!in_array($method, ['GET', 'HEAD'], true) || $status !== 302))) {
            return null;
        }

        $time = $entry['time'] ?? $entry['ts'] ?? null;
        try {
            if (is_int($time) || is_float($time)) {
                $date = DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', (float)$time));
                if (!$date instanceof DateTimeImmutable) {
                    return null;
                }
                $date = $date->setTimezone(new DateTimeZone('UTC'));
            } elseif (is_string($time) && $time !== '') {
                $date = (new DateTimeImmutable($time))->setTimezone(new DateTimeZone('UTC'));
            } else {
                return null;
            }
        } catch (Throwable) {
            return null;
        }

        $userAgent = (string)($entry['user_agent'] ?? $this->headerValue($headers, 'User-Agent'));
        $referrer = (string)($entry['referrer'] ?? $entry['referer'] ?? $this->headerValue($headers, 'Referer'));
        $loggedReferrerDomain = (string)($entry['referrer_domain'] ?? '');
        $country = strtoupper(trim((string)($entry['country'] ?? $this->headerValue($headers, 'Cf-Ipcountry'))));
        if (preg_match('/^[A-Z]{2}$/', $country) !== 1) {
            $country = 'ZZ';
        }

        return [
            'slug' => $matches[1],
            'accessed_at_ms' => (int)$date->format('Uv'),
            'accessed_hour' => $date->format('Y-m-d\TH:00:00\Z'),
            'country_code' => $country,
            'device_type' => $this->classifyDevice($userAgent),
            'browser' => $this->classifyBrowser($userAgent),
            'operating_system' => $this->classifyOperatingSystem($userAgent),
            'referrer_domain' => $this->referrerDomain($referrer, $loggedReferrerDomain),
            'visitor_kind' => $this->classifyVisitor($userAgent, $method),
            'request_kind' => $isConfirmation
                ? 'confirmation_post'
                : ($method === 'HEAD' ? 'redirect_head' : 'redirect_get'),
        ];
    }

    /** @return array{aggregates: array<string, array<string, int|string>>, accepted: int} */
    private function prepareIngestBatch(array $events): array
    {
        $slugs = [];
        foreach ($events as $event) {
            $slug = (string)$event['slug'];
            $slugs[$slug] = $slug;
        }

        $links = [];
        foreach (array_chunk(array_values($slugs), 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $statement = $this->pdo->prepare(<<<SQL
                SELECT id, slug FROM links WHERE slug IN ({$placeholders})
                UNION ALL
                SELECT link_id AS id, alias AS slug FROM link_aliases WHERE alias IN ({$placeholders})
            SQL);
            $statement->execute(array_merge($chunk, $chunk));
            foreach ($statement as $link) {
                $links[(string)$link['slug']] = (int)$link['id'];
            }
        }

        $linkIds = array_values(array_unique(array_values($links)));
        $snapshots = [];
        foreach (array_chunk($linkIds, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $statement = $this->pdo->prepare(<<<SQL
                SELECT link_id, effective_at_ms, campaign_name, campaign_source,
                       campaign_medium, campaign_content
                FROM link_campaign_snapshots
                WHERE link_id IN ({$placeholders})
                ORDER BY link_id ASC, effective_at_ms ASC
            SQL);
            $statement->execute($chunk);
            foreach ($statement as $snapshot) {
                $snapshots[(int)$snapshot['link_id']][] = $snapshot;
            }
        }

        $aggregates = [];
        $accepted = 0;
        foreach ($events as $event) {
            $linkId = $links[(string)$event['slug']] ?? null;
            if (!is_int($linkId)) {
                continue;
            }
            $campaign = $this->campaignAt(
                $snapshots[$linkId] ?? [],
                (int)$event['accessed_at_ms']
            );
            $row = [
                'link_id' => $linkId,
                'accessed_hour' => (string)$event['accessed_hour'],
                'country_code' => (string)$event['country_code'],
                'device_type' => (string)$event['device_type'],
                'browser' => (string)$event['browser'],
                'operating_system' => (string)$event['operating_system'],
                'referrer_domain' => (string)$event['referrer_domain'],
                'visitor_kind' => (string)$event['visitor_kind'],
                'request_kind' => (string)$event['request_kind'],
                'campaign_name' => $campaign['campaign_name'],
                'campaign_source' => $campaign['campaign_source'],
                'campaign_medium' => $campaign['campaign_medium'],
                'campaign_content' => $campaign['campaign_content'],
            ];
            $key = implode("\x1F", array_map('strval', $row));
            if (!isset($aggregates[$key])) {
                $aggregates[$key] = $row + ['clicks' => 0];
            }
            $aggregates[$key]['clicks']++;
            $accepted++;
        }

        return ['aggregates' => $aggregates, 'accepted' => $accepted];
    }

    private function commitIngestBatch(
        array $aggregates,
        int $accepted,
        string $sourcePath,
        string $inode,
        int $nextOffset,
        string $checkpointHash,
        ?array $expectedState
    ): ?array {
        $lockStartedAt = hrtime(true);
        $this->pdo->exec('BEGIN IMMEDIATE');
        $lockWaitMs = max(0, (int)round((hrtime(true) - $lockStartedAt) / 1_000_000));
        try {
            $currentState = $this->readDatabaseState($sourcePath);
            if (!$this->sameDatabaseState($currentState, $expectedState)) {
                $this->pdo->rollBack();
                return null;
            }

            $statement = $this->pdo->prepare(<<<'SQL'
                INSERT INTO visitor_hourly_stats (
                    link_id, accessed_hour, country_code, device_type, browser, operating_system,
                    referrer_domain, visitor_kind, request_kind, campaign_name, campaign_source,
                    campaign_medium, campaign_content, clicks
                ) VALUES (
                    :link_id, :accessed_hour, :country_code, :device_type, :browser, :operating_system,
                    :referrer_domain, :visitor_kind, :request_kind, :campaign_name, :campaign_source,
                    :campaign_medium, :campaign_content, :clicks
                )
                ON CONFLICT (
                    link_id, accessed_hour, country_code, device_type, browser, operating_system,
                    referrer_domain, visitor_kind, request_kind, campaign_name, campaign_source,
                    campaign_medium, campaign_content
                ) DO UPDATE SET clicks = clicks + excluded.clicks
            SQL);
            $dailyDimensions = $this->pdo->prepare(<<<'SQL'
                INSERT INTO analytics_daily_dimensions (
                    link_id, accessed_on, country_code, device_type, browser, operating_system,
                    referrer_domain, visitor_kind, request_kind, campaign_name, campaign_source,
                    campaign_medium, campaign_content, clicks
                ) VALUES (
                    :link_id, :accessed_on, :country_code, :device_type, :browser, :operating_system,
                    :referrer_domain, :visitor_kind, :request_kind, :campaign_name, :campaign_source,
                    :campaign_medium, :campaign_content, :clicks
                )
                ON CONFLICT (
                    link_id, accessed_on, country_code, device_type, browser, operating_system,
                    referrer_domain, visitor_kind, request_kind, campaign_name, campaign_source,
                    campaign_medium, campaign_content
                ) DO UPDATE SET clicks = clicks + excluded.clicks
            SQL);
            foreach ($aggregates as $aggregate) {
                $statement->execute($aggregate);
                $dailyAggregate = $aggregate;
                $dailyAggregate['accessed_on'] = substr((string)$dailyAggregate['accessed_hour'], 0, 10);
                unset($dailyAggregate['accessed_hour']);
                $dailyDimensions->execute($dailyAggregate);
            }

            $stateStatement = $this->pdo->prepare(<<<'SQL'
                INSERT INTO analytics_ingest_state (source_path, inode, byte_offset, checkpoint_hash, updated_at)
                VALUES (:source_path, :inode, :byte_offset, :checkpoint_hash, :updated_at)
                ON CONFLICT(source_path) DO UPDATE SET
                    inode = excluded.inode,
                    byte_offset = excluded.byte_offset,
                    checkpoint_hash = excluded.checkpoint_hash,
                    updated_at = excluded.updated_at
            SQL);
            $stateStatement->execute([
                'source_path' => $sourcePath,
                'inode' => $inode,
                'byte_offset' => $nextOffset,
                'checkpoint_hash' => $checkpointHash,
                'updated_at' => gmdate('c'),
            ]);
            $this->pdo->commit();
            return ['accepted' => $accepted, 'lock_wait_ms' => $lockWaitMs];
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function readDatabaseState(string $sourcePath): ?array
    {
        $statement = $this->pdo->prepare(<<<'SQL'
            SELECT inode, byte_offset, checkpoint_hash
            FROM analytics_ingest_state
            WHERE source_path = :source_path
        SQL);
        $statement->execute(['source_path' => $sourcePath]);
        $state = $statement->fetch();
        return is_array($state) ? $state : null;
    }

    private function sameDatabaseState(?array $left, ?array $right): bool
    {
        if ($left === null || $right === null) {
            return $left === $right;
        }
        return (string)($left['inode'] ?? '') === (string)($right['inode'] ?? '')
            && (int)($left['byte_offset'] ?? -1) === (int)($right['byte_offset'] ?? -1)
            && (string)($left['checkpoint_hash'] ?? '') === (string)($right['checkpoint_hash'] ?? '');
    }

    /** @return array{campaign_name: string, campaign_source: string, campaign_medium: string, campaign_content: string} */
    private function campaignAt(array $snapshots, int $accessedAtMs): array
    {
        $campaign = [
            'campaign_name' => '',
            'campaign_source' => '',
            'campaign_medium' => '',
            'campaign_content' => '',
        ];
        foreach ($snapshots as $snapshot) {
            if ((int)$snapshot['effective_at_ms'] > $accessedAtMs) {
                break;
            }
            $campaign = [
                'campaign_name' => (string)$snapshot['campaign_name'],
                'campaign_source' => (string)$snapshot['campaign_source'],
                'campaign_medium' => (string)$snapshot['campaign_medium'],
                'campaign_content' => (string)$snapshot['campaign_content'],
            ];
        }
        return $campaign;
    }

    private function classifyVisitor(string $userAgent, string $method): string
    {
        if ($method === 'HEAD' || preg_match(
            '/(?:curl|wget|python-requests|go-http-client|okhttp|postmanruntime|zgrab|masscan|nmap|nikto|nessus|acunetix|nuclei|urlscan|securityheaders|linkchecker|uptimerobot|pingdom|statuscake)/i',
            $userAgent
        ) === 1) {
            return 'scanner';
        }
        if (preg_match(
            '/(?:bot|crawler|spider|slurp|facebookexternalhit|twitterbot|linkedinbot|telegrambot|discordbot|whatsapp|preview)/i',
            $userAgent
        ) === 1) {
            return 'bot';
        }
        if (trim($userAgent) === '') {
            return 'unknown';
        }
        return $this->classifyBrowser($userAgent) === 'Other' ? 'unknown' : 'suspected_human';
    }

    private function classifyDevice(string $userAgent): string
    {
        if (preg_match('/(?:iPad|Tablet|Nexus 7|Nexus 10|SM-T\d+)/i', $userAgent) === 1) {
            return 'tablet';
        }
        if (preg_match('/(?:Mobile|Android|iPhone|Windows Phone)/i', $userAgent) === 1) {
            return 'mobile';
        }
        return trim($userAgent) === '' ? 'other' : 'desktop';
    }

    private function classifyBrowser(string $userAgent): string
    {
        return match (true) {
            preg_match('/Edg(?:e|A|iOS)?\//i', $userAgent) === 1 => 'Edge',
            preg_match('/OPR\//i', $userAgent) === 1 => 'Opera',
            preg_match('/SamsungBrowser\//i', $userAgent) === 1 => 'Samsung Internet',
            preg_match('/(?:Chrome|CriOS)\//i', $userAgent) === 1 => 'Chrome',
            preg_match('/(?:Firefox|FxiOS)\//i', $userAgent) === 1 => 'Firefox',
            preg_match('/Version\/.*Safari\//i', $userAgent) === 1 => 'Safari',
            preg_match('/(?:MSIE |Trident\/)/i', $userAgent) === 1 => 'Internet Explorer',
            default => 'Other',
        };
    }

    private function classifyOperatingSystem(string $userAgent): string
    {
        return match (true) {
            preg_match('/(?:iPhone|iPad|iPod)/i', $userAgent) === 1 => 'iOS',
            preg_match('/Android/i', $userAgent) === 1 => 'Android',
            preg_match('/Windows/i', $userAgent) === 1 => 'Windows',
            preg_match('/CrOS/i', $userAgent) === 1 => 'ChromeOS',
            preg_match('/Macintosh|Mac OS X/i', $userAgent) === 1 => 'macOS',
            preg_match('/Linux/i', $userAgent) === 1 => 'Linux',
            default => 'Other',
        };
    }

    private function referrerDomain(string $referrer, string $loggedDomain = ''): string
    {
        if ($loggedDomain !== '') {
            $domain = strtolower(trim($loggedDomain));
            return preg_match('/^[a-z0-9](?:[a-z0-9.-]{0,178}[a-z0-9])?$/', $domain) === 1
                ? $domain
                : 'direct';
        }
        if (trim($referrer) === '' || $referrer === '-') {
            return 'direct';
        }
        $host = parse_url($referrer, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return 'direct';
        }
        return substr(strtolower($host), 0, 180);
    }

    private function headerValue(array $headers, string $name): string
    {
        foreach ($headers as $headerName => $value) {
            if (strcasecmp((string)$headerName, $name) !== 0) {
                continue;
            }
            if (is_array($value)) {
                return is_scalar($value[0] ?? null) ? (string)$value[0] : '';
            }
            return is_scalar($value) ? (string)$value : '';
        }
        return '';
    }

    private function readState(string $statePath): array
    {
        if (!is_file($statePath)) {
            return [];
        }
        try {
            $decoded = json_decode((string)file_get_contents($statePath), true, 8, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : [];
        } catch (Throwable) {
            return [];
        }
    }

    private function successfulState(string $statePath, array $result, ?int $latestEventAt): array
    {
        $previous = $this->readState($statePath);
        $previousLatest = (int)($previous['latest_event_at'] ?? 0);
        $now = time();
        return $result + [
            'last_attempt_at' => $now,
            'last_success_at' => $now,
            'failure_count' => max(0, (int)($previous['failure_count'] ?? 0)),
            'consecutive_failures' => 0,
            'last_failure_at' => max(0, (int)($previous['last_failure_at'] ?? 0)),
            'last_error' => '',
            'latest_event_at' => max($previousLatest, $latestEventAt ?? 0),
        ];
    }

    private function writeState(string $statePath, array $state): void
    {
        $directory = dirname($statePath);
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException('Cannot create the analytics state directory.');
        }
        $temporary = $statePath . '.tmp.' . getmypid();
        $json = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($temporary, $json . "\n", LOCK_EX) === false) {
            throw new RuntimeException('Cannot write analytics ingest state.');
        }
        @chmod($temporary, 0600);
        if (!rename($temporary, $statePath)) {
            @unlink($temporary);
            throw new RuntimeException('Cannot publish analytics ingest state.');
        }
    }
}
