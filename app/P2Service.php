<?php

declare(strict_types=1);

final class ConversionIdempotencyConflict extends RuntimeException
{
}

final class P2Service
{
    public function __construct(private readonly PDO $pdo, private readonly array $config, private readonly ?LinkService $links = null)
    {
    }

    public function saveTagRule(
        string $name,
        string $field,
        string $operator,
        string $pattern,
        array $tags,
        int $priority = 100,
        bool $enabled = true,
        ?int $id = null
    ): int {
        $name = trim($name);
        $pattern = trim($pattern);
        [$tagsValid, $tags] = normalize_tag_list($tags);
        if ($name === '' || text_length($name) > 60 || $pattern === '' || text_length($pattern) > 500
            || !in_array($field, ['url', 'host', 'path', 'title'], true)
            || !in_array($operator, ['contains', 'prefix', 'suffix', 'equals', 'regex'], true)
            || !$tagsValid || !$tags || $priority < 0 || $priority > 10000
            || ($operator === 'regex' && @preg_match('~' . str_replace('~', '\\~', $pattern) . '~iu', '') === false)) {
            throw new InvalidArgumentException('Invalid tag rule.');
        }
        $now = utc_timestamp();
        $values = [
            'name' => $name,
            'field' => $field,
            'operator' => $operator,
            'pattern' => $pattern,
            'tags_json' => json_encode($tags, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'priority' => $priority,
            'is_enabled' => $enabled ? 1 : 0,
            'updated_at' => $now,
        ];
        if ($id !== null && $id > 0) {
            $statement = $this->pdo->prepare(<<<'SQL'
                UPDATE tag_rules SET name = :name, field = :field, operator = :operator,
                    pattern = :pattern, tags_json = :tags_json, priority = :priority,
                    is_enabled = :is_enabled, updated_at = :updated_at
                WHERE id = :id
            SQL);
            $statement->execute($values + ['id' => $id]);
            if ($statement->rowCount() !== 1) {
                throw new InvalidArgumentException('Tag rule not found.');
            }
            return $id;
        }
        $statement = $this->pdo->prepare(<<<'SQL'
            INSERT INTO tag_rules (
                name, field, operator, pattern, tags_json, priority, is_enabled, created_at, updated_at
            ) VALUES (
                :name, :field, :operator, :pattern, :tags_json, :priority, :is_enabled, :created_at, :updated_at
            )
        SQL);
        $statement->execute($values + ['created_at' => $now]);
        return (int)$this->pdo->lastInsertId();
    }

    public function tagRules(): array
    {
        $rules = $this->pdo->query('SELECT * FROM tag_rules ORDER BY priority ASC, id ASC')->fetchAll();
        foreach ($rules as &$rule) {
            $decoded = json_decode((string)$rule['tags_json'], true);
            $rule['tags'] = is_array($decoded) ? array_values($decoded) : [];
        }
        unset($rule);
        return $rules;
    }

    public function deleteTagRule(int $id): bool
    {
        $statement = $this->pdo->prepare('DELETE FROM tag_rules WHERE id = :id');
        $statement->execute(['id' => $id]);
        return $statement->rowCount() === 1;
    }

    public function duplicateGroups(int $limit = 50): array
    {
        $statement = $this->pdo->prepare(<<<'SQL'
            SELECT target_url, COALESCE(short_domain_id, 0) AS domain_key, COUNT(*) AS link_count,
                   GROUP_CONCAT(id) AS ids
            FROM links
            WHERE deleted_at IS NULL
            GROUP BY target_url, COALESCE(short_domain_id, 0)
            HAVING COUNT(*) > 1
            ORDER BY link_count DESC, MIN(id) ASC
            LIMIT :record_limit
        SQL);
        $statement->bindValue(':record_limit', max(1, min(200, $limit)), PDO::PARAM_INT);
        $statement->execute();
        $groups = $statement->fetchAll();
        $find = $this->pdo->prepare('SELECT id, slug, title, clicks FROM links WHERE id = :id');
        foreach ($groups as &$group) {
            $group['links'] = [];
            foreach (explode(',', (string)$group['ids']) as $id) {
                $find->execute(['id' => (int)$id]);
                $link = $find->fetch();
                if ($link) {
                    $group['links'][] = $link;
                }
            }
        }
        unset($group);
        return $groups;
    }

    /** @return array{matched: int, changed: int} */
    public function applyTagRules(array $ids = []): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        if (count($ids) > 1000) {
            throw new InvalidArgumentException('Too many links selected.');
        }
        $where = 'deleted_at IS NULL';
        if ($ids) {
            $where .= ' AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        }
        $statement = $this->pdo->prepare("SELECT id, target_url, title FROM links WHERE {$where} ORDER BY id");
        $statement->execute($ids);
        $links = $statement->fetchAll();
        $rules = array_values(array_filter($this->tagRules(), static fn (array $rule): bool => (int)$rule['is_enabled'] === 1));
        $matched = 0;
        $changed = 0;

        with_sqlite_retry(function () use ($links, $rules, &$matched, &$changed): void {
            $this->pdo->beginTransaction();
            try {
                $tagQuery = $this->pdo->prepare('SELECT tag FROM link_tags WHERE link_id = :link_id ORDER BY tag');
                $tagInsert = $this->pdo->prepare('INSERT OR IGNORE INTO link_tags (link_id, tag) VALUES (:link_id, :tag)');
                $touch = $this->pdo->prepare('UPDATE links SET updated_at = :updated_at WHERE id = :id');
                foreach ($links as $link) {
                    $newTags = [];
                    foreach ($rules as $rule) {
                        if ($this->ruleMatches($rule, $link)) {
                            $newTags = array_values(array_unique(array_merge($newTags, (array)$rule['tags'])));
                        }
                    }
                    if (!$newTags) {
                        continue;
                    }
                    $matched++;
                    $tagQuery->execute(['link_id' => (int)$link['id']]);
                    $currentTags = array_map(static fn (array $row): string => (string)$row['tag'], $tagQuery->fetchAll());
                    $newTags = array_values(array_diff($newTags, $currentTags));
                    if (count($currentTags) + count($newTags) > 10) {
                        continue;
                    }
                    foreach ($newTags as $tag) {
                        $tagInsert->execute(['link_id' => (int)$link['id'], 'tag' => $tag]);
                    }
                    if ($newTags) {
                        $touch->execute(['updated_at' => utc_timestamp(), 'id' => (int)$link['id']]);
                        $changed++;
                    }
                }
                $this->pdo->commit();
            } catch (Throwable $exception) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $exception;
            }
        });
        return ['matched' => $matched, 'changed' => $changed];
    }

