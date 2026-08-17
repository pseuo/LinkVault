<?php

declare(strict_types=1);

trait LinkOperationsTrait
{
    public function linkPresets(): array
    {
        $rows = $this->pdo->query('SELECT * FROM link_presets ORDER BY name COLLATE NOCASE ASC')->fetchAll();
        foreach ($rows as &$row) {
            try {
                $values = json_decode((string)$row['values_json'], true, 32, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                $values = [];
            }
            $row['values'] = is_array($values) ? $values : [];
        }
        unset($row);
        return $rows;
    }

    public function saveLinkPreset(string $name, array $values): int
    {
        $now = utc_timestamp();
        $statement = $this->pdo->prepare(<<<'SQL'
            INSERT INTO link_presets (name, values_json, created_at, updated_at)
            VALUES (:name, :values_json, :created_at, :updated_at)
            ON CONFLICT(name) DO UPDATE SET values_json = excluded.values_json, updated_at = excluded.updated_at
        SQL);
        $statement->execute([
            'name' => $name,
            'values_json' => json_encode($values, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $lookup = $this->pdo->prepare('SELECT id FROM link_presets WHERE name = :name COLLATE NOCASE');
        $lookup->execute(['name' => $name]);
        return (int)$lookup->fetchColumn();
    }

    public function deleteLinkPreset(int $id): bool
    {
        $statement = $this->pdo->prepare('DELETE FROM link_presets WHERE id = :id');
        $statement->execute(['id' => $id]);
        return $statement->rowCount() === 1;
    }

    public function repairTargetHealth(
        int $linkId,
        string $action,
        string $expectedUpdatedAt,
        string $expectedTargetHash,
        ?string $url = null,
        string $ignoreReason = ''
    ): bool {
        return (bool)with_sqlite_retry(function () use (
            $linkId, $action, $expectedUpdatedAt, $expectedTargetHash, $url, $ignoreReason
        ): bool {
            $this->pdo->exec('BEGIN IMMEDIATE');
            try {
                $query = $this->pdo->prepare(<<<'SQL'
                    SELECT l.*, h.target_url_hash
                    FROM links l INNER JOIN target_health h ON h.link_id = l.id
                    WHERE l.id = :id AND l.deleted_at IS NULL
                SQL);
                $query->execute(['id' => $linkId]);
                $link = $query->fetch();
                if (!$link || !hash_equals((string)$link['updated_at'], $expectedUpdatedAt)
                    || !hash_equals((string)$link['target_url_hash'], $expectedTargetHash)) {
                    $this->pdo->rollBack();
                    return false;
                }
                $now = utc_timestamp();
                if ($action === 'ignore') {
                    $statement = $this->pdo->prepare(<<<'SQL'
                        UPDATE target_health SET ignored_at = :ignored_at, ignored_reason = :ignored_reason
                        WHERE link_id = :link_id AND target_url_hash = :target_url_hash
                    SQL);
                    $statement->execute([
                        'ignored_at' => $now,
                        'ignored_reason' => limit_text($ignoreReason, 200),
                        'link_id' => $linkId,
                        'target_url_hash' => $expectedTargetHash,
                    ]);
                } elseif ($action === 'disable') {
                    $statement = $this->pdo->prepare(<<<'SQL'
                        UPDATE links SET is_active = 0, updated_at = :updated_at
                        WHERE id = :id AND updated_at = :expected_updated_at
                    SQL);
                    $statement->execute(['updated_at' => $now, 'id' => $linkId, 'expected_updated_at' => $expectedUpdatedAt]);
                    if ($statement->rowCount() === 1) {
                        $this->addHistory($linkId, 'disabled', link_status_key($link), 'inactive', $now);
                        $this->enqueueLifecycle('link.disabled', $linkId, 'link.disabled:' . $linkId . ':' . $now);
                    }
                } elseif (in_array($action, ['target', 'fallback'], true) && is_string($url)) {
                    if ($action === 'target') {
                        $campaign = [
                            'campaign_name' => (string)($link['campaign_name'] ?? ''),
                            'campaign_source' => (string)($link['campaign_source'] ?? ''),
                            'campaign_medium' => (string)($link['campaign_medium'] ?? ''),
                            'campaign_content' => (string)($link['campaign_content'] ?? ''),
                        ];
                        $url = apply_campaign_parameters($url, $campaign, (bool)array_filter($campaign));
                        if (!valid_target_url($url, $this->maxUrlLength)) {
                            throw new InvalidArgumentException('Repaired target URL is invalid or too long.');
                        }
                    }
                    $column = $action === 'target' ? 'target_url' : 'fallback_url';
                    $statement = $this->pdo->prepare(
                        "UPDATE links SET {$column} = :url, updated_at = :updated_at WHERE id = :id AND updated_at = :expected_updated_at"
                    );
                    $statement->execute([
                        'url' => $url,
                        'updated_at' => $now,
                        'id' => $linkId,
                        'expected_updated_at' => $expectedUpdatedAt,
                    ]);
                    if ($action === 'target' && $statement->rowCount() === 1) {
                        $clear = $this->pdo->prepare(
                            "UPDATE target_health SET ignored_at = NULL, ignored_reason = '', next_check_at = :next_check_at WHERE link_id = :link_id"
                        );
                        $clear->execute(['next_check_at' => $now, 'link_id' => $linkId]);
                    }
                } else {
                    throw new InvalidArgumentException('Invalid repair action.');
                }
                $changed = $statement->rowCount() === 1;
                $this->pdo->commit();
                return $changed;
            } catch (Throwable $exception) {
                $this->rollback();
                throw $exception;
            }
        });
    }

    public function maintenanceCounts(
        int $expiringDays = 7,
        int $staleDays = 90,
        int $quotaPercent = 80,
        ?string $evaluatedAt = null
    ): array {
        $evaluatedAt ??= utc_timestamp();
        $counts = [];
        foreach (['expiring', 'stale_zero', 'quota', 'invalid', 'target_health'] as $category) {
            [$clause, $params] = $this->maintenanceClause(
                $category,
                $expiringDays,
                $staleDays,
                $quotaPercent,
                $evaluatedAt
            );
            $statement = $this->pdo->prepare("SELECT COUNT(*) FROM links l WHERE l.deleted_at IS NULL AND ({$clause})");
            $statement->execute($params);
            $counts[$category] = (int)$statement->fetchColumn();
        }
        return $counts;
    }

    public function maintenanceSummary(
        int $expiringDays = 7,
        int $staleDays = 90,
        int $quotaPercent = 80,
        int $itemLimit = 20,
        ?string $evaluatedAt = null
    ): array {
        $evaluatedAt ??= utc_timestamp();
        $summary = [];
        foreach (['expiring', 'quota', 'stale_zero'] as $category) {
            [$clause, $params] = $this->maintenanceClause(
                $category,
                $expiringDays,
                $staleDays,
                $quotaPercent,
                $evaluatedAt
            );
            $count = $this->pdo->prepare("SELECT COUNT(*) FROM links l WHERE l.deleted_at IS NULL AND ({$clause})");
            $count->execute($params);
            $items = $this->pdo->prepare(<<<SQL
                SELECT l.id, l.slug, l.title, l.clicks, l.max_clicks, l.expires_at, l.created_at
                FROM links l
                WHERE l.deleted_at IS NULL AND ({$clause})
                ORDER BY l.id ASC
                LIMIT :item_limit
            SQL);
            foreach ($params as $name => $value) {
                $items->bindValue(':' . $name, $value);
            }
            $items->bindValue(':item_limit', max(1, min(100, $itemLimit)), PDO::PARAM_INT);
            $items->execute();
            $summary[$category] = [
                'count' => (int)$count->fetchColumn(),
                'links' => $items->fetchAll(),
            ];
        }
        return $summary;
    }

    public function listForMaintenance(
        string $category,
        string $search,
        int $page,
        int $pageSize,
        string $sort = 'created_desc',
        string $tag = '',
        int $expiringDays = 7,
        int $staleDays = 90,
        int $quotaPercent = 80,
        ?string $evaluatedAt = null
    ): array {
        [$where, $params] = $this->adminFilter('active', $search, 'all', $tag, false);
        [$maintenanceClause, $maintenanceParams] = $this->maintenanceClause(
            $category,
            $expiringDays,
            $staleDays,
            $quotaPercent,
            $evaluatedAt
        );
        $where .= ' AND (' . $maintenanceClause . ')';
        $params = array_merge($params, $maintenanceParams);
        $count = $this->pdo->prepare("SELECT COUNT(*) FROM links l WHERE {$where}");
        $count->execute($params);
        $total = (int)$count->fetchColumn();
        $maxPage = max(1, (int)ceil($total / $pageSize));
        $page = min(max(1, $page), $maxPage);
        $statement = $this->pdo->prepare(<<<SQL
            SELECT l.*,
                   COALESCE((
                       SELECT GROUP_CONCAT(tag, X'1F')
                       FROM (SELECT tag FROM link_tags WHERE link_id = l.id ORDER BY tag COLLATE NOCASE)
                   ), '') AS tags,
                   (SELECT state FROM target_health WHERE link_id = l.id) AS target_health_state,
                   (SELECT reason FROM target_health WHERE link_id = l.id) AS target_health_reason,
                   (SELECT checked_at FROM target_health WHERE link_id = l.id) AS target_health_checked_at,
                   (SELECT effective_url FROM target_health WHERE link_id = l.id) AS target_health_effective_url,
                   (SELECT redirect_state FROM target_health WHERE link_id = l.id) AS target_health_redirect_state,
                   (SELECT redirect_chain_json FROM target_health WHERE link_id = l.id) AS target_health_redirect_chain_json,
                   (SELECT http_status FROM target_health WHERE link_id = l.id) AS target_health_http_status,
                   (SELECT latency_ms FROM target_health WHERE link_id = l.id) AS target_health_latency_ms,
                    (SELECT consecutive_failures FROM target_health WHERE link_id = l.id) AS target_health_consecutive_failures,
                    (SELECT target_url_hash FROM target_health WHERE link_id = l.id) AS target_health_target_url_hash
            FROM links l
            WHERE {$where}
            ORDER BY {$this->adminOrder($sort)}
            LIMIT :page_size OFFSET :offset
        SQL);
        foreach ($params as $name => $value) {
            $statement->bindValue(':' . $name, $value);
        }
        $statement->bindValue(':page_size', max(1, $pageSize), PDO::PARAM_INT);
        $statement->bindValue(':offset', ($page - 1) * $pageSize, PDO::PARAM_INT);
        $statement->execute();
        return ['links' => $statement->fetchAll(), 'total' => $total, 'page' => $page];
    }

    public function bulkMaintenance(array $ids, string $action, int $days = 0, array $tags = []): int
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn (int $id): bool => $id > 0
        )));
        if (!$ids || count($ids) > 1000 || !in_array($action, ['extend', 'add_tags', 'remove_tags'], true)) {
            return 0;
        }
        if ($action === 'extend' && ($days < 1 || $days > 3650)) {
            throw new InvalidArgumentException('Extension days must be between 1 and 3650.');
        }
        if ($action !== 'extend' && !$tags) {
            throw new InvalidArgumentException('At least one tag is required.');
        }

        return (int)with_sqlite_retry(function () use ($ids, $action, $days, $tags): int {
            $this->pdo->beginTransaction();
            try {
                $changed = 0;
                foreach ($ids as $id) {
                    $before = $this->findById($id);
                    if (!$before || !empty($before['deleted_at'])) {
                        continue;
                    }
                    $now = utc_timestamp();
                    if ($action === 'extend') {
                        if (!is_string($before['expires_at'] ?? null) || $before['expires_at'] === '') {
                            continue;
                        }
                        $base = link_is_expired($before)
                            ? new DateTimeImmutable('now', new DateTimeZone('UTC'))
                            : new DateTimeImmutable((string)$before['expires_at']);
                        $expiresAt = $base->modify('+' . $days . ' days')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
                        $statement = $this->pdo->prepare(
                            'UPDATE links SET expires_at = :expires_at, updated_at = :updated_at WHERE id = :id AND deleted_at IS NULL'
                        );
                        $statement->execute(['expires_at' => $expiresAt, 'updated_at' => $now, 'id' => $id]);
                        if ($statement->rowCount() > 0) {
                            $after = $this->findById($id);
                            $this->addHistory($id, 'expiration_extended', link_status_key($before), link_status_key($after ?: []), $now);
                            $changed++;
                        }
                        continue;
                    }

                    $currentTags = [];
                    $tagQuery = $this->pdo->prepare('SELECT tag FROM link_tags WHERE link_id = :link_id ORDER BY tag');
                    $tagQuery->execute(['link_id' => $id]);
                    foreach ($tagQuery->fetchAll() as $row) {
                        $currentTags[] = (string)$row['tag'];
                    }
                    $nextTags = $action === 'add_tags'
                        ? array_values(array_unique(array_merge($currentTags, $tags)))
                        : array_values(array_diff($currentTags, $tags));
                    if (count($nextTags) > 10) {
                        throw new InvalidArgumentException('A selected link would exceed the 10-tag limit.');
                    }
                    if ($nextTags === $currentTags) {
                        continue;
                    }
                    $this->replaceTags($id, $nextTags);
                    $updated = $this->pdo->prepare('UPDATE links SET updated_at = :updated_at WHERE id = :id');
                    $updated->execute(['updated_at' => $now, 'id' => $id]);
                    $changed++;
                }
                $this->pdo->commit();
                return $changed;
            } catch (Throwable $exception) {
                $this->rollback();
                throw $exception;
            }
        });
    }

