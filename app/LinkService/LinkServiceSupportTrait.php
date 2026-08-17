<?php

declare(strict_types=1);

trait LinkServiceSupportTrait
{
    private function adminFilter(
        string $view,
        string $search,
        string $status,
        string $tag,
        bool $favoritesOnly
    ): array {
        $where = $view === 'trash' ? 'l.deleted_at IS NOT NULL' : 'l.deleted_at IS NULL';
        $params = [];
        if ($view !== 'trash') {
            $now = utc_timestamp();
            $domainAvailableSql = '(l.short_domain_id IS NULL OR EXISTS (SELECT 1 FROM short_domains status_domain'
                . ' WHERE status_domain.id = l.short_domain_id AND status_domain.verified_at IS NOT NULL'
                . ' AND status_domain.is_enabled = 1))';
            $statusSql = match ($status) {
                'active' => 'l.is_active = 1'
                    . ' AND ' . $domainAvailableSql
                    . ' AND (l.starts_at IS NULL OR l.starts_at <= :status_now)'
                    . ' AND (l.expires_at IS NULL OR l.expires_at > :status_now)'
                    . ' AND (l.max_clicks IS NULL OR l.clicks < l.max_clicks)'
                    . ' AND (l.is_one_time = 0 OR l.clicks = 0)',
                'inactive' => 'l.is_active = 0 OR (l.short_domain_id IS NOT NULL AND NOT EXISTS ('
                    . 'SELECT 1 FROM short_domains status_domain WHERE status_domain.id = l.short_domain_id'
                    . ' AND status_domain.verified_at IS NOT NULL AND status_domain.is_enabled = 1))',
                'scheduled' => 'l.is_active = 1 AND ' . $domainAvailableSql
                    . ' AND l.starts_at IS NOT NULL AND l.starts_at > :status_now',
                'expired' => 'l.is_active = 1 AND (l.starts_at IS NULL OR l.starts_at <= :status_now)'
                    . ' AND ' . $domainAvailableSql
                    . ' AND l.expires_at IS NOT NULL AND l.expires_at <= :status_now',
                'exhausted' => 'l.is_active = 1 AND (l.starts_at IS NULL OR l.starts_at <= :status_now)'
                    . ' AND ' . $domainAvailableSql
                    . ' AND (l.expires_at IS NULL OR l.expires_at > :status_now)'
                    . ' AND ((l.max_clicks IS NOT NULL AND l.clicks >= l.max_clicks) OR (l.is_one_time = 1 AND l.clicks >= 1))',
                default => '',
            };
            if ($statusSql !== '') {
                $where .= ' AND (' . $statusSql . ')';
                if (str_contains($statusSql, ':status_now')) {
                    $params['status_now'] = $now;
                }
            }
            if ($favoritesOnly) {
                $where .= ' AND l.is_favorite = 1';
            }
        }
        if ($tag !== '') {
            $where .= ' AND EXISTS (SELECT 1 FROM link_tags filter_tags WHERE filter_tags.link_id = l.id AND filter_tags.tag = :filter_tag)';
            $params['filter_tag'] = $tag;
        }
        if ($search === '') {
            return [$where, $params];
        }

        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
        $searchValue = '%' . $escaped . '%';
        $canUseFts = !str_contains($search, '%')
            && !str_contains($search, '_')
            && preg_match('/[\p{L}\p{N}]/u', $search) === 1;
        if ($canUseFts) {
            $where .= " AND (l.id IN (SELECT rowid FROM links_fts WHERE links_fts MATCH :search_match)"
                . " OR EXISTS (SELECT 1 FROM link_tags search_tags WHERE search_tags.link_id = l.id AND search_tags.tag LIKE :search_tag ESCAPE '\\'))";
            $params['search_match'] = '"' . str_replace('"', '""', $search) . '"*';
            $params['search_tag'] = $searchValue;
            return [$where, $params];
        }

        $where .= " AND (l.title LIKE :search_title ESCAPE '\\'"
            . " OR l.slug LIKE :search_slug ESCAPE '\\'"
            . " OR l.target_url LIKE :search_target ESCAPE '\\'"
            . " OR EXISTS (SELECT 1 FROM link_tags search_tags WHERE search_tags.link_id = l.id AND search_tags.tag LIKE :search_tag ESCAPE '\\'))";
        return [$where, array_merge($params, [
            'search_title' => $searchValue,
            'search_slug' => $searchValue,
            'search_target' => $searchValue,
            'search_tag' => $searchValue,
        ])];
    }

