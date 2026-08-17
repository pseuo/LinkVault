<?php

declare(strict_types=1);

trait LinkImportExportTrait
{
    public function exportLinks(
        string $view = 'active',
        string $search = '',
        string $status = 'all',
        string $sort = 'created_asc',
        string $tag = '',
        bool $favoritesOnly = false,
        ?array $selectedIds = null
    ): array {
        [$where, $params] = $this->adminFilter($view, $search, $status, $tag, $favoritesOnly);
        if ($selectedIds !== null) {
            $selectedIds = array_values(array_unique(array_filter(
                array_map('intval', $selectedIds),
                static fn (int $id): bool => $id > 0
            )));
            if (!$selectedIds || count($selectedIds) > 1000) {
                return [];
            }
            $placeholders = [];
            foreach ($selectedIds as $index => $id) {
                $name = 'selected_' . $index;
                $placeholders[] = ':' . $name;
                $params[$name] = $id;
            }
            $where .= ' AND l.id IN (' . implode(', ', $placeholders) . ')';
        }

        $statement = $this->pdo->prepare(<<<SQL
            SELECT l.slug, l.target_url, l.title, l.is_active, l.expires_at, l.is_favorite,
                   l.starts_at, l.max_clicks, l.is_one_time, l.one_time_mode,
                   l.campaign_name, l.campaign_source, l.campaign_medium, l.campaign_content,
                   CASE WHEN l.access_password_hash IS NOT NULL
                             OR l.access_password_reset_required = 1 THEN 1 ELSE 0 END
                       AS password_protected,
                    l.invalid_message, l.fallback_url, d.hostname AS short_domain,
                   COALESCE((
                       SELECT GROUP_CONCAT(tag, X'1F')
                       FROM (SELECT tag FROM link_tags WHERE link_id = l.id ORDER BY tag COLLATE NOCASE)
                   ), '') AS tags
             FROM links l LEFT JOIN short_domains d ON d.id = l.short_domain_id
            WHERE {$where}
            ORDER BY {$this->adminOrder($sort)}
        SQL);
        foreach ($params as $name => $value) {
            $statement->bindValue(':' . $name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $statement->execute();
        $links = $statement->fetchAll();
        foreach ($links as &$link) {
            $link['tags'] = $link['tags'] === '' ? [] : explode("\x1F", (string)$link['tags']);
        }
        unset($link);
        return $links;
    }

    /** @return array<string, int> */

    public function streamFullSnapshot(callable $write, string $exportedAt): array
    {
        $this->pdo->beginTransaction();
        try {
            $schemaVersion = (int)$this->pdo->query('PRAGMA user_version')->fetchColumn();
            $write("{\n");
            $write('  "version": 1,' . "\n");
            $write('  "kind": "full_data_snapshot",' . "\n");
            $write('  "restorable": false,' . "\n");
            $write('  "schema_version": ' . $schemaVersion . ',' . "\n");
            $write('  "exported_at": ' . json_encode(
                $exportedAt,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            ));

            $tables = [
                'links' => <<<'SQL'
                    SELECT id, slug, target_url, title, clicks, is_active, expires_at, deleted_at,
                           created_at, updated_at, last_accessed_at, is_favorite, starts_at,
                           max_clicks, is_one_time, one_time_mode, campaign_name, campaign_source,
                           campaign_medium, campaign_content, invalid_message, fallback_url,
                           short_domain_id, access_password_reset_required AS password_reset_required,
                           CASE WHEN access_password_hash IS NOT NULL
                                     OR access_password_reset_required = 1 THEN 1 ELSE 0 END
                               AS password_protected
                    FROM links ORDER BY id ASC
                SQL,
                'link_daily_stats' => <<<'SQL'
                    SELECT link_id, accessed_on, clicks FROM link_daily_stats
                    ORDER BY link_id ASC, accessed_on ASC
                SQL,
                'link_tags' => 'SELECT link_id, tag FROM link_tags ORDER BY link_id ASC, tag COLLATE NOCASE ASC',
                'link_aliases' => <<<'SQL'
                    SELECT alias, link_id, created_at FROM link_aliases
                    ORDER BY alias COLLATE NOCASE ASC
                SQL,
                'link_presets' => <<<'SQL'
                    SELECT id, name, values_json, created_at, updated_at FROM link_presets
                    ORDER BY name COLLATE NOCASE ASC, id ASC
                SQL,
                'link_status_history' => <<<'SQL'
                    SELECT id, link_id, event, from_status, to_status, created_at
                    FROM link_status_history ORDER BY id ASC
                SQL,
                'audit_events' => <<<'SQL'
                    SELECT id, created_at, actor_type, action, outcome, entity_type,
                           entity_id, request_id, details_json
                    FROM audit_events ORDER BY id ASC
                SQL,
                'link_daily_stats_archive' => <<<'SQL'
                    SELECT link_id, slug, title, accessed_on, clicks, archived_at
                    FROM link_daily_stats_archive ORDER BY link_id ASC, accessed_on ASC
                SQL,
                'saved_filters' => <<<'SQL'
                    SELECT id, name, view, search, status, sort, tag, favorites_only, created_at, updated_at
                    FROM saved_filters ORDER BY name COLLATE NOCASE ASC, id ASC
                SQL,
                'api_tokens' => <<<'SQL'
                    SELECT id, name, token_prefix, scopes, created_at, expires_at, last_used_at,
                            use_count, revoked_at, rotated_from_id, rotation_expires_at
                    FROM api_tokens ORDER BY id ASC
                SQL,
                'api_token_usage' => <<<'SQL'
                    SELECT id, token_id, token_name, token_prefix, used_at, outcome, endpoint, request_id
                    FROM api_token_usage ORDER BY id ASC
                SQL,
                'visitor_hourly_stats' => <<<'SQL'
                    SELECT link_id, accessed_hour, country_code, device_type, browser,
                           operating_system, referrer_domain, visitor_kind, request_kind,
                           campaign_name, campaign_source, campaign_medium, campaign_content, clicks
                    FROM visitor_hourly_stats
                    ORDER BY link_id ASC, accessed_hour ASC
                SQL,
                'visitor_daily_stats' => <<<'SQL'
                    SELECT link_id, accessed_on, country_code, device_type, browser,
                           operating_system, referrer_domain, visitor_kind, request_kind,
                           campaign_name, campaign_source, campaign_medium, campaign_content, clicks
                    FROM visitor_daily_stats
                    ORDER BY link_id ASC, accessed_on ASC
                SQL,
                'link_campaign_snapshots' => <<<'SQL'
                    SELECT link_id, effective_at_ms, campaign_name, campaign_source,
                           campaign_medium, campaign_content
                    FROM link_campaign_snapshots
                    ORDER BY link_id ASC, effective_at_ms ASC
                SQL,
                'target_health' => <<<'SQL'
                    SELECT link_id, target_url_hash, state, reason, checked_at, next_check_at,
                           last_healthy_at, http_status, latency_ms, effective_url, redirect_count,
                            redirect_state, consecutive_failures, redirect_chain_json,
                            ignored_at, ignored_reason
                    FROM target_health ORDER BY link_id ASC
                SQL,
                'bulk_operations' => <<<'SQL'
                    SELECT id, action, parameters_json, items_json, status, reversible,
                           selected_count, eligible_count, changed_count, result_json, created_at,
                           preview_expires_at, applied_at, undo_expires_at, undone_at, retain_until
                    FROM bulk_operations ORDER BY created_at ASC, id ASC
                SQL,
                'saved_analytics_views' => <<<'SQL'
                    SELECT id, name, request_json, created_at, updated_at
                    FROM saved_analytics_views ORDER BY name COLLATE NOCASE ASC, id ASC
                SQL,
                'analytics_alert_state' => <<<'SQL'
                    SELECT anomaly_type, is_active, last_notified_at, last_value, updated_at
                    FROM analytics_alert_state ORDER BY anomaly_type ASC
                SQL,
                'analytics_ingest_state' => <<<'SQL'
                    SELECT source_path, inode, byte_offset, checkpoint_hash, updated_at
                    FROM analytics_ingest_state ORDER BY source_path ASC
                SQL,
                'short_domains' => <<<'SQL'
                    SELECT id, hostname, verified_at, is_enabled, brand_name, brand_tagline,
                           brand_theme, brand_color, logo_url, favicon_url,
                           invalid_page_title, invalid_page_message, created_at, updated_at
                    FROM short_domains ORDER BY hostname ASC
                SQL,
                'webhook_outbox' => <<<'SQL'
                    SELECT event_id, event_type, link_id, dedupe_key, status, attempts,
                            available_at, leased_until, last_error, created_at, delivered_at,
                            replay_count
                    FROM webhook_outbox ORDER BY created_at ASC, event_id ASC
                SQL,
                'webhook_delivery_attempts' => <<<'SQL'
                    SELECT id, event_id, attempt_number, attempted_at, http_status,
                           duration_ms, error
                    FROM webhook_delivery_attempts
                    ORDER BY event_id ASC, attempt_number ASC, id ASC
                SQL,
            ];
            $databaseTables = array_map('strval', $this->pdo->query(<<<'SQL'
                SELECT name FROM sqlite_schema
                WHERE type = 'table' AND name NOT LIKE 'sqlite_%'
                ORDER BY name ASC
            SQL)->fetchAll(PDO::FETCH_COLUMN));
            $write(",\n  \"table_manifest\": " . json_encode([
                'included_tables' => array_keys($tables),
                'excluded_tables' => array_values(array_diff($databaseTables, array_keys($tables))),
                'redacted_fields' => [
                    'links.access_password_hash', 'api_tokens.token_hash',
                    'short_domains.verification_token', 'webhook_outbox.payload_json',
                ],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $counts = [];
            foreach ($tables as $name => $sql) {
                $write(",\n  " . json_encode($name, JSON_THROW_ON_ERROR) . ": [\n");
                $statement = $this->pdo->query($sql);
                $first = true;
                $counts[$name] = 0;
                while ($row = $statement->fetch()) {
                    $write(($first ? '' : ",\n") . '    ' . json_encode(
                        $row,
                        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                            | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
                    ));
                    $first = false;
                    $counts[$name]++;
                }
                $statement->closeCursor();
                $write("\n  ]");
            }
            $write("\n}\n");
            $this->pdo->commit();
            return $counts;
        } catch (Throwable $exception) {
            $this->rollback();
            throw $exception;
        }
    }

    public function analyzeImport(array $items, int $formatVersion = 1, string $conflictMode = 'skip'): array
    {
        if (!in_array($formatVersion, [1, 2, 3], true)) {
            throw new InvalidArgumentException('Unsupported link export version.');
        }
        if (!in_array($conflictMode, ['skip', 'overwrite', 'new_slug'], true)) {
            throw new InvalidArgumentException('Unsupported import conflict mode.');
        }
        if (count($items) > $this->importMaxRecords) {
            throw new InvalidArgumentException('The import contains too many records.');
        }

        $seen = [];
        $normalizedRows = [];
        $duplicates = [];
        $invalid = [];
        foreach ($items as $index => $item) {
            [$normalized, $error] = $this->normalizeImportItem($item, $formatVersion);
            $row = $index + 1;
            if ($normalized === null) {
                $invalid[] = ['row' => $row, 'slug' => is_array($item) && is_scalar($item['slug'] ?? null) ? (string)$item['slug'] : '', 'reason' => $error];
                continue;
            }
            $slug = $normalized['slug'];
            if (isset($seen[$slug])) {
                $duplicates[] = [
                    'row' => $row,
                    'slug' => $slug,
                    'reason' => '文件内短码重复',
                ];
                continue;
            }
            $seen[$slug] = true;
            $normalizedRows[] = ['row' => $row, 'item' => $normalized];
        }

        $existing = [];
        foreach ($this->pdo->query(<<<'SQL'
            SELECT l.id, l.slug, l.target_url, l.title, l.is_active, l.expires_at, l.is_favorite,
                   l.starts_at, l.max_clicks, l.is_one_time, l.one_time_mode,
                   l.campaign_name, l.campaign_source, l.campaign_medium, l.campaign_content,
                   l.access_password_reset_required, l.invalid_message, l.fallback_url,
                   l.short_domain_id, d.hostname AS short_domain,
                   CASE WHEN l.access_password_hash IS NOT NULL
                             OR l.access_password_reset_required = 1 THEN 1 ELSE 0 END
                       AS password_protected,
                   l.deleted_at, l.updated_at,
                   COALESCE((
                       SELECT GROUP_CONCAT(tag, X'1F')
                       FROM (SELECT tag FROM link_tags WHERE link_id = l.id ORDER BY tag)
                   ), '') AS tags
            FROM links l LEFT JOIN short_domains d ON d.id = l.short_domain_id
        SQL) as $link) {
            $link['tags'] = (string)$link['tags'] === '' ? [] : explode("\x1F", (string)$link['tags']);
            $existing[(string)$link['slug']] = $link;
        }

        $aliases = array_fill_keys(array_map(
            'strval',
            $this->pdo->query('SELECT alias FROM link_aliases')->fetchAll(PDO::FETCH_COLUMN)
        ), true);
        $reservedSlugs = array_fill_keys(array_merge(array_keys($existing), array_keys($aliases), array_keys($seen)), true);
        $operations = [];
        $changes = [];
        $newCount = 0;
        $renamedCount = 0;
        $overwrittenCount = 0;
        $unchangedCount = 0;
        $passwordResetCount = 0;

        foreach ($normalizedRows as $normalizedRow) {
            $row = (int)$normalizedRow['row'];
            $item = $normalizedRow['item'];
            $sourceSlug = (string)$item['slug'];
            $current = $existing[$sourceSlug] ?? null;
            if (isset($aliases[$sourceSlug])) {
                if ($conflictMode === 'new_slug') {
                    $resultSlug = $this->allocateImportSlug($sourceSlug, $reservedSlugs);
                    $reservedSlugs[$resultSlug] = true;
                    $item['slug'] = $resultSlug;
                    $operation = $this->makeImportOperation('renamed', $row, $sourceSlug, $item, null);
                    $operations[] = $operation;
                    $changes[] = $this->importChangePreview($operation, null);
                    $renamedCount++;
                    $passwordResetCount += (int)$item['access_password_reset_required'];
                } else {
                    $duplicates[] = ['row' => $row, 'slug' => $sourceSlug, 'reason' => '短码已被旧短码别名占用'];
                }
                continue;
            }
            if (!is_array($current)) {
                $operation = $this->makeImportOperation('insert', $row, $sourceSlug, $item, null);
                $operations[] = $operation;
                $changes[] = $this->importChangePreview($operation, null);
                $newCount++;
                $passwordResetCount += (int)$item['access_password_reset_required'];
                continue;
            }

            if ($conflictMode === 'skip') {
                $duplicates[] = ['row' => $row, 'slug' => $sourceSlug, 'reason' => '短码已存在，按策略跳过'];
                continue;
            }

            if ($conflictMode === 'new_slug') {
                $resultSlug = $this->allocateImportSlug($sourceSlug, $reservedSlugs);
                $reservedSlugs[$resultSlug] = true;
                $item['slug'] = $resultSlug;
                $operation = $this->makeImportOperation('renamed', $row, $sourceSlug, $item, $current);
                $operations[] = $operation;
                $changes[] = $this->importChangePreview($operation, null);
                $renamedCount++;
                $passwordResetCount += (int)$item['access_password_reset_required'];
                continue;
            }

            if ((int)$item['access_password_reset_required'] === 0
                && (int)$current['password_protected'] === 1) {
                $item['password_protected'] = 1;
                $item['access_password_reset_required'] = (int)$current['access_password_reset_required'];
            }
            if ((int)$current['access_password_reset_required'] === 1) {
                $item['is_active'] = 0;
            }
            $diffs = $this->importDiffs($this->importPortableState($current), $this->importPortableState($item));
            if (!$diffs) {
                $unchangedCount++;
                continue;
            }
            $operation = $this->makeImportOperation('overwrite', $row, $sourceSlug, $item, $current);
            $operation['diffs'] = $diffs;
            $operations[] = $operation;
            $changes[] = $this->importChangePreview($operation, $current);
            $overwrittenCount++;
            $passwordResetCount += (int)$item['access_password_reset_required'];
        }

        return [
            'mode' => $conflictMode,
            'new' => $newCount,
            'renamed' => $renamedCount,
            'overwritten' => $overwrittenCount,
            'unchanged' => $unchangedCount,
            'writes' => count($operations),
            'duplicate' => count($duplicates),
            'invalid' => count($invalid),
            'password_reset_required' => $passwordResetCount,
            'items' => $operations,
            'changes' => $changes,
            'duplicates' => $duplicates,
            'errors' => $invalid,
        ];
    }

    /** @return array{imported: int, skipped: int} */

    public function import(array $items, int $formatVersion = 1): array
    {
        $analysis = $this->analyzeImport($items, $formatVersion, 'skip');
        $result = $this->importPrepared($analysis['items']);
        $result['skipped'] += $analysis['duplicate'] + $analysis['invalid'];
        return $result;
    }

    /** @return array{imported: int, renamed: int, overwritten: int, password_reset_required: int, skipped: int} */

    public function importPrepared(array $items): array
    {
        if (count($items) > $this->importMaxRecords) {
            throw new InvalidArgumentException('The import contains too many records.');
        }
        return with_sqlite_retry(function () use ($items): array {
            $this->pdo->exec('BEGIN IMMEDIATE');
            try {
                $insert = $this->pdo->prepare(<<<'SQL'
                    INSERT INTO links (
                        slug, target_url, title, is_active, expires_at, is_favorite,
                        starts_at, max_clicks, is_one_time, one_time_mode, campaign_name,
                        campaign_source, campaign_medium, campaign_content,
                        access_password_reset_required, invalid_message, fallback_url, short_domain_id,
                        created_at, updated_at
                    ) VALUES (
                        :slug, :target_url, :title, :is_active, :expires_at, :is_favorite,
                        :starts_at, :max_clicks, :is_one_time, :one_time_mode, :campaign_name,
                        :campaign_source, :campaign_medium, :campaign_content,
                        :access_password_reset_required, :invalid_message, :fallback_url, :short_domain_id,
                        :created_at, :updated_at
                    )
                SQL);
                $update = $this->pdo->prepare(<<<'SQL'
                    UPDATE links SET
                        target_url = :target_url,
                        title = :title,
                        is_active = :is_active,
                        expires_at = :expires_at,
                        is_favorite = :is_favorite,
                        starts_at = :starts_at,
                        max_clicks = :max_clicks,
                        is_one_time = :is_one_time,
                        one_time_mode = :one_time_mode,
                        campaign_name = :campaign_name,
                        campaign_source = :campaign_source,
                        campaign_medium = :campaign_medium,
                        campaign_content = :campaign_content,
                        access_password_hash = CASE
                            WHEN CAST(:clear_access_password AS INTEGER) = 1 THEN NULL
                            ELSE access_password_hash
                        END,
                        access_password_reset_required = CASE
                            WHEN CAST(:access_password_reset_required AS INTEGER) = 1 THEN 1
                            ELSE access_password_reset_required
                        END,
                        invalid_message = :invalid_message,
                        fallback_url = :fallback_url,
                        short_domain_id = :short_domain_id,
                        updated_at = :updated_at
                    WHERE id = :id
                SQL);
                $imported = 0;
                $renamed = 0;
                $overwritten = 0;
                $passwordResetRequired = 0;
                $skipped = 0;
                foreach (array_chunk($items, $this->importBatchSize) as $batch) {
                    foreach ($batch as $operation) {
                        if (!is_array($operation) || !is_array($operation['item'] ?? null)
                            || !in_array($operation['action'] ?? null, ['insert', 'renamed', 'overwrite'], true)) {
                            throw new InvalidArgumentException('The prepared import plan is invalid.');
                        }
                        $item = $operation['item'];
                        $action = (string)$operation['action'];
                        $current = $this->findImportLinkBySlug((string)$operation['source_slug']);
                        if ($action === 'insert') {
                            if ($current !== null || $this->importSlugIsAlias((string)$item['slug'])) {
                                throw new RuntimeException('Import preview is stale: a short code is now occupied.');
                            }
                        } elseif ($action !== 'renamed' || is_array($operation['expected'] ?? null)) {
                            $expected = $operation['expected'] ?? null;
                            if (!is_array($current) || !is_array($expected)
                                || (int)$current['id'] !== (int)($expected['id'] ?? 0)
                                || !hash_equals((string)($expected['hash'] ?? ''), $this->importStateHash($current))) {
                                throw new RuntimeException('Import preview is stale: a conflicting link changed.');
                            }
                        }
                        if ($action === 'renamed' && ($this->findImportLinkBySlug((string)$item['slug']) !== null
                            || $this->importSlugIsAlias((string)$item['slug']))) {
                            throw new RuntimeException('Import preview is stale: a generated short code is now occupied.');
                        }
                        if (array_key_exists('short_domain_id', $item) && $item['short_domain_id'] !== null) {
                            $domain = $this->pdo->prepare(<<<'SQL'
                                SELECT 1 FROM short_domains
                                WHERE id = :id AND verified_at IS NOT NULL AND is_enabled = 1
                            SQL);
                            $domain->execute(['id' => (int)$item['short_domain_id']]);
                            if (!$domain->fetchColumn()) {
                                throw new RuntimeException('Import preview is stale: a short domain is no longer available.');
                            }
                        }

                        $now = utc_timestamp();
                        $writeValues = [
                            'slug' => $item['slug'],
                            'target_url' => $item['target_url'],
                            'title' => $item['title'],
                            'is_active' => $item['is_active'],
                            'expires_at' => $item['expires_at'],
                            'is_favorite' => $item['is_favorite'],
                            'starts_at' => $item['starts_at'],
                            'max_clicks' => $item['max_clicks'],
                            'is_one_time' => $item['is_one_time'],
                            'one_time_mode' => $item['one_time_mode'],
                            'campaign_name' => $item['campaign_name'],
                            'campaign_source' => $item['campaign_source'],
                            'campaign_medium' => $item['campaign_medium'],
                            'campaign_content' => $item['campaign_content'],
                            'access_password_reset_required' => $item['access_password_reset_required'],
                            'invalid_message' => $item['invalid_message'],
                            'fallback_url' => $item['fallback_url'],
                            'short_domain_id' => array_key_exists('short_domain_id', $item)
                                ? $item['short_domain_id']
                                : ($current['short_domain_id'] ?? null),
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                        if ($action === 'overwrite') {
                            unset(
                                $writeValues['slug'],
                                $writeValues['created_at']
                            );
                            $writeValues['clear_access_password'] = $item['access_password_reset_required'];
                            $writeValues['id'] = (int)$current['id'];
                            $update->execute($writeValues);
                            $this->replaceTags((int)$current['id'], $item['tags']);
                            $after = $this->findById((int)$current['id']);
                            $this->addHistory(
                                (int)$current['id'],
                                'import_overwritten',
                                link_status_key($current),
                                link_status_key($after ?: []),
                                $now
                            );
                            if ((int)$current['is_active'] === 1 && (int)($after['is_active'] ?? 1) === 0) {
                                $this->enqueueLifecycle(
                                    'link.disabled',
                                    (int)$current['id'],
                                    'link.disabled:' . (int)$current['id'] . ':' . $now
                                );
                            }
                            $overwritten++;
                            $passwordResetRequired += (int)$item['access_password_reset_required'];
                            continue;
                        }

                        $insert->execute($writeValues);
                        $id = (int)$this->pdo->lastInsertId();
                        $this->replaceTags($id, $item['tags']);
                        $created = $this->findById($id);
                        $this->addHistory($id, 'imported', null, link_status_key($created ?: []), $now);
                        $this->enqueueLifecycle('link.created', $id, 'link.created:' . $id);
                        $imported++;
                        $passwordResetRequired += (int)$item['access_password_reset_required'];
                        if ($action === 'renamed') {
                            $renamed++;
                        }
                    }
                }
                $this->pdo->commit();
                return [
                    'imported' => $imported,
                    'renamed' => $renamed,
                    'overwritten' => $overwritten,
                    'password_reset_required' => $passwordResetRequired,
                    'skipped' => $skipped,
                ];
            } catch (Throwable $exception) {
                $this->rollback();
                throw $exception;
            }
        });
    }

    private function makeImportOperation(
        string $action,
        int $row,
        string $sourceSlug,
        array $item,
        ?array $current
    ): array {
        return [
            'action' => $action,
            'row' => $row,
            'source_slug' => $sourceSlug,
            'result_slug' => (string)$item['slug'],
            'item' => $item,
            'expected' => $current === null ? null : [
                'id' => (int)$current['id'],
                'hash' => $this->importStateHash($current),
            ],
            'diffs' => $this->importDiffs(
                null,
                array_merge(['slug' => (string)$item['slug']], $this->importPortableState($item)),
                $sourceSlug
            ),
        ];
    }

    private function importChangePreview(array $operation, ?array $current): array
    {
        return [
            'row' => (int)$operation['row'],
            'action' => (string)$operation['action'],
            'source_slug' => (string)$operation['source_slug'],
            'result_slug' => (string)$operation['result_slug'],
            'deleted' => $current !== null && !empty($current['deleted_at']),
            'diffs' => $operation['diffs'],
        ];
    }

    private function importPortableState(array $item): array
    {
        $state = [];
        foreach ([
            'target_url', 'title', 'is_active', 'expires_at', 'is_favorite', 'starts_at',
            'max_clicks', 'is_one_time', 'one_time_mode', 'campaign_name', 'campaign_source',
            'campaign_medium', 'campaign_content',
            'password_protected', 'access_password_reset_required', 'invalid_message', 'fallback_url',
        ] as $field) {
            $value = $item[$field] ?? null;
            $state[$field] = in_array($field, ['is_active', 'is_favorite', 'is_one_time'], true)
                ? (int)$value
                : ($field === 'max_clicks' && $value !== null ? (int)$value : $value);
        }
        $tags = is_array($item['tags'] ?? null) ? array_values(array_unique($item['tags'])) : [];
        sort($tags, SORT_STRING);
        if (array_key_exists('short_domain', $item)) {
            $state['short_domain'] = $item['short_domain'];
        }
        $state['tags'] = $tags;
        return $state;
    }

    private function importDiffs(?array $before, array $after, ?string $sourceSlug = null): array
    {
        $diffs = [];
        if ($sourceSlug !== null && $sourceSlug !== '') {
            $resultSlug = (string)($after['slug'] ?? $sourceSlug);
            if ($resultSlug !== $sourceSlug) {
                $diffs[] = ['field' => 'slug', 'before' => $sourceSlug, 'after' => $resultSlug];
            }
        }
        foreach ($after as $field => $value) {
            if ($field === 'slug') {
                continue;
            }
            $previous = $before[$field] ?? null;
            if ($before === null || $previous !== $value) {
                $diffs[] = ['field' => $field, 'before' => $before === null ? null : $previous, 'after' => $value];
            }
        }
        return $diffs;
    }

    private function importStateHash(array $link): string
    {
        return hash('sha256', json_encode([
            'id' => (int)$link['id'],
            'slug' => (string)$link['slug'],
            'portable' => $this->importPortableState($link),
            'deleted_at' => $link['deleted_at'] ?? null,
            'access_password_reset_required' => (int)($link['access_password_reset_required'] ?? 0),
            'updated_at' => (string)($link['updated_at'] ?? ''),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function findImportLinkBySlug(string $slug): ?array
    {
        $statement = $this->pdo->prepare(<<<'SQL'
            SELECT l.id, l.slug, l.target_url, l.title, l.is_active, l.expires_at, l.is_favorite,
                   l.starts_at, l.max_clicks, l.is_one_time, l.one_time_mode,
                   l.campaign_name, l.campaign_source, l.campaign_medium, l.campaign_content,
                   l.access_password_reset_required, l.invalid_message, l.fallback_url,
                   l.short_domain_id, d.hostname AS short_domain,
                   CASE WHEN l.access_password_hash IS NOT NULL
                             OR l.access_password_reset_required = 1 THEN 1 ELSE 0 END
                       AS password_protected,
                   l.deleted_at, l.updated_at,
                   COALESCE((SELECT GROUP_CONCAT(tag, X'1F') FROM (
                       SELECT tag FROM link_tags WHERE link_id = l.id ORDER BY tag
                   )), '') AS tags
            FROM links l LEFT JOIN short_domains d ON d.id = l.short_domain_id
            WHERE l.slug = :slug
        SQL);
        $statement->execute(['slug' => $slug]);
        $link = $statement->fetch();
        if (!$link) {
            return null;
        }
        $link['tags'] = (string)$link['tags'] === '' ? [] : explode("\x1F", (string)$link['tags']);
        return $link;
    }

    private function importSlugIsAlias(string $slug): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM link_aliases WHERE alias = :slug');
        $statement->execute(['slug' => $slug]);
        return (bool)$statement->fetchColumn();
    }

    private function allocateImportSlug(string $sourceSlug, array $reservedSlugs): string
    {
        for ($suffixNumber = 2; $suffixNumber < 1000000; $suffixNumber++) {
            $suffix = '-' . $suffixNumber;
            $candidate = substr($sourceSlug, 0, 64 - strlen($suffix)) . $suffix;
            if (!isset($reservedSlugs[$candidate])) {
                return $candidate;
            }
        }
        throw new RuntimeException('Cannot allocate a new short code for import.');
    }

    private function normalizeImportItem(mixed $item, int $formatVersion): array
    {
        if (!is_array($item) || array_is_list($item)) {
            return [null, '记录不是对象'];
        }
        if (!is_string($item['slug'] ?? null)) {
            return [null, '短码必须是字符串'];
        }
        if (!is_string($item['target_url'] ?? null)) {
            return [null, '目标地址必须是字符串'];
        }
        $slug = trim($item['slug']);
        $targetUrl = trim($item['target_url']);
        if (!valid_slug($slug)) {
            return [null, '短码无效'];
        }
        if (!valid_target_url($targetUrl, $this->maxUrlLength)) {
            return [null, '目标地址无效或过长'];
        }
        $expiration = $item['expires_at'] ?? null;
        if ($expiration !== null && !is_string($expiration)) {
            return [null, '过期时间格式无效'];
        }
        [$expirationValid, $expiresAt] = normalize_expiration($expiration ?? '');
        $start = $item['starts_at'] ?? null;
        if ($start !== null && !is_string($start)) {
            return [null, '启用时间格式无效'];
        }
        [$startValid, $startsAt] = normalize_expiration($start ?? '');
        if (!$expirationValid || !$startValid) {
            return [null, '时间必须是带时区的 ISO 8601'];
        }
        if ($startsAt !== null && $expiresAt !== null && $startsAt >= $expiresAt) {
            return [null, '启用时间必须早于过期时间'];
        }
        $title = $item['title'] ?? '';
        if (!is_string($title)) {
            return [null, '标题必须是字符串'];
        }
        $title = trim($title);
        if (text_length($title) > 120) {
            return [null, '标题不能超过 120 个字符'];
        }
        $tagValue = $item['tags'] ?? '';
        if (!is_array($tagValue) && !is_string($tagValue)) {
            return [null, '标签格式无效'];
        }
        [$tagsValid, $tags] = is_array($tagValue)
            ? normalize_tag_list($tagValue)
            : normalize_tags($tagValue);
        if (!$tagsValid) {
            return [null, is_array($tagValue)
                ? '标签数组至多包含 10 个非空字符串，每个最多 24 个字符'
                : '标签不能超过 10 个，每个最多 24 个字符'];
        }
        $maxClicks = $item['max_clicks'] ?? null;
        if ($maxClicks !== null && (!is_int($maxClicks) || $maxClicks < 1 || $maxClicks > 2147483647)) {
            return [null, '最大点击次数无效'];
        }
        foreach (['is_active', 'is_favorite', 'is_one_time'] as $booleanIntegerField) {
            if (array_key_exists($booleanIntegerField, $item)
                && (!is_int($item[$booleanIntegerField]) || !in_array($item[$booleanIntegerField], [0, 1], true))) {
                return [null, $booleanIntegerField . ' 必须是整数 0 或 1'];
            }
        }
        $isOneTime = ($item['is_one_time'] ?? 0) === 1;
        $oneTimeMode = $item['one_time_mode'] ?? 'immediate';
        if (!is_string($oneTimeMode) || !in_array($oneTimeMode, ['immediate', 'confirm'], true)) {
            return [null, '一次性链接消费模式无效'];
        }
        $campaign = [];
        foreach (['campaign_name', 'campaign_source', 'campaign_medium', 'campaign_content'] as $campaignField) {
            $campaignValue = $item[$campaignField] ?? '';
            if (!is_string($campaignValue) || !valid_campaign_value(trim($campaignValue))) {
                return [null, '活动归因字段无效'];
            }
            $campaign[$campaignField] = trim($campaignValue);
        }
        $passwordProtected = 0;
        $invalidMessage = '';
        $fallbackUrl = null;
        if ($formatVersion >= 2) {
            foreach (['password_protected', 'invalid_message', 'fallback_url'] as $requiredField) {
                if (!array_key_exists($requiredField, $item)) {
                    return [null, "v2 记录缺少 {$requiredField}"];
                }
            }
            if (!is_int($item['password_protected'])
                || !in_array($item['password_protected'], [0, 1], true)) {
                return [null, 'password_protected 必须是整数 0 或 1'];
            }
            if (!is_string($item['invalid_message']) || !valid_invalid_message($item['invalid_message'])) {
                return [null, '失效提示格式无效'];
            }
            if ($item['fallback_url'] !== null && !is_string($item['fallback_url'])) {
                return [null, '备用地址格式无效'];
            }
            $fallbackValue = is_string($item['fallback_url']) ? trim($item['fallback_url']) : '';
            if ($fallbackValue !== '' && !valid_target_url($fallbackValue, $this->maxUrlLength)) {
                return [null, '备用地址无效或过长'];
            }
            $passwordProtected = $item['password_protected'];
            $invalidMessage = trim($item['invalid_message']);
            $fallbackUrl = $fallbackValue === '' ? null : $fallbackValue;
        }
        $shortDomainId = null;
        if ($formatVersion >= 3) {
            if (!array_key_exists('short_domain', $item)
                || ($item['short_domain'] !== null && !is_string($item['short_domain']))) {
                return [null, 'v3 记录缺少有效的 short_domain'];
            }
            $hostname = is_string($item['short_domain']) ? strtolower(rtrim(trim($item['short_domain']), '.')) : '';
            if ($hostname !== '') {
                $domain = $this->pdo->prepare(<<<'SQL'
                    SELECT id FROM short_domains
                    WHERE hostname = :hostname AND verified_at IS NOT NULL AND is_enabled = 1
                SQL);
                $domain->execute(['hostname' => $hostname]);
                $shortDomainId = $domain->fetchColumn();
                if ($shortDomainId === false) {
                    return [null, '短链域名不存在、未验证或已停用'];
                }
                $shortDomainId = (int)$shortDomainId;
            }
        }
        $normalized = [
            'slug' => $slug,
            'target_url' => $targetUrl,
            'title' => $title,
            'is_active' => $passwordProtected === 1 ? 0 : ($item['is_active'] ?? 1),
            'expires_at' => $expiresAt,
            'is_favorite' => $item['is_favorite'] ?? 0,
            'starts_at' => $startsAt,
            'max_clicks' => $maxClicks,
            'is_one_time' => $isOneTime ? 1 : 0,
            'one_time_mode' => $isOneTime && $oneTimeMode === 'confirm' ? 'confirm' : 'immediate',
            'campaign_name' => $campaign['campaign_name'],
            'campaign_source' => $campaign['campaign_source'],
            'campaign_medium' => $campaign['campaign_medium'],
            'campaign_content' => $campaign['campaign_content'],
            'password_protected' => $passwordProtected,
            'access_password_reset_required' => $passwordProtected,
            'invalid_message' => $invalidMessage,
            'fallback_url' => $fallbackUrl,
            'tags' => $tags,
        ];
        if ($formatVersion >= 3) {
            $normalized['short_domain_id'] = $shortDomainId;
            $normalized['short_domain'] = $hostname === '' ? null : $hostname;
        }
        return [$normalized, ''];
    }
}