    /** @return array<string, mixed> */
    public function previewBulkOperation(array $ids, string $action, int $days = 0, array $tags = []): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn (int $id): bool => $id > 0
        )));
        $allowed = [
            'favorite', 'unfavorite', 'enable', 'disable', 'delete', 'restore', 'purge',
            'extend', 'add_tags', 'remove_tags',
        ];
        if (!$ids || count($ids) > 1000 || !in_array($action, $allowed, true)) {
            throw new InvalidArgumentException('Invalid bulk operation.');
        }
        if ($action === 'extend' && ($days < 1 || $days > 3650)) {
            throw new InvalidArgumentException('Extension days must be between 1 and 3650.');
        }
        if (in_array($action, ['add_tags', 'remove_tags'], true) && !$tags) {
            throw new InvalidArgumentException('At least one tag is required.');
        }

        $items = [];
        $wouldChange = 0;
        $unchanged = 0;
        $ineligible = 0;
        $bulkLinks = $this->fetchBulkLinks($ids);
        foreach ($ids as $id) {
            $link = $bulkLinks[$id] ?? null;
            if (!$link) {
                $items[] = ['id' => $id, 'slug' => '', 'title' => '', 'state' => 'ineligible', 'reason' => '链接不存在', 'impact' => ''];
                $ineligible++;
                continue;
            }
            $before = $this->bulkState($link, (array)($link['_bulk_tags'] ?? []));
            $after = $before;
            $state = 'change';
            $reason = '将更新';
            $deleted = !empty($link['deleted_at']);
            if ($action === 'purge') {
                if (!$deleted) {
                    $state = 'ineligible';
                    $reason = '仅回收站链接可永久删除';
                }
            } elseif ($action === 'restore') {
                if (!$deleted) {
                    $state = 'unchanged';
                    $reason = '不在回收站';
                } else {
                    $after['deleted_at'] = null;
                }
            } elseif ($deleted) {
                $state = 'ineligible';
                $reason = '链接已在回收站';
            } elseif ($action === 'favorite' || $action === 'unfavorite') {
                $desired = $action === 'favorite' ? 1 : 0;
                if ((int)$link['is_favorite'] === $desired) {
                    $state = 'unchanged';
                    $reason = $desired === 1 ? '已经收藏' : '尚未收藏';
                } else {
                    $after['is_favorite'] = $desired;
                }
            } elseif ($action === 'enable' || $action === 'disable') {
                $desired = $action === 'enable' ? 1 : 0;
                if ($action === 'enable' && (int)($link['access_password_reset_required'] ?? 0) === 1) {
                    $state = 'ineligible';
                    $reason = '受保护链接必须先重新设置密码';
                } elseif ((int)$link['is_active'] === $desired) {
                    $state = 'unchanged';
                    $reason = $desired === 1 ? '已经启用' : '已经停用';
                } else {
                    $after['is_active'] = $desired;
                }
            } elseif ($action === 'delete') {
                $after['deleted_at'] = utc_timestamp();
            } elseif ($action === 'extend') {
                if (!is_string($link['expires_at'] ?? null) || $link['expires_at'] === '') {
                    $state = 'ineligible';
                    $reason = '没有过期时间';
                } else {
                    $base = link_is_expired($link)
                        ? new DateTimeImmutable('now', new DateTimeZone('UTC'))
                        : new DateTimeImmutable((string)$link['expires_at']);
                    $after['expires_at'] = $base->modify('+' . $days . ' days')
                        ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
                }
            } else {
                $currentTags = $before['tags'];
                $nextTags = $action === 'add_tags'
                    ? array_values(array_unique(array_merge($currentTags, $tags)))
                    : array_values(array_diff($currentTags, $tags));
                sort($nextTags, SORT_STRING);
                if (count($nextTags) > 10) {
                    $state = 'ineligible';
                    $reason = '操作后将超过 10 个标签';
                } elseif ($nextTags === $currentTags) {
                    $state = 'unchanged';
                    $reason = $action === 'add_tags' ? '标签已存在' : '没有对应标签';
                } else {
                    $after['tags'] = $nextTags;
                }
            }

            if ($state === 'change') {
                $wouldChange++;
            } elseif ($state === 'unchanged') {
                $unchanged++;
            } else {
                $ineligible++;
            }
            $items[] = [
                'id' => $id,
                'slug' => (string)$link['slug'],
                'title' => (string)$link['title'],
                'state' => $state,
                'reason' => $reason,
                'impact' => $this->bulkImpactDescription($action, $before, $after, $days, $tags, $state),
                'before' => $before,
                'after' => $after,
            ];
        }

        $operationId = bin2hex(random_bytes(16));
        $now = time();
        $parameters = ['days' => $days, 'tags' => array_values($tags)];
        $statement = $this->pdo->prepare(<<<'SQL'
            INSERT INTO bulk_operations (
                id, action, parameters_json, items_json, status, reversible,
                selected_count, eligible_count, changed_count, created_at,
                preview_expires_at, retain_until
            ) VALUES (
                :id, :action, :parameters_json, :items_json, 'previewed', :reversible,
                :selected_count, :eligible_count, 0, :created_at,
                :preview_expires_at, :retain_until
            )
        SQL);
        with_sqlite_retry(fn () => $statement->execute([
            'id' => $operationId,
            'action' => $action,
            'parameters_json' => json_encode($parameters, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'items_json' => json_encode($items, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'reversible' => $action === 'purge' ? 0 : 1,
            'selected_count' => count($ids),
            'eligible_count' => $wouldChange,
            'created_at' => $now,
            'preview_expires_at' => $now + 900,
            'retain_until' => $now + 7 * 86400,
        ]));

        return [
            'operation_id' => $operationId,
            'action' => $action,
            'action_label' => $this->bulkActionLabel($action),
            'parameters_label' => match ($action) {
                'extend' => $days . ' 天',
                'add_tags', 'remove_tags' => implode('、', $tags),
                default => '',
            },
            'selected' => count($ids),
            'selected_ids' => $ids,
            'would_change' => $wouldChange,
            'unchanged' => $unchanged,
            'ineligible' => $ineligible,
            'reversible' => $action !== 'purge',
            'expires_at' => $now + 900,
            'items' => array_map(static fn (array $item): array => array_intersect_key(
                $item,
                array_flip(['id', 'slug', 'title', 'state', 'reason', 'impact'])
            ), array_slice($items, 0, 50)),
        ];
    }

    private function bulkActionLabel(string $action): string
    {
        return match ($action) {
            'favorite' => '收藏', 'unfavorite' => '取消收藏',
            'enable' => '启用', 'disable' => '停用',
            'delete' => '移入回收站', 'restore' => '恢复', 'purge' => '永久删除',
            'extend' => '延期', 'add_tags' => '添加标签', 'remove_tags' => '移除标签',
            default => $action,
        };
    }

    private function bulkImpactDescription(
        string $action,
        array $before,
        array $after,
        int $days,
        array $tags,
        string $state
    ): string {
        if ($state !== 'change') {
            return '';
        }
        return match ($action) {
            'favorite' => '收藏：否 -> 是',
            'unfavorite' => '收藏：是 -> 否',
            'enable' => '状态：停用 -> 启用',
            'disable' => '状态：启用 -> 停用',
            'delete' => '移入回收站，可在 24 小时内撤销',
            'restore' => '从回收站恢复',
            'purge' => '永久删除链接及其关联数据',
            'extend' => '过期时间：' . (string)($before['expires_at'] ?? '-') . ' -> '
                . (string)($after['expires_at'] ?? '-') . '（+' . $days . ' 天）',
            'add_tags', 'remove_tags' => '标签：'
                . ($before['tags'] === [] ? '无' : implode('、', (array)$before['tags']))
                . ' -> ' . ($after['tags'] === [] ? '无' : implode('、', (array)$after['tags']))
                . '（' . implode('、', $tags) . '）',
            default => '',
        };
    }

    /** @return array<string, mixed> */
    public function applyBulkOperation(string $operationId, bool $confirmPurge = false): array
    {
        return with_sqlite_retry(function () use ($operationId, $confirmPurge): array {
            if (preg_match('/^[a-f0-9]{32}$/D', $operationId) !== 1) {
                throw new InvalidArgumentException('Invalid bulk operation identifier.');
            }
            $this->pdo->exec('BEGIN IMMEDIATE');
            try {
                $operation = $this->bulkOperationRow($operationId);
                if (!$operation) {
                    throw new InvalidArgumentException('Bulk operation not found.');
                }
                if ($operation['status'] === 'applied') {
                    $this->pdo->exec('COMMIT');
                    $result = $this->decodeBulkJson((string)$operation['result_json']);
                    return array_merge($result, ['replayed' => true]);
                }
                if ($operation['status'] !== 'previewed') {
                    throw new InvalidArgumentException('Bulk operation is no longer applicable.');
                }
                if ($operation['action'] === 'purge' && !$confirmPurge) {
                    throw new InvalidArgumentException('Purge confirmation is required.');
                }
                if ((int)$operation['preview_expires_at'] < time()) {
                    $this->updateBulkStatus($operationId, 'expired', ['reason' => 'preview_expired']);
                    $this->pdo->exec('COMMIT');
                    return ['status' => 'expired', 'changed' => 0, 'reversible' => false];
                }
                $items = $this->decodeBulkJson((string)$operation['items_json']);
                $currentLinks = $this->fetchBulkLinks(array_map(
                    static fn (array $item): int => (int)$item['id'],
                    $items
                ));
                foreach ($items as $item) {
                    $current = $currentLinks[(int)$item['id']] ?? null;
                    $before = $item['before'] ?? null;
                    if (!is_array($before)) {
                        if ($current !== null) {
                            $this->updateBulkStatus($operationId, 'conflicted', [
                                'reason' => 'selection_changed', 'link_id' => (int)$item['id'],
                            ]);
                            $this->pdo->exec('COMMIT');
                            return ['status' => 'conflicted', 'changed' => 0, 'reversible' => false];
                        }
                        continue;
                    }
                    if (!$current || $this->bulkState($current, (array)($current['_bulk_tags'] ?? [])) !== $before) {
                        $this->updateBulkStatus($operationId, 'conflicted', [
                            'reason' => 'selection_changed', 'link_id' => (int)$item['id'],
                        ]);
                        $this->pdo->exec('COMMIT');
                        return ['status' => 'conflicted', 'changed' => 0, 'reversible' => false];
                    }
                }

                $changed = 0;
                $changedIds = [];
                $nowText = utc_timestamp();
                foreach ($items as &$item) {
                    if (($item['state'] ?? null) !== 'change') {
                        continue;
                    }
                    $this->applyBulkState(
                        (int)$item['id'],
                        (string)$operation['action'],
                        (array)$item['after'],
                        $nowText,
                        $currentLinks[(int)$item['id']] ?? null
                    );
                    $item['applied_updated_at'] = (string)$operation['action'] === 'purge' ? 'purged' : $nowText;
                    $changed++;
                    $changedIds[] = (int)$item['id'];
                }
                unset($item);
                $appliedAt = time();
                $result = [
                    'status' => 'applied',
                    'action' => (string)$operation['action'],
                    'changed' => $changed,
                    'link_ids' => $changedIds,
                    'reversible' => (int)$operation['reversible'] === 1 && $changed > 0,
                    'undo_expires_at' => $appliedAt + 86400,
                ];
                $update = $this->pdo->prepare(<<<'SQL'
                    UPDATE bulk_operations
                    SET status = 'applied', items_json = :items_json, changed_count = :changed_count,
                        result_json = :result_json, applied_at = :applied_at,
                        undo_expires_at = :undo_expires_at
                    WHERE id = :id
                SQL);
                $update->execute([
                    'items_json' => json_encode($items, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'changed_count' => $changed,
                    'result_json' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'applied_at' => $appliedAt,
                    'undo_expires_at' => $appliedAt + 86400,
                    'id' => $operationId,
                ]);
                $this->pdo->exec('COMMIT');
                return $result;
            } catch (Throwable $exception) {
                $this->rollback();
                throw $exception;
            }
        });
    }

    /** @return array<string, mixed> */
    public function undoBulkOperation(string $operationId): array
    {
        return with_sqlite_retry(function () use ($operationId): array {
            if (preg_match('/^[a-f0-9]{32}$/D', $operationId) !== 1) {
                throw new InvalidArgumentException('Invalid bulk operation identifier.');
            }
            $this->pdo->exec('BEGIN IMMEDIATE');
            try {
                $operation = $this->bulkOperationRow($operationId);
                if (!$operation || $operation['status'] !== 'applied' || (int)$operation['reversible'] !== 1) {
                    throw new InvalidArgumentException('Bulk operation cannot be undone.');
                }
                if ((int)$operation['undo_expires_at'] < time()) {
                    throw new InvalidArgumentException('Bulk undo window has expired.');
                }
                $items = $this->decodeBulkJson((string)$operation['items_json']);
                foreach ($items as $item) {
                    if (($item['state'] ?? null) !== 'change') {
                        continue;
                    }
                    $current = $this->findById((int)$item['id']);
                    $expected = (string)($item['applied_updated_at'] ?? '');
                    if (!$current || $expected === '' || !hash_equals($expected, (string)$current['updated_at'])) {
                        $this->updateBulkStatus($operationId, 'conflicted', [
                            'reason' => 'undo_link_changed', 'link_id' => (int)$item['id'],
                        ]);
                        $this->pdo->exec('COMMIT');
                        return ['status' => 'conflicted', 'changed' => 0];
                    }
                }

                $changed = 0;
                $changedIds = [];
                $nowText = utc_timestamp();
                foreach ($items as $item) {
                    if (($item['state'] ?? null) !== 'change') {
                        continue;
                    }
                    $this->restoreBulkState((int)$item['id'], (string)$operation['action'], (array)$item['before'], $nowText);
                    $changed++;
                    $changedIds[] = (int)$item['id'];
                }
                $result = ['status' => 'undone', 'changed' => $changed, 'link_ids' => $changedIds];
                $update = $this->pdo->prepare(<<<'SQL'
                    UPDATE bulk_operations
                    SET status = 'undone', result_json = :result_json, undone_at = :undone_at
                    WHERE id = :id
                SQL);
                $update->execute([
                    'result_json' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'undone_at' => time(),
                    'id' => $operationId,
                ]);
                $this->pdo->exec('COMMIT');
                return $result;
            } catch (Throwable $exception) {
                $this->rollback();
                throw $exception;
            }
        });
    }

    /** @return list<array{id: string, action: string, action_label: string, changed: int, applied_at: int, undo_expires_at: int}> */
    public function undoableBulkOperations(int $limit = 5): array
    {
        $statement = $this->pdo->prepare(<<<'SQL'
            SELECT id, action, changed_count, applied_at, undo_expires_at
            FROM bulk_operations
            WHERE status = 'applied' AND reversible = 1 AND changed_count > 0
              AND undo_expires_at >= :now
            ORDER BY applied_at DESC
            LIMIT :limit
        SQL);
        $statement->bindValue(':now', time(), PDO::PARAM_INT);
        $statement->bindValue(':limit', max(1, min(20, $limit)), PDO::PARAM_INT);
        $statement->execute();
        return array_map(fn (array $row): array => [
            'id' => (string)$row['id'],
            'action' => (string)$row['action'],
            'action_label' => $this->bulkActionLabel((string)$row['action']),
            'changed' => (int)$row['changed_count'],
            'applied_at' => (int)$row['applied_at'],
            'undo_expires_at' => (int)$row['undo_expires_at'],
        ], $statement->fetchAll());
    }

    /** @return array<string, mixed> */
    private function bulkState(array $link, ?array $tags = null): array
    {
        return [
            'updated_at' => (string)$link['updated_at'],
            'deleted_at' => $link['deleted_at'] === null ? null : (string)$link['deleted_at'],
            'is_active' => (int)$link['is_active'],
            'access_password_reset_required' => (int)($link['access_password_reset_required'] ?? 0),
            'is_favorite' => (int)$link['is_favorite'],
            'expires_at' => $link['expires_at'] === null ? null : (string)$link['expires_at'],
            'tags' => $tags ?? $this->bulkTags((int)$link['id']),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function fetchBulkLinks(array $ids): array
    {
        $links = [];
        foreach (array_chunk(array_values(array_unique(array_map('intval', $ids))), 400) as $chunk) {
            if (!$chunk) {
                continue;
            }
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $statement = $this->pdo->prepare("SELECT * FROM links WHERE id IN ({$placeholders})");
            $statement->execute($chunk);
            foreach ($statement->fetchAll() as $link) {
                $link['_bulk_tags'] = [];
                $links[(int)$link['id']] = $link;
            }
            $tags = $this->pdo->prepare(
                "SELECT link_id, tag FROM link_tags WHERE link_id IN ({$placeholders}) ORDER BY link_id, tag"
            );
            $tags->execute($chunk);
            foreach ($tags->fetchAll() as $tag) {
                $linkId = (int)$tag['link_id'];
                if (isset($links[$linkId])) {
                    $links[$linkId]['_bulk_tags'][] = (string)$tag['tag'];
                }
            }
        }
        return $links;
    }

    /** @return list<string> */
    private function bulkTags(int $linkId): array
    {
        $statement = $this->pdo->prepare('SELECT tag FROM link_tags WHERE link_id = :link_id ORDER BY tag');
        $statement->execute(['link_id' => $linkId]);
        return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    private function bulkOperationRow(string $operationId): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM bulk_operations WHERE id = :id');
        $statement->execute(['id' => $operationId]);
        $row = $statement->fetch();
        return $row ?: null;
    }

    private function decodeBulkJson(string $json): array
    {
        try {
            $value = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw new RuntimeException('Stored bulk operation is invalid.', 0, $exception);
        }
        if (!is_array($value)) {
            throw new RuntimeException('Stored bulk operation is invalid.');
        }
        return $value;
    }

    private function updateBulkStatus(string $operationId, string $status, array $result): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE bulk_operations SET status = :status, result_json = :result_json WHERE id = :id'
        );
        $statement->execute([
            'status' => $status,
            'result_json' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'id' => $operationId,
        ]);
    }

    private function applyBulkState(int $id, string $action, array $state, string $now, ?array $before = null): void
    {
        $before ??= $this->findById($id);
        if (!$before) {
            throw new RuntimeException('Previewed link disappeared.');
        }
        if ($action === 'purge') {
            $statement = $this->pdo->prepare('DELETE FROM links WHERE id = :id AND deleted_at IS NOT NULL');
            $statement->execute(['id' => $id]);
            if ($statement->rowCount() !== 1) {
                throw new RuntimeException('Previewed link cannot be purged.');
            }
            return;
        }
        if (in_array($action, ['add_tags', 'remove_tags'], true)) {
            $this->replaceTags($id, (array)$state['tags']);
            $statement = $this->pdo->prepare('UPDATE links SET updated_at = :updated_at WHERE id = :id');
            $statement->execute(['updated_at' => $now, 'id' => $id]);
        } else {
            [$column, $value] = match ($action) {
                'favorite', 'unfavorite' => ['is_favorite', (int)$state['is_favorite']],
                'enable', 'disable' => ['is_active', (int)$state['is_active']],
                'delete', 'restore' => ['deleted_at', $state['deleted_at']],
                'extend' => ['expires_at', $state['expires_at']],
                default => throw new LogicException('Unsupported bulk operation state.'),
            };
            $statement = $this->pdo->prepare(
                'UPDATE links SET ' . $column . ' = :value, updated_at = :updated_at WHERE id = :id'
            );
            $statement->execute(['value' => $value, 'updated_at' => $now, 'id' => $id]);
        }
        $event = match ($action) {
            'enable' => 'enabled', 'disable' => 'disabled', 'delete' => 'deleted',
            'restore' => 'restored', 'extend' => 'expiration_extended', default => null,
        };
        if ($event !== null) {
            $after = array_merge($before, match ($action) {
                'enable', 'disable' => ['is_active' => (int)$state['is_active']],
                'delete', 'restore' => ['deleted_at' => $state['deleted_at']],
                'extend' => ['expires_at' => $state['expires_at']],
                default => [],
            });
            $this->recordStatusChange($id, $event, link_status_key($before), link_status_key($after), $now);
            if ($action === 'disable' && (int)$before['is_active'] === 1) {
                $this->enqueueLifecycle('link.disabled', $id, 'link.disabled:' . $id . ':' . $now);
            }
        }
    }

    private function restoreBulkState(int $id, string $action, array $state, string $now): void
    {
        $before = $this->findById($id);
        if (!$before || $action === 'purge') {
            throw new RuntimeException('Bulk operation cannot be restored.');
        }
        if (in_array($action, ['add_tags', 'remove_tags'], true)) {
            $this->replaceTags($id, (array)$state['tags']);
            $statement = $this->pdo->prepare('UPDATE links SET updated_at = :updated_at WHERE id = :id');
            $statement->execute(['updated_at' => $now, 'id' => $id]);
        } else {
            [$column, $value] = match ($action) {
                'favorite', 'unfavorite' => ['is_favorite', (int)$state['is_favorite']],
                'enable', 'disable' => ['is_active', (int)$state['is_active']],
                'delete', 'restore' => ['deleted_at', $state['deleted_at']],
                'extend' => ['expires_at', $state['expires_at']],
                default => throw new LogicException('Unsupported bulk undo state.'),
            };
            $statement = $this->pdo->prepare(
                'UPDATE links SET ' . $column . ' = :value, updated_at = :updated_at WHERE id = :id'
            );
            $statement->execute(['value' => $value, 'updated_at' => $now, 'id' => $id]);
        }
        $after = $this->findById($id);
        $this->recordStatusChange(
            $id,
            'bulk_undo_' . $action,
            link_status_key($before),
            link_status_key($after ?: []),
            $now
        );
    }

    public function listAuditEvents(int $page, int $pageSize, string $action = 'all'): array
    {
        $where = $action === 'all' ? '1 = 1' : 'action = :action';
        $params = $action === 'all' ? [] : ['action' => $action];
        $count = $this->pdo->prepare("SELECT COUNT(*) FROM audit_events WHERE {$where}");
        $count->execute($params);
        $total = (int)$count->fetchColumn();
        $page = min(max(1, $page), max(1, (int)ceil($total / $pageSize)));
        $statement = $this->pdo->prepare(<<<SQL
            SELECT id, created_at, actor_type, action, outcome, entity_type,
                   entity_id, request_id, details_json
            FROM audit_events
            WHERE {$where}
            ORDER BY created_at DESC, id DESC
            LIMIT :page_size OFFSET :offset
        SQL);
        foreach ($params as $name => $value) {
            $statement->bindValue(':' . $name, $value);
        }
        $statement->bindValue(':page_size', max(1, $pageSize), PDO::PARAM_INT);
        $statement->bindValue(':offset', ($page - 1) * $pageSize, PDO::PARAM_INT);
        $statement->execute();
        return ['events' => $statement->fetchAll(), 'total' => $total, 'page' => $page];
    }

    public function auditActions(): array
    {
        return $this->pdo->query('SELECT DISTINCT action FROM audit_events ORDER BY action ASC')->fetchAll(PDO::FETCH_COLUMN);
    }

    public function enforceDailyStatsRetention(
        int $retentionDays,
        string $mode,
        int $archiveRetentionDays = 1095,
        int $batchSize = 500
    ): array
    {
        if ($retentionDays < 1 || $archiveRetentionDays < $retentionDays
            || $archiveRetentionDays > 36500 || $batchSize < 1 || $batchSize > 5000
            || !in_array($mode, ['archive', 'delete'], true)) {
            throw new InvalidArgumentException('Invalid daily statistics retention policy.');
        }
        $cutoff = gmdate('Y-m-d', strtotime('-' . $retentionDays . ' days'));
        $processed = 0;
        $archived = 0;
        $deleted = 0;
        do {
            $batchDeleted = with_sqlite_retry(function () use ($cutoff, $mode, $batchSize): int {
                $this->pdo->exec('BEGIN IMMEDIATE');
                try {
                    if ($mode === 'archive') {
                        $archive = $this->pdo->prepare(<<<'SQL'
                            INSERT INTO link_daily_stats_archive (
                                link_id, slug, title, accessed_on, clicks, archived_at
                            )
                            SELECT s.link_id, l.slug, l.title, s.accessed_on, s.clicks, :archived_at
                            FROM link_daily_stats s
                            INNER JOIN links l ON l.id = s.link_id
                            WHERE (s.link_id, s.accessed_on) IN (
                                SELECT link_id, accessed_on FROM link_daily_stats
                                WHERE accessed_on < :cutoff
                                ORDER BY accessed_on ASC, link_id ASC LIMIT :batch_size
                            )
                            ON CONFLICT(link_id, accessed_on) DO UPDATE SET
                                slug = excluded.slug, title = excluded.title, clicks = excluded.clicks,
                                archived_at = excluded.archived_at
                        SQL);
                        $archive->bindValue(':archived_at', utc_timestamp());
                        $archive->bindValue(':cutoff', $cutoff);
                        $archive->bindValue(':batch_size', $batchSize, PDO::PARAM_INT);
                        $archive->execute();
                    }
                    $delete = $this->pdo->prepare(<<<'SQL'
                        DELETE FROM link_daily_stats
                        WHERE (link_id, accessed_on) IN (
                            SELECT link_id, accessed_on FROM link_daily_stats
                            WHERE accessed_on < :cutoff
                            ORDER BY accessed_on ASC, link_id ASC LIMIT :batch_size
                        )
                    SQL);
                    $delete->bindValue(':cutoff', $cutoff);
                    $delete->bindValue(':batch_size', $batchSize, PDO::PARAM_INT);
                    $delete->execute();
                    $count = $delete->rowCount();
                    $this->pdo->commit();
                    return $count;
                } catch (Throwable $exception) {
                    $this->rollback();
                    throw $exception;
                }
            });
            $processed += $batchDeleted;
            $deleted += $batchDeleted;
            $archived += $mode === 'archive' ? $batchDeleted : 0;
        } while ($batchDeleted === $batchSize);

        $archiveCutoff = gmdate('Y-m-d', strtotime('-' . $archiveRetentionDays . ' days'));
        $archiveDelete = $this->pdo->prepare(<<<'SQL'
            DELETE FROM link_daily_stats_archive
            WHERE (link_id, accessed_on) IN (
                SELECT link_id, accessed_on FROM link_daily_stats_archive
                WHERE accessed_on < :cutoff
                ORDER BY accessed_on ASC, link_id ASC LIMIT :batch_size
            )
        SQL);
        $archiveDeleted = 0;
        do {
            $archiveDelete->bindValue(':cutoff', $archiveCutoff);
            $archiveDelete->bindValue(':batch_size', $batchSize, PDO::PARAM_INT);
            with_sqlite_retry(fn () => $archiveDelete->execute());
            $batchDeleted = $archiveDelete->rowCount();
            $archiveDeleted += $batchDeleted;
        } while ($batchDeleted === $batchSize);

        return [
            'processed' => $processed,
            'archived' => $archived,
            'deleted' => $deleted,
            'cutoff' => $cutoff,
            'archive_deleted' => $archiveDeleted,
            'archive_cutoff' => $archiveCutoff,
        ];
    }

    /** @return array<string, int> */

    public function cleanupOperationalData(
        int $idempotencyRetentionSeconds,
        int $auditRetentionDays,
        int $webhookRetentionDays = 180,
        int $webhookAttemptRetentionDays = 90,
        int $batchSize = 500
    ): array
    {
        if ($idempotencyRetentionSeconds < 60 || $idempotencyRetentionSeconds > 30 * 86400
            || $auditRetentionDays < 1 || $auditRetentionDays > 3650
            || $webhookRetentionDays < 1 || $webhookRetentionDays > 3650
            || $webhookAttemptRetentionDays < 1 || $webhookAttemptRetentionDays > 3650
            || $batchSize < 1 || $batchSize > 5000) {
            throw new InvalidArgumentException('Invalid operational data retention policy.');
        }
        $now = time();
        $webhookCutoff = gmdate('Y-m-d\TH:i:s\Z', $now - $webhookRetentionDays * 86400);
        $result = [
            'idempotency_requests' => $this->deleteRowsInBatches(
                'idempotency_requests', 'expires_at <= :now', ['now' => $now], $batchSize,
                '(operation, key_hash)', 'operation, key_hash'
            ),
            'create_requests' => $this->deleteRowsInBatches(
                'create_requests', 'created_at < :cutoff',
                ['cutoff' => gmdate('Y-m-d\TH:i:s\Z', $now - $idempotencyRetentionSeconds)], $batchSize
            ),
            'audit_events' => $this->deleteRowsInBatches(
                'audit_events', 'created_at < :cutoff',
                ['cutoff' => gmdate('Y-m-d\TH:i:s\Z', $now - $auditRetentionDays * 86400)], $batchSize
            ),
            'bulk_operations' => $this->deleteRowsInBatches(
                'bulk_operations', 'retain_until <= :now', ['now' => $now], $batchSize
            ),
            'webhook_delivery_attempts' => $this->deleteRowsInBatches(
                'webhook_delivery_attempts', 'attempted_at < :cutoff',
                ['cutoff' => gmdate('Y-m-d\TH:i:s\Z', $now - $webhookAttemptRetentionDays * 86400)], $batchSize
            ),
            'webhook_outbox' => $this->deleteRowsInBatches(
                'webhook_outbox',
                "(status = 'delivered' AND delivered_at < :cutoff) OR (status = 'dead' AND created_at < :cutoff)",
                ['cutoff' => $webhookCutoff],
                $batchSize
            ),
        ];
        if ($this->tableExists('notification_claims')) {
            $result['notification_claims'] = $this->deleteRowsInBatches(
                'notification_claims',
                'completed_at IS NOT NULL AND completed_at < :cutoff',
                ['cutoff' => gmdate('Y-m-d\TH:i:s\Z', $now - 180 * 86400)],
                $batchSize,
                '(notification_type, dedupe_key)',
                'notification_type, dedupe_key'
            );
        }
        if ($this->tableExists('short_domain_retirement_jobs')) {
            $result['short_domain_retirement_jobs'] = $this->deleteRowsInBatches(
                'short_domain_retirement_jobs',
                "status = 'completed' AND completed_at < :cutoff",
                ['cutoff' => gmdate('Y-m-d\TH:i:s\Z', $now - 180 * 86400)],
                $batchSize
            );
        }
        return $result;
    }

    private function tableExists(string $table): bool
    {
        $statement = $this->pdo->prepare(
            "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :name"
        );
        $statement->execute(['name' => $table]);
        return (bool)$statement->fetchColumn();
    }

    private function deleteRowsInBatches(
        string $table,
        string $where,
        array $parameters,
        int $batchSize,
        string $keyPredicate = 'rowid',
        string $keyColumns = 'rowid'
    ): int {
        $statement = $this->pdo->prepare(
            "DELETE FROM {$table} WHERE {$keyPredicate} IN "
            . "(SELECT {$keyColumns} FROM {$table} WHERE {$where} LIMIT :batch_size)"
        );
        $deleted = 0;
        do {
            foreach ($parameters as $name => $value) {
                $statement->bindValue(':' . $name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
            }
            $statement->bindValue(':batch_size', $batchSize, PDO::PARAM_INT);
            with_sqlite_retry(fn () => $statement->execute());
            $batchDeleted = $statement->rowCount();
            $deleted += $batchDeleted;
        } while ($batchDeleted === $batchSize);
        return $deleted;
    }

    public function targetHealthForLink(int $id): ?array
    {
        $statement = $this->pdo->prepare(<<<'SQL'
            SELECT state, reason, checked_at, next_check_at, last_healthy_at, http_status,
                   latency_ms, effective_url, redirect_count, redirect_state, consecutive_failures
            FROM target_health WHERE link_id = :link_id
        SQL);
        $statement->execute(['link_id' => $id]);
        $result = $statement->fetch();
        return $result ?: null;
    }
}