    private function maintenanceClause(
        string $category,
        int $expiringDays = 7,
        int $staleDays = 90,
        int $quotaPercent = 80,
        ?string $evaluatedAt = null
    ): array
    {
        $now = $evaluatedAt ?? utc_timestamp();
        $evaluationTimestamp = strtotime($now);
        if ($evaluationTimestamp === false) {
            throw new InvalidArgumentException('Maintenance evaluation time is invalid.');
        }
        $available = 'l.is_active = 1 AND (l.starts_at IS NULL OR l.starts_at <= :maintenance_now)'
            . ' AND (l.expires_at IS NULL OR l.expires_at > :maintenance_now)'
            . ' AND (l.max_clicks IS NULL OR l.clicks < l.max_clicks)'
            . ' AND (l.is_one_time = 0 OR l.clicks = 0)';
        return match ($category) {
            'target_health' => [
                "l.is_active = 1 AND EXISTS (
                    SELECT 1 FROM target_health maintenance_health
                    WHERE maintenance_health.link_id = l.id
                      AND maintenance_health.state <> 'healthy'
                      AND maintenance_health.ignored_at IS NULL
                )",
                [],
            ],
            'stale_zero' => [
                "{$available} AND l.clicks = 0 AND l.created_at <= :maintenance_stale_before",
                [
                    'maintenance_now' => $now,
                    'maintenance_stale_before' => gmdate(
                        'Y-m-d\TH:i:s\Z',
                        $evaluationTimestamp - max(1, $staleDays) * 86400
                    ),
                ],
            ],
            'quota' => [
                "{$available} AND l.is_one_time = 0 AND l.max_clicks IS NOT NULL"
                    . ' AND l.clicks * 100 >= l.max_clicks * :maintenance_quota_percent',
                ['maintenance_now' => $now, 'maintenance_quota_percent' => max(1, min(99, $quotaPercent))],
            ],
            'invalid' => [
                'l.is_active = 0 OR (l.starts_at IS NULL OR l.starts_at <= :maintenance_now)'
                    . ' AND ((l.expires_at IS NOT NULL AND l.expires_at <= :maintenance_now)'
                    . ' OR (l.max_clicks IS NOT NULL AND l.clicks >= l.max_clicks)'
                    . ' OR (l.is_one_time = 1 AND l.clicks >= 1))',
                ['maintenance_now' => $now],
            ],
            default => [
                "{$available} AND l.expires_at IS NOT NULL AND l.expires_at <= :maintenance_expiring_before",
                [
                    'maintenance_now' => $now,
                    'maintenance_expiring_before' => gmdate(
                        'Y-m-d\TH:i:s\Z',
                        $evaluationTimestamp + max(1, $expiringDays) * 86400
                    ),
                ],
            ],
        };
    }

    private function adminOrder(string $sort): string
    {
        return match ($sort) {
            'created_asc' => 'l.id ASC',
            'clicks_desc' => 'l.clicks DESC, l.id DESC',
            'clicks_asc' => 'l.clicks ASC, l.id DESC',
            'last_accessed_desc' => 'l.last_accessed_at DESC, l.id DESC',
            'title_asc' => "CASE WHEN l.title = '' THEN 1 ELSE 0 END, l.title COLLATE NOCASE ASC, l.id DESC",
            default => 'l.id DESC',
        };
    }

    private function adminReverseOrder(string $sort): string
    {
        return match ($sort) {
            'created_asc' => 'l.id DESC',
            'clicks_desc' => 'l.clicks ASC, l.id ASC',
            'clicks_asc' => 'l.clicks DESC, l.id ASC',
            'last_accessed_desc' => 'l.last_accessed_at ASC, l.id ASC',
            'title_asc' => "CASE WHEN l.title = '' THEN 1 ELSE 0 END DESC, l.title COLLATE NOCASE DESC, l.id ASC",
            default => 'l.id ASC',
        };
    }

    private function changeDeletedState(int $id, bool $delete, ?string $expectedUpdatedAt = null): bool
    {
        return (bool)with_sqlite_retry(function () use ($id, $delete, $expectedUpdatedAt): bool {
            $this->pdo->beginTransaction();
            try {
                $before = $this->findById($id);
                if (!$before || ($delete ? !empty($before['deleted_at']) : empty($before['deleted_at']))
                    || ($expectedUpdatedAt !== null && (string)$before['updated_at'] !== $expectedUpdatedAt)) {
                    $this->pdo->rollBack();
                    return false;
                }
                $now = utc_timestamp();
                $sql = $delete
                    ? 'UPDATE links SET deleted_at = :changed_at, updated_at = :updated_at WHERE id = :id AND deleted_at IS NULL'
                    : 'UPDATE links SET deleted_at = NULL, updated_at = :updated_at WHERE id = :id AND deleted_at IS NOT NULL';
                if ($expectedUpdatedAt !== null) {
                    $sql .= ' AND updated_at = :expected_updated_at';
                }
                $statement = $this->pdo->prepare($sql);
                $params = ['updated_at' => $now, 'id' => $id];
                if ($delete) {
                    $params['changed_at'] = $now;
                }
                if ($expectedUpdatedAt !== null) {
                    $params['expected_updated_at'] = $expectedUpdatedAt;
                }
                $statement->execute($params);
                if ($statement->rowCount() === 0) {
                    $this->pdo->rollBack();
                    return false;
                }
                $after = $this->findById($id);
                $this->recordStatusChange(
                    $id,
                    $delete ? 'deleted' : 'restored',
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

    private function findById(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM links WHERE id = :id');
        $statement->execute(['id' => $id]);
        $link = $statement->fetch();
        return $link ?: null;
    }

    private function replaceTags(int $id, array $tags): void
    {
        $delete = $this->pdo->prepare('DELETE FROM link_tags WHERE link_id = :link_id');
        $delete->execute(['link_id' => $id]);
        if (!$tags) {
            return;
        }
        $insert = $this->pdo->prepare('INSERT INTO link_tags (link_id, tag) VALUES (:link_id, :tag)');
        foreach (array_values(array_unique($tags)) as $tag) {
            $insert->execute(['link_id' => $id, 'tag' => limit_text((string)$tag, 24)]);
        }
    }

    private function recordStatusChange(
        int $id,
        string $event,
        string $fromStatus,
        string $toStatus,
        string $now
    ): void {
        if ($fromStatus === $toStatus) {
            return;
        }
        $this->addHistory($id, $event, $fromStatus, $toStatus, $now);
    }

    private function addHistory(
        int $id,
        string $event,
        ?string $fromStatus,
        string $toStatus,
        string $now
    ): void {
        $statement = $this->pdo->prepare(<<<'SQL'
            INSERT INTO link_status_history (link_id, event, from_status, to_status, created_at)
            VALUES (:link_id, :event, :from_status, :to_status, :created_at)
        SQL);
        $statement->execute([
            'link_id' => $id,
            'event' => $event,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'created_at' => $now,
        ]);
    }

    private function enqueueLifecycle(string $eventType, int $linkId, string $dedupeKey, array $details = []): void
    {
        LifecycleWebhookService::enqueue($this->pdo, $this->config, $eventType, $linkId, $dedupeKey, $details);
    }

    private function rollback(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }
}