    /** @return array{merged: int, skipped: int} */
    public function mergeDuplicates(int $canonicalId, array $duplicateIds): array
    {
        $duplicateIds = array_values(array_unique(array_filter(
            array_map('intval', $duplicateIds),
            static fn (int $id): bool => $id > 0 && $id !== $canonicalId
        )));
        if ($canonicalId < 1 || !$duplicateIds || count($duplicateIds) > 100) {
            throw new InvalidArgumentException('Invalid duplicate merge selection.');
        }
        return with_sqlite_retry(function () use ($canonicalId, $duplicateIds): array {
            $this->pdo->exec('BEGIN IMMEDIATE');
            try {
                $find = $this->pdo->prepare('SELECT * FROM links WHERE id = :id AND deleted_at IS NULL');
                $find->execute(['id' => $canonicalId]);
                $canonical = $find->fetch();
                if (!$canonical) {
                    throw new InvalidArgumentException('Canonical link not found.');
                }
                $merged = 0;
                $skipped = 0;
                foreach ($duplicateIds as $duplicateId) {
                    $find->execute(['id' => $duplicateId]);
                    $duplicate = $find->fetch();
                    if (!$duplicate || (string)$duplicate['target_url'] !== (string)$canonical['target_url']
                        || (int)($duplicate['short_domain_id'] ?? 0) !== (int)($canonical['short_domain_id'] ?? 0)) {
                        $skipped++;
                        continue;
                    }
                    $oldSlug = (string)$duplicate['slug'];
                    $replacementSlug = substr('merged-' . $duplicateId . '-' . $oldSlug, 0, 64);
                    $suffix = 1;
                    $slugTaken = $this->pdo->prepare(<<<'SQL'
                        SELECT 1 FROM links WHERE slug = :slug
                        UNION ALL
                        SELECT 1 FROM link_aliases WHERE alias = :slug
                        LIMIT 1
                    SQL);
                    while (true) {
                        $slugTaken->execute(['slug' => $replacementSlug]);
                        if ($slugTaken->fetchColumn() === false) {
                            break;
                        }
                        $replacementSlug = substr('merged-' . $duplicateId . '-' . $suffix, 0, 64);
                        $suffix++;
                    }
                    $updateDuplicate = $this->pdo->prepare(<<<'SQL'
                        UPDATE links SET slug = :slug, deleted_at = :deleted_at, is_active = 0, updated_at = :updated_at
                        WHERE id = :id
                    SQL);
                    $now = utc_timestamp();
                    $updateDuplicate->execute([
                        'slug' => $replacementSlug,
                        'deleted_at' => $now,
                        'updated_at' => $now,
                        'id' => $duplicateId,
                    ]);
                    $alias = $this->pdo->prepare('INSERT INTO link_aliases (alias, link_id, created_at) VALUES (:alias, :link_id, :created_at)');
                    $alias->execute(['alias' => $oldSlug, 'link_id' => $canonicalId, 'created_at' => $now]);
                    $this->pdo->prepare('INSERT OR IGNORE INTO link_tags (link_id, tag) SELECT :canonical_id, tag FROM link_tags WHERE link_id = :duplicate_id')
                        ->execute(['canonical_id' => $canonicalId, 'duplicate_id' => $duplicateId]);
                    $this->pdo->prepare(<<<'SQL'
                        INSERT INTO link_daily_stats (link_id, accessed_on, clicks)
                        SELECT :canonical_id, accessed_on, clicks FROM link_daily_stats WHERE link_id = :duplicate_id
                        ON CONFLICT(link_id, accessed_on) DO UPDATE SET clicks = clicks + excluded.clicks
                    SQL)->execute(['canonical_id' => $canonicalId, 'duplicate_id' => $duplicateId]);
                    $this->pdo->prepare('DELETE FROM link_daily_stats WHERE link_id = :link_id')->execute(['link_id' => $duplicateId]);
                    $this->pdo->prepare('UPDATE conversion_events SET link_id = :canonical_id WHERE link_id = :duplicate_id')
                        ->execute(['canonical_id' => $canonicalId, 'duplicate_id' => $duplicateId]);
                    $this->pdo->prepare('UPDATE abuse_reports SET link_id = :canonical_id WHERE link_id = :duplicate_id')
                        ->execute(['canonical_id' => $canonicalId, 'duplicate_id' => $duplicateId]);
                    $this->pdo->prepare('UPDATE links SET clicks = clicks + :clicks, updated_at = :updated_at WHERE id = :id')
                        ->execute(['clicks' => (int)$duplicate['clicks'], 'updated_at' => $now, 'id' => $canonicalId]);
                    $merged++;
                }
                $this->pdo->commit();
                return ['merged' => $merged, 'skipped' => $skipped];
            } catch (Throwable $exception) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $exception;
            }
        });
    }

    public static function validConversionSignature(
        string $token,
        string $timestamp,
        string $idempotencyKey,
        string $body,
        string $signature,
        int $toleranceSeconds = 300
    ): bool {
        if (!ctype_digit($timestamp) || abs(time() - (int)$timestamp) > max(30, $toleranceSeconds)
            || preg_match('/^sha256=([a-f0-9]{64})$/D', strtolower(trim($signature)), $matches) !== 1) {
            return false;
        }
        $expected = hash_hmac('sha256', $timestamp . '.' . $idempotencyKey . '.' . $body, $token);
        return hash_equals($expected, $matches[1]);
    }

    /** @return array{id: int, event_id: string, replayed: bool} */
    public function recordConversion(int $tokenId, string $idempotencyKey, array $payload): array
    {
        $eventId = trim((string)($payload['event_id'] ?? ''));
        $eventName = trim((string)($payload['event'] ?? ''));
        $linkId = is_int($payload['link_id'] ?? null) ? $payload['link_id'] : 0;
        [$timeValid, $occurredAt] = normalize_expiration((string)($payload['occurred_at'] ?? ''));
        $valueMinor = $payload['value_minor'] ?? null;
        $currency = isset($payload['currency']) ? strtoupper(trim((string)$payload['currency'])) : null;
        $metadata = $payload['metadata'] ?? [];
        if ($tokenId < 1 || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/D', $eventId) !== 1
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,79}$/D', $eventName) !== 1
            || $linkId < 1 || !$timeValid || $occurredAt === null
            || ($valueMinor !== null && (!is_int($valueMinor) || $valueMinor < 0))
            || ($currency !== null && preg_match('/^[A-Z]{3}$/D', $currency) !== 1)
            || !is_array($metadata) || ($metadata !== [] && array_is_list($metadata))) {
            throw new InvalidArgumentException('Invalid conversion event.');
        }
        $occurredTimestamp = strtotime($occurredAt);
        if ($occurredTimestamp === false || $occurredTimestamp > time()) {
            throw new InvalidArgumentException('Conversion event time cannot be in the future.');
        }
        $metadataJson = json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (strlen($metadataJson) > 8192) {
            throw new InvalidArgumentException('Conversion metadata is too large.');
        }
        $canonical = json_encode([
            'event_id' => $eventId,
            'event' => $eventName,
            'link_id' => $linkId,
            'occurred_at' => $occurredAt,
            'value_minor' => $valueMinor,
            'currency' => $currency,
            'metadata' => $metadata,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $keyHash = hash('sha256', $idempotencyKey);
        $payloadHash = hash('sha256', $canonical);

        return with_sqlite_retry(function () use (
            $tokenId, $keyHash, $payloadHash, $eventId, $eventName, $linkId,
            $occurredAt, $valueMinor, $currency, $metadataJson
        ): array {
            $statement = $this->pdo->prepare(<<<'SQL'
                INSERT INTO conversion_events (
                    event_id, token_id, link_id, event_name, occurred_at, value_minor,
                    currency, metadata_json, idempotency_key_hash, payload_hash, created_at
                ) VALUES (
                    :event_id, :token_id, :link_id, :event_name, :occurred_at, :value_minor,
                    :currency, :metadata_json, :key_hash, :payload_hash, :created_at
                ) ON CONFLICT(token_id, idempotency_key_hash) DO NOTHING
            SQL);
            $statement->execute([
                'event_id' => $eventId,
                'token_id' => $tokenId,
                'link_id' => $linkId,
                'event_name' => $eventName,
                'occurred_at' => $occurredAt,
                'value_minor' => $valueMinor,
                'currency' => $currency,
                'metadata_json' => $metadataJson,
                'key_hash' => $keyHash,
                'payload_hash' => $payloadHash,
                'created_at' => utc_timestamp(),
            ]);
            $existing = $this->pdo->prepare(<<<'SQL'
                SELECT id, event_id, payload_hash FROM conversion_events
                WHERE token_id = :token_id AND idempotency_key_hash = :key_hash
            SQL);
            $existing->execute(['token_id' => $tokenId, 'key_hash' => $keyHash]);
            $row = $existing->fetch();
            if (!$row) {
                throw new RuntimeException('Conversion idempotency record was not persisted.');
            }
            if (!hash_equals((string)$row['payload_hash'], $payloadHash)) {
                throw new ConversionIdempotencyConflict('Idempotency key conflict.');
            }
            return [
                'id' => (int)$row['id'],
                'event_id' => (string)$row['event_id'],
                'replayed' => $statement->rowCount() === 0,
            ];
        });
    }

    public function saveFunnel(string $name, array $stages): int
    {
        $name = trim($name);
        $stages = array_values(array_unique(array_map(static fn (mixed $stage): string => trim((string)$stage), $stages)));
        if ($name === '' || text_length($name) > 80 || !$stages || count($stages) > 20
            || array_filter($stages, static fn (string $stage): bool => preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,79}$/D', $stage) !== 1)) {
            throw new InvalidArgumentException('Invalid funnel.');
        }
        $now = utc_timestamp();
        $statement = $this->pdo->prepare(<<<'SQL'
            INSERT INTO funnels (name, stages_json, created_at, updated_at)
            VALUES (:name, :stages_json, :created_at, :updated_at)
            ON CONFLICT(name) DO UPDATE SET stages_json = excluded.stages_json, updated_at = excluded.updated_at
        SQL);
        $statement->execute([
            'name' => $name,
            'stages_json' => json_encode($stages, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $id = (int)$this->pdo->lastInsertId();
        if ($id === 0) {
            $lookup = $this->pdo->prepare('SELECT id FROM funnels WHERE name = :name');
            $lookup->execute(['name' => $name]);
            $id = (int)$lookup->fetchColumn();
        }
        return $id;
    }

    public function funnelReport(?int $funnelId = null): array
    {
        $funnels = $funnelId === null
            ? $this->pdo->query('SELECT * FROM funnels ORDER BY name')->fetchAll()
            : (function () use ($funnelId): array {
                $statement = $this->pdo->prepare('SELECT * FROM funnels WHERE id = :id');
                $statement->execute(['id' => $funnelId]);
                return $statement->fetchAll();
            })();
        $clicks = (int)$this->pdo->query('SELECT COALESCE(SUM(clicks), 0) FROM links WHERE deleted_at IS NULL')->fetchColumn();
        $result = [];
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM conversion_events WHERE event_name = :event_name');
        foreach ($funnels as $funnel) {
            $stages = json_decode((string)$funnel['stages_json'], true);
            $stageRows = [['name' => 'click', 'count' => $clicks, 'rate' => $clicks > 0 ? 100.0 : 0.0]];
            foreach (is_array($stages) ? $stages : [] as $stage) {
                $count->execute(['event_name' => (string)$stage]);
                $stageCount = (int)$count->fetchColumn();
                $stageRows[] = [
                    'name' => (string)$stage,
                    'count' => $stageCount,
                    'rate' => $clicks > 0 ? round($stageCount * 100 / $clicks, 2) : 0.0,
                ];
            }
            $result[] = ['id' => (int)$funnel['id'], 'name' => (string)$funnel['name'], 'stages' => $stageRows];
        }
        return $result;
    }

    /** @return array{public_id: string, status: string} */
    public function submitReport(string $url, string $reason, string $details, string $contact, string $reporterHash): array
    {
        $url = trim($url);
        $details = trim($details);
        $contact = trim($contact);
        if (!valid_target_url($url, 2048) || !in_array($reason, ['phishing', 'malware', 'spam', 'fraud', 'other'], true)
            || text_length($details) > 1000 || text_length($contact) > 254 || strlen($reporterHash) !== 64) {
            throw new InvalidArgumentException('Invalid abuse report.');
        }
        $linkId = null;
        $host = strtolower(rtrim((string)(parse_url($url, PHP_URL_HOST) ?? ''), '.'));
        $path = (string)(parse_url($url, PHP_URL_PATH) ?? '');
        $slug = ltrim($path, '/');
        $baseHost = (string)(configured_base_url($this->config)['host'] ?? '');
        $managedHost = $host !== '' && hash_equals($baseHost, $host);
        if (!$managedHost && $host !== '') {
            $domainLookup = $this->pdo->prepare(<<<'SQL'
                SELECT 1 FROM short_domains
                WHERE hostname = :hostname AND verified_at IS NOT NULL AND is_enabled = 1
            SQL);
            $domainLookup->execute(['hostname' => $host]);
            $managedHost = $domainLookup->fetchColumn() !== false;
        }
        if ($managedHost && valid_slug($slug)) {
            $lookup = $this->pdo->prepare('SELECT id FROM links WHERE slug = :slug');
            $lookup->execute(['slug' => $slug]);
            $value = $lookup->fetchColumn();
            $linkId = $value === false ? null : (int)$value;
        }
        $publicId = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
        $now = utc_timestamp();
        $statement = $this->pdo->prepare(<<<'SQL'
            INSERT INTO abuse_reports (
                public_id, link_id, reported_url, reason, details, reporter_contact,
                reporter_hash, status, created_at, updated_at
            ) VALUES (
                :public_id, :link_id, :reported_url, :reason, :details, :reporter_contact,
                :reporter_hash, 'open', :created_at, :updated_at
            )
        SQL);
        $statement->execute([
            'public_id' => $publicId,
            'link_id' => $linkId,
            'reported_url' => $url,
            'reason' => $reason,
            'details' => $details,
            'reporter_contact' => $contact,
            'reporter_hash' => $reporterHash,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return ['public_id' => $publicId, 'status' => 'open'];
    }

    public function reports(string $status = 'open', int $limit = 100): array
    {
        $status = in_array($status, ['open', 'reviewing', 'resolved', 'rejected'], true) ? $status : 'open';
        $statement = $this->pdo->prepare(<<<'SQL'
            SELECT r.*, l.slug, l.title FROM abuse_reports r
            LEFT JOIN links l ON l.id = r.link_id
            WHERE r.status = :status ORDER BY r.created_at ASC, r.id ASC LIMIT :record_limit
        SQL);
        $statement->bindValue(':status', $status);
        $statement->bindValue(':record_limit', max(1, min(500, $limit)), PDO::PARAM_INT);
        $statement->execute();
        return $statement->fetchAll();
    }

    public function addBlacklistDomain(string $hostname, string $reason, bool $includeSubdomains = true, string $source = 'manual'): int
    {
        $hostname = strtolower(rtrim(trim($hostname), '.'));
        $reason = trim($reason);
        $source = trim($source);
        if (!filter_var($hostname, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) || filter_var($hostname, FILTER_VALIDATE_IP)
            || $reason === '' || text_length($reason) > 300 || $source === '' || text_length($source) > 80) {
            throw new InvalidArgumentException('Invalid blacklist entry.');
        }
        $now = utc_timestamp();
        $statement = $this->pdo->prepare(<<<'SQL'
            INSERT INTO domain_blacklist (
                hostname, include_subdomains, reason, source, is_enabled, created_at, updated_at
            ) VALUES (
                :hostname, :include_subdomains, :reason, :source, 1, :created_at, :updated_at
            )
            ON CONFLICT(hostname) DO UPDATE SET include_subdomains = excluded.include_subdomains,
                reason = excluded.reason, source = excluded.source, is_enabled = 1,
                updated_at = excluded.updated_at
        SQL);
        $statement->execute([
            'hostname' => $hostname,
            'include_subdomains' => $includeSubdomains ? 1 : 0,
            'reason' => $reason,
            'source' => $source,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $id = (int)$this->pdo->lastInsertId();
        if ($id === 0) {
            $lookup = $this->pdo->prepare('SELECT id FROM domain_blacklist WHERE hostname = :hostname');
            $lookup->execute(['hostname' => $hostname]);
            $id = (int)$lookup->fetchColumn();
        }
        return $id;
    }

    public function blacklist(): array
    {
        return $this->pdo->query('SELECT * FROM domain_blacklist ORDER BY hostname')->fetchAll();
    }

    /** @return array{risk_level: string, score: int, reasons: list<string>} */
    public function evaluateRisk(string $url): array
    {
        $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?? ''));
        $score = 0;
        $reasons = [];
        if ($host === '') {
            return ['risk_level' => 'critical', 'score' => 100, 'reasons' => ['invalid_url']];
        }
        $blacklist = $this->pdo->query('SELECT hostname, include_subdomains FROM domain_blacklist WHERE is_enabled = 1')->fetchAll();
        foreach ($blacklist as $entry) {
            $blocked = strtolower((string)$entry['hostname']);
            if ($host === $blocked || ((int)$entry['include_subdomains'] === 1 && str_ends_with($host, '.' . $blocked))) {
                $score = 100;
                $reasons[] = 'blacklisted_domain';
                break;
            }
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $score += 25;
            $reasons[] = 'ip_literal_host';
        }
        if (str_contains($host, 'xn--')) {
            $score += 20;
            $reasons[] = 'punycode_host';
        }
        if (substr_count($host, '.') >= 4) {
            $score += 10;
            $reasons[] = 'deep_subdomain';
        }
        if (preg_match('/(?:login|verify|secure|account|wallet|password|credential)/i', $url) === 1) {
            $score += 20;
            $reasons[] = 'credential_lure_terms';
        }
        if (strlen($url) > 1024) {
            $score += 10;
            $reasons[] = 'very_long_url';
        }
        $score = min(100, $score);
        $level = $score >= 80 ? 'critical' : ($score >= 50 ? 'high' : ($score >= 20 ? 'medium' : 'low'));
        return ['risk_level' => $level, 'score' => $score, 'reasons' => $reasons];
    }

    public function scanLink(int $linkId): array
    {
        $statement = $this->pdo->prepare('SELECT target_url FROM links WHERE id = :id AND deleted_at IS NULL');
        $statement->execute(['id' => $linkId]);
        $url = $statement->fetchColumn();
        if (!is_string($url)) {
            throw new InvalidArgumentException('Link not found.');
        }
        $risk = $this->evaluateRisk($url);
        $scan = $this->pdo->prepare(<<<'SQL'
            INSERT INTO link_risk_scans (
                link_id, target_url_hash, risk_level, score, reasons_json, scanned_at
            ) VALUES (
                :link_id, :target_url_hash, :risk_level, :score, :reasons_json, :scanned_at
            )
            ON CONFLICT(link_id) DO UPDATE SET target_url_hash = excluded.target_url_hash,
                risk_level = excluded.risk_level, score = excluded.score,
                reasons_json = excluded.reasons_json, scanned_at = excluded.scanned_at
        SQL);
        $scan->execute([
            'link_id' => $linkId,
            'target_url_hash' => hash('sha256', $url),
            'risk_level' => $risk['risk_level'],
            'score' => $risk['score'],
            'reasons_json' => json_encode($risk['reasons'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'scanned_at' => utc_timestamp(),
        ]);
        return $risk;
    }

    public function riskScans(int $limit = 100): array
    {
        $statement = $this->pdo->prepare(<<<'SQL'
            SELECT s.*, l.slug, l.title, l.target_url FROM link_risk_scans s
            JOIN links l ON l.id = s.link_id
            ORDER BY s.score DESC, s.scanned_at DESC LIMIT :record_limit
        SQL);
        $statement->bindValue(':record_limit', max(1, min(500, $limit)), PDO::PARAM_INT);
        $statement->execute();
        return $statement->fetchAll();
    }

    public function processReport(int $reportId, string $action, string $note = ''): bool
    {
        if ($reportId < 1 || !in_array($action, ['review', 'disable_link', 'enable_link', 'dismiss', 'note'], true)
            || text_length(trim($note)) > 1000) {
            throw new InvalidArgumentException('Invalid abuse action.');
        }
        return with_sqlite_retry(function () use ($reportId, $action, $note): bool {
            $this->pdo->exec('BEGIN IMMEDIATE');
            try {
                $find = $this->pdo->prepare('SELECT * FROM abuse_reports WHERE id = :id');
                $find->execute(['id' => $reportId]);
                $report = $find->fetch();
                if (!$report) {
                    $this->pdo->rollBack();
                    return false;
                }
                $now = utc_timestamp();
                $status = $action === 'review' || $action === 'note' ? 'reviewing'
                    : ($action === 'dismiss' ? 'rejected' : 'resolved');
                if (in_array($action, ['disable_link', 'enable_link'], true) && (int)($report['link_id'] ?? 0) > 0) {
                    if (!$this->links instanceof LinkService
                        || !$this->links->setActiveForAbuse((int)$report['link_id'], $action === 'enable_link')) {
                        throw new InvalidArgumentException('The reported link could not be updated.');
                    }
                }
                $this->pdo->prepare(<<<'SQL'
                    UPDATE abuse_reports SET status = :status, updated_at = :updated_at, resolved_at = :resolved_at
                    WHERE id = :id
                SQL)->execute([
                    'status' => $status,
                    'updated_at' => $now,
                    'resolved_at' => in_array($status, ['resolved', 'rejected'], true) ? $now : null,
                    'id' => $reportId,
                ]);
                $this->pdo->prepare(<<<'SQL'
                    INSERT INTO abuse_actions (report_id, link_id, action, note, actor_type, created_at)
                    VALUES (:report_id, :link_id, :action, :note, 'admin', :created_at)
                SQL)->execute([
                    'report_id' => $reportId,
                    'link_id' => $report['link_id'],
                    'action' => $action,
                    'note' => trim($note),
                    'created_at' => $now,
                ]);
                $this->pdo->commit();
                audit_event($this->pdo, $this->config, 'admin', 'abuse_report_action', 'success', 'abuse_report', (string)$reportId, [
                    'action' => $action,
                    'link_id' => $report['link_id'],
                    'note' => limit_text(trim($note), 1000),
                ]);
                return true;
            } catch (Throwable $exception) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $exception;
            }
        });
    }

    private function ruleMatches(array $rule, array $link): bool
    {
        $url = (string)$link['target_url'];
        $value = match ((string)$rule['field']) {
            'host' => strtolower((string)(parse_url($url, PHP_URL_HOST) ?? '')),
            'path' => (string)(parse_url($url, PHP_URL_PATH) ?? ''),
            'title' => (string)$link['title'],
            default => $url,
        };
        $pattern = (string)$rule['pattern'];
        return match ((string)$rule['operator']) {
            'contains' => str_contains(strtolower($value), strtolower($pattern)),
            'prefix' => str_starts_with(strtolower($value), strtolower($pattern)),
            'suffix' => str_ends_with(strtolower($value), strtolower($pattern)),
            'equals' => strtolower($value) === strtolower($pattern),
            'regex' => preg_match('~' . str_replace('~', '\\~', $pattern) . '~iu', $value) === 1,
            default => false,
        };
    }
}
