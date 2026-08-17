<?php

declare(strict_types=1);

trait LinkAnalyticsTrait
{
    public function dashboardOverview(
        string $view,
        string $search,
        string $status = 'all',
        string $tag = '',
        bool $favoritesOnly = false
    ): array {
        return [
            'daily_stats' => $this->dailyStats($view, $search, $status, $tag, $favoritesOnly),
            'popular_links' => $this->popularLinks($view, $search, $status, $tag, $favoritesOnly),
            'status_distribution' => $view === 'trash'
                ? [] : $this->statusDistribution($search, $tag, $favoritesOnly),
            'zero_click_links' => $this->zeroClickLinks($view, $search, $status, $tag, $favoritesOnly),
        ];
    }

    public function dailyStats(
        string $view,
        string $search,
        string $status = 'all',
        string $tag = '',
        bool $favoritesOnly = false
    ): array {
        [$where, $params] = $this->adminFilter($view, $search, $status, $tag, $favoritesOnly);
        $statement = $this->pdo->prepare(<<<SQL
            SELECT s.accessed_on, SUM(s.clicks) AS clicks
            FROM link_daily_stats s
            INNER JOIN links l ON l.id = s.link_id
            WHERE s.accessed_on >= :since AND {$where}
            GROUP BY s.accessed_on
            ORDER BY s.accessed_on DESC
        SQL);
        $statement->execute(array_merge(['since' => gmdate('Y-m-d', strtotime('-13 days'))], $params));
        $byDate = [];
        foreach ($statement->fetchAll() as $stat) {
            $byDate[(string)$stat['accessed_on']] = (int)$stat['clicks'];
        }
        $stats = [];
        for ($offset = 13; $offset >= 0; $offset--) {
            $date = gmdate('Y-m-d', strtotime("-{$offset} days"));
            $stats[] = ['accessed_on' => $date, 'clicks' => $byDate[$date] ?? 0];
        }
        return $stats;
    }

    public function popularLinks(
        string $view,
        string $search,
        string $status = 'all',
        string $tag = '',
        bool $favoritesOnly = false,
        int $limit = 5
    ): array {
        [$where, $params] = $this->adminFilter($view, $search, $status, $tag, $favoritesOnly);
        $statement = $this->pdo->prepare(<<<SQL
            SELECT l.id, l.slug, l.title, l.clicks,
                   SUM(s.clicks) AS recent_clicks
            FROM links l
            INNER JOIN link_daily_stats s ON s.link_id = l.id AND s.accessed_on >= :since
            WHERE {$where}
            GROUP BY l.id
            HAVING SUM(s.clicks) > 0
            ORDER BY recent_clicks DESC, l.clicks DESC, l.id DESC
            LIMIT :item_limit
        SQL);
        $statement->bindValue(':since', gmdate('Y-m-d', strtotime('-13 days')));
        foreach ($params as $name => $value) {
            $statement->bindValue(':' . $name, $value);
        }
        $statement->bindValue(':item_limit', max(1, min(20, $limit)), PDO::PARAM_INT);
        $statement->execute();
        return $statement->fetchAll();
    }

    public function statusDistribution(string $search = '', string $tag = '', bool $favoritesOnly = false): array
    {
        [$where, $params] = $this->adminFilter('active', $search, 'all', $tag, $favoritesOnly);
        $statement = $this->pdo->prepare(<<<SQL
            SELECT status_key, COUNT(*) AS link_count
            FROM (
                SELECT CASE
                    WHEN l.is_active <> 1 THEN 'inactive'
                    WHEN l.short_domain_id IS NOT NULL AND NOT EXISTS (
                        SELECT 1 FROM short_domains status_domain
                        WHERE status_domain.id = l.short_domain_id
                          AND status_domain.verified_at IS NOT NULL
                          AND status_domain.is_enabled = 1
                    ) THEN 'inactive'
                    WHEN l.starts_at IS NOT NULL AND l.starts_at > :distribution_now THEN 'scheduled'
                    WHEN l.expires_at IS NOT NULL AND l.expires_at <= :distribution_now THEN 'expired'
                    WHEN (l.max_clicks IS NOT NULL AND l.clicks >= l.max_clicks)
                      OR (l.is_one_time = 1 AND l.clicks >= 1) THEN 'exhausted'
                    ELSE 'active'
                END AS status_key
                FROM links l
                WHERE {$where}
            ) grouped_links
            GROUP BY status_key
        SQL);
        $statement->execute(array_merge($params, ['distribution_now' => utc_timestamp()]));
        $counts = ['active' => 0, 'scheduled' => 0, 'inactive' => 0, 'expired' => 0, 'exhausted' => 0];
        while ($row = $statement->fetch()) {
            $key = (string)$row['status_key'];
            if (isset($counts[$key])) {
                $counts[$key] = (int)$row['link_count'];
            }
        }
        return $counts;
    }

    public function zeroClickLinks(
        string $view,
        string $search,
        string $status = 'all',
        string $tag = '',
        bool $favoritesOnly = false,
        int $limit = 5
    ): array {
        [$where, $params] = $this->adminFilter($view, $search, $status, $tag, $favoritesOnly);
        $statement = $this->pdo->prepare(<<<SQL
            SELECT l.id, l.slug, l.title, l.created_at
            FROM links l
            WHERE {$where} AND l.clicks = 0
            ORDER BY l.created_at ASC, l.id ASC
            LIMIT :item_limit
        SQL);
        foreach ($params as $name => $value) {
            $statement->bindValue(':' . $name, $value);
        }
        $statement->bindValue(':item_limit', max(1, min(20, $limit)), PDO::PARAM_INT);
        $statement->execute();
        return $statement->fetchAll();
    }

    public function trend(int $id, int $days): array
    {
        $days = in_array($days, [7, 14, 30], true) ? $days : 14;
        $since = gmdate('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
        $statement = $this->pdo->prepare(<<<'SQL'
            SELECT accessed_on, clicks FROM link_daily_stats
            WHERE link_id = :link_id AND accessed_on >= :since
            ORDER BY accessed_on ASC
        SQL);
        $statement->execute(['link_id' => $id, 'since' => $since]);
        $byDate = [];
        foreach ($statement->fetchAll() as $stat) {
            $byDate[(string)$stat['accessed_on']] = (int)$stat['clicks'];
        }
        $trend = [];
        for ($offset = $days - 1; $offset >= 0; $offset--) {
            $date = gmdate('Y-m-d', strtotime("-{$offset} days"));
            $trend[] = ['accessed_on' => $date, 'clicks' => $byDate[$date] ?? 0];
        }
        return $trend;
    }

    public function statusHistory(int $id): array
    {
        $statement = $this->pdo->prepare(<<<'SQL'
            SELECT event, from_status, to_status, created_at
            FROM link_status_history WHERE link_id = :link_id
            ORDER BY created_at DESC, id DESC LIMIT 100
        SQL);
        $statement->execute(['link_id' => $id]);
        return $statement->fetchAll();
    }
}
