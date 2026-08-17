<?php

declare(strict_types=1);

const LINKVAULT_SCHEMA_VERSION = 29;

const LINKVAULT_MIGRATION_SHA256 = [
    1 => 'c26e607b6297affd7adffe6da4fe2e7b0220a14e16eb3878a6e3b214d4cf7a95',
    2 => 'd9abada5f3a7e1882b2c9be8418762f5bec72b8f7d271d6292a002d4ae2cf9fb',
    3 => '5a77fc70600b83ef0e85d13a24619a7f3286da328b506a7984b6ab3c03f129dd',
    4 => '824da596ee3873aa4842a157b0a9a19daa36a3f8a523972a3dbc699a204d2347',
    5 => '09079b826894e6f70d94e903931153cde47ec8646c4bd044acf45afe9b81ea87',
    6 => '9ddd31d3095edcd6c9cfe20353adcf2e1167811f47f45a77f411b88c99ed5b12',
    7 => 'aba2b2a0db5cee16ae282742e768b31b6361f947b03693ac3b934710dd3f90de',
    8 => '0fb53af7a59cfeb59ee4136201a764d7770030205d6cf12299bd28ad92d68bd1',
    9 => '15fb5472d35c67dbfc7b56d1aad474fc90ceff99b8a3aaaf9370d2cd68038859',
    10 => '8a86705c2651066c530a1265618119059a9549288281d6843d989b9fe5565813',
    11 => '94fffa6fe6ede013600a7785886ce35ff688a6819d07f9eedc08a285eb0fd5fc',
    12 => 'fa158bbeba9a7013200ff6175cdb684f711b30f3e888ed7ea018c4f27947cbe0',
    13 => '06fc4c448cc402284c3b353de9574e1256bf3b24ddd8716a0b0be055133fe3a7',
    14 => '747c8e125cd2bd74deb76a0c4a319d3d73a32c925a51e28d2cc23c132f256014',
    15 => '615530447b077323ad415136da3f650150bf136b290f37e48ab5a29241272aa0',
    16 => 'e9c7d08dca9558852f17d133c941bbebd770d71b65e16bccd5400c8c8f66cdf4',
    17 => '2786f139a571232aa36f36e0a6145812816b62d892b00f386d0885f9c5883fc5',
    18 => '32bdebb8020dfd33339f8ed2e41ec0d10ee094844418f0d68522b56b3031ed20',
    19 => '42c44a0e56ff56069cdb36dbf953f63080e8f9b6a0c4d13ff1dc14b6476e9dc7',
    20 => '33ffebabbfda45526948c5ce4ff41253612430557703c53b900be7fe72ae1f3a',
    21 => '9c17e6dd77c23832fc287b218c8daad555aeaeb7637a580c631cb69599ad2bce',
    22 => 'e1586330b2e78ce7255d801915ed49f7536837aaf458ac795be0b9058865e833',
    23 => '25ad784e2a4ce95157a62695b77dbe1243b050c14ce834cb82b53944248b3a87',
    24 => '605eb7d20e03a76e89c67c79061bc4a58a1f51f777d5f86717a43e108c89a041',
    25 => 'ea4ec32b0fe0c3d80a7aaba8b37ed829fc23461ae0fcb09f1db3142998c68868',
    26 => '35fc2cfd9379dbd62249a796a17cc825860acecf3c2566753bfce66801da6c7b',
    27 => 'd0917a433cf501443d3bb085abcf4db00a8c0c60a611aece38fc1d2b225df0a4',
    28 => '2c55c7084ab5b48a6fd58c37110c11c1364617c7a03c5e04a15c116cf02b7aef',
    29 => 'e83dafdffea85042af551814832dc1099883e2cbee15a96da253d146d7c08bd8',
];

function linkvault_verified_migration_sql(string $path, int $version): string
{
    $sql = file_get_contents($path);
    if (!is_string($sql) || trim($sql) === '') {
        throw new RuntimeException("Cannot read migration {$version}.");
    }
    $normalized = str_replace(["\r\n", "\r"], "\n", $sql);
    $expected = LINKVAULT_MIGRATION_SHA256[$version] ?? null;
    if (!is_string($expected) || !hash_equals($expected, hash('sha256', $normalized))) {
        throw new RuntimeException("Migration {$version} does not match its frozen checksum.");
    }
    return $sql;
}

function linkvault_quote_identifier(string $identifier): string
{
    return '"' . str_replace('"', '""', $identifier) . '"';
}

function linkvault_schema_problems(PDO $pdo): array
{
    $problems = [];
    $expectedTables = [
        'links' => [
            'id', 'slug', 'target_url', 'title', 'clicks', 'is_active', 'expires_at',
            'deleted_at', 'created_at', 'updated_at', 'last_accessed_at', 'is_favorite',
            'starts_at', 'max_clicks', 'is_one_time', 'one_time_mode', 'campaign_name',
            'campaign_source', 'campaign_medium', 'campaign_content', 'access_password_hash',
            'access_password_reset_required', 'invalid_message', 'fallback_url', 'short_domain_id',
        ],
        'link_daily_stats' => ['link_id', 'accessed_on', 'clicks'],
        'link_tags' => ['link_id', 'tag'],
        'link_status_history' => ['id', 'link_id', 'event', 'from_status', 'to_status', 'created_at'],
        'login_attempts' => ['identifier', 'failures', 'window_started_at', 'last_failed_at', 'locked_until'],
        'create_requests' => ['request_id', 'payload_hash', 'link_id', 'created_at'],
        'healthcheck_probe' => ['id', 'checked_at'],
        'audit_events' => [
            'id', 'created_at', 'actor_type', 'action', 'outcome', 'entity_type',
            'entity_id', 'request_id', 'details_json',
        ],
        'idempotency_requests' => [
            'operation', 'key_hash', 'payload_hash', 'response_status', 'response_body',
            'created_at', 'expires_at',
        ],
        'saved_filters' => [
            'id', 'name', 'view', 'search', 'status', 'sort', 'tag', 'favorites_only',
            'created_at', 'updated_at',
        ],
        'link_daily_stats_archive' => [
            'link_id', 'slug', 'title', 'accessed_on', 'clicks', 'archived_at',
        ],
        'api_tokens' => [
            'id', 'name', 'token_prefix', 'token_hash', 'created_at', 'expires_at',
            'last_used_at', 'use_count', 'revoked_at', 'rotated_from_id', 'rotation_expires_at', 'scopes',
            'quota_requests', 'quota_window_seconds', 'allowed_cidrs',
        ],
        'api_token_usage' => [
            'id', 'token_id', 'token_name', 'token_prefix', 'used_at', 'outcome',
            'endpoint', 'request_id',
        ],
        'api_token_alerts' => [
            'token_id', 'alert_type', 'occurrence_count', 'first_seen_at', 'last_seen_at',
            'last_endpoint', 'last_client_ip',
        ],
        'api_rate_limits' => [
            'identifier', 'request_count', 'window_started_at', 'updated_at',
        ],
        'link_password_attempts' => [
            'link_id', 'client_identifier_hash', 'failures', 'window_started_at',
            'last_failed_at', 'locked_until',
        ],
        'admin_security' => [
            'id', 'totp_secret_encrypted', 'totp_enabled_at', 'totp_last_counter', 'updated_at',
        ],
        'admin_recovery_codes' => [
            'id', 'code_hash', 'created_at', 'used_at',
        ],
        'visitor_hourly_stats' => [
            'link_id', 'accessed_hour', 'country_code', 'device_type', 'browser',
            'operating_system', 'referrer_domain', 'visitor_kind', 'request_kind',
            'campaign_name', 'campaign_source', 'campaign_medium', 'campaign_content', 'clicks',
        ],
        'visitor_daily_stats' => [
            'link_id', 'accessed_on', 'country_code', 'device_type', 'browser',
            'operating_system', 'referrer_domain', 'visitor_kind', 'request_kind',
            'campaign_name', 'campaign_source', 'campaign_medium', 'campaign_content', 'clicks',
        ],
        'analytics_ingest_state' => [
            'source_path', 'inode', 'byte_offset', 'updated_at', 'checkpoint_hash',
        ],
        'link_campaign_snapshots' => [
            'link_id', 'effective_at_ms', 'campaign_name', 'campaign_source',
            'campaign_medium', 'campaign_content',
        ],
        'analytics_alert_state' => [
            'anomaly_type', 'is_active', 'last_notified_at', 'last_value', 'updated_at',
        ],
        'target_health' => [
            'link_id', 'target_url_hash', 'state', 'reason', 'checked_at', 'next_check_at',
            'last_healthy_at', 'http_status', 'latency_ms', 'effective_url', 'redirect_count',
            'redirect_state', 'consecutive_failures', 'redirect_chain_json', 'ignored_at', 'ignored_reason',
        ],
        'bulk_operations' => [
            'id', 'action', 'parameters_json', 'items_json', 'status', 'reversible',
            'selected_count', 'eligible_count', 'changed_count', 'result_json',
            'created_at', 'preview_expires_at', 'applied_at', 'undo_expires_at',
            'undone_at', 'retain_until',
        ],
        'saved_analytics_views' => [
            'id', 'name', 'request_json', 'created_at', 'updated_at',
        ],
        'short_domains' => [
            'id', 'hostname', 'verification_token', 'verified_at', 'is_enabled',
            'brand_name', 'brand_tagline', 'brand_theme', 'created_at', 'updated_at',
            'brand_color', 'logo_url', 'favicon_url', 'invalid_page_title', 'invalid_page_message',
        ],
        'webhook_outbox' => [
            'event_id', 'event_type', 'link_id', 'dedupe_key', 'payload_json', 'status',
            'attempts', 'available_at', 'leased_until', 'last_error', 'created_at', 'delivered_at', 'replay_count',
        ],
        'webhook_delivery_attempts' => [
            'id', 'event_id', 'attempt_number', 'attempted_at', 'http_status', 'duration_ms', 'error',
        ],
        'link_presets' => ['id', 'name', 'values_json', 'created_at', 'updated_at'],
        'link_aliases' => ['alias', 'link_id', 'created_at'],
        'notification_claims' => [
            'notification_type', 'dedupe_key', 'leased_until', 'completed_at', 'last_error', 'updated_at',
        ],
        'admin_notifications' => [
            'id', 'notification_key', 'notification_type', 'severity', 'title', 'body',
            'entity_type', 'entity_id', 'action_url', 'created_at', 'read_at', 'dismissed_at',
        ],
        'short_domain_retirement_jobs' => [
            'id', 'source_id', 'source_hostname', 'destination_id', 'status', 'total_count',
            'migrated_count', 'attempt_count', 'last_error', 'created_at', 'updated_at', 'completed_at',
        ],
        'analytics_export_jobs' => [
            'id', 'owner_hash', 'report', 'request_json', 'status', 'attempts', 'available_at',
            'leased_until', 'lease_token', 'row_count', 'artifact_name', 'download_name', 'size_bytes',
            'last_error', 'created_at', 'started_at', 'completed_at', 'expires_at',
        ],
        'analytics_daily_dimensions' => [
            'link_id', 'accessed_on', 'country_code', 'device_type', 'browser',
            'operating_system', 'referrer_domain', 'visitor_kind', 'request_kind',
            'campaign_name', 'campaign_source', 'campaign_medium', 'campaign_content', 'clicks',
        ],
        'analytics_rollup_state' => [
            'id', 'status', 'checkpoint_date', 'last_error', 'updated_at', 'completed_at',
        ],
        'tag_rules' => [
            'id', 'name', 'field', 'operator', 'pattern', 'tags_json', 'priority',
            'is_enabled', 'created_at', 'updated_at',
        ],
        'funnels' => ['id', 'name', 'stages_json', 'created_at', 'updated_at'],
        'conversion_events' => [
            'id', 'event_id', 'token_id', 'link_id', 'event_name', 'occurred_at',
            'value_minor', 'currency', 'metadata_json', 'idempotency_key_hash',
            'payload_hash', 'created_at',
        ],
        'abuse_reports' => [
            'id', 'public_id', 'link_id', 'reported_url', 'reason', 'details',
            'reporter_contact', 'reporter_hash', 'status', 'created_at', 'updated_at', 'resolved_at',
        ],
        'domain_blacklist' => [
            'id', 'hostname', 'include_subdomains', 'reason', 'source', 'is_enabled',
            'created_at', 'updated_at',
        ],
        'link_risk_scans' => [
            'link_id', 'target_url_hash', 'risk_level', 'score', 'reasons_json', 'scanned_at',
        ],
        'abuse_actions' => [
            'id', 'report_id', 'link_id', 'action', 'note', 'actor_type', 'created_at',
        ],
        'links_fts' => ['title', 'slug', 'target_url'],
    ];
    $expectedPrimaryKeys = [
        'links' => ['id'],
        'link_daily_stats' => ['link_id', 'accessed_on'],
        'link_tags' => ['link_id', 'tag'],
        'link_status_history' => ['id'],
        'login_attempts' => ['identifier'],
        'create_requests' => ['request_id'],
        'healthcheck_probe' => ['id'],
        'audit_events' => ['id'],
        'idempotency_requests' => ['operation', 'key_hash'],
        'saved_filters' => ['id'],
        'link_daily_stats_archive' => ['link_id', 'accessed_on'],
        'api_tokens' => ['id'],
        'api_token_usage' => ['id'],
        'api_token_alerts' => ['token_id', 'alert_type'],
        'api_rate_limits' => ['identifier'],
        'link_password_attempts' => ['link_id', 'client_identifier_hash'],
        'admin_security' => ['id'],
        'admin_recovery_codes' => ['id'],
        'visitor_hourly_stats' => [
            'link_id', 'accessed_hour', 'country_code', 'device_type', 'browser',
            'operating_system', 'referrer_domain', 'visitor_kind', 'request_kind',
            'campaign_name', 'campaign_source', 'campaign_medium', 'campaign_content',
        ],
        'visitor_daily_stats' => [
            'link_id', 'accessed_on', 'country_code', 'device_type', 'browser',
            'operating_system', 'referrer_domain', 'visitor_kind', 'request_kind',
            'campaign_name', 'campaign_source', 'campaign_medium', 'campaign_content',
        ],
        'analytics_ingest_state' => ['source_path'],
        'link_campaign_snapshots' => ['link_id', 'effective_at_ms'],
        'analytics_alert_state' => ['anomaly_type'],
        'target_health' => ['link_id'],
        'bulk_operations' => ['id'],
        'saved_analytics_views' => ['id'],
        'short_domains' => ['id'],
        'webhook_outbox' => ['event_id'],
        'webhook_delivery_attempts' => ['id'],
        'link_presets' => ['id'],
        'link_aliases' => ['alias'],
        'notification_claims' => ['notification_type', 'dedupe_key'],
        'admin_notifications' => ['id'],
        'short_domain_retirement_jobs' => ['id'],
        'analytics_export_jobs' => ['id'],
        'analytics_daily_dimensions' => [
            'link_id', 'accessed_on', 'country_code', 'device_type', 'browser',
            'operating_system', 'referrer_domain', 'visitor_kind', 'request_kind',
            'campaign_name', 'campaign_source', 'campaign_medium', 'campaign_content',
        ],
        'analytics_rollup_state' => ['id'],
        'tag_rules' => ['id'],
        'funnels' => ['id'],
        'conversion_events' => ['id'],
        'abuse_reports' => ['id'],
        'domain_blacklist' => ['id'],
        'link_risk_scans' => ['link_id'],
        'abuse_actions' => ['id'],
    ];

    $tableExists = $pdo->prepare(
        "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :name"
    );
    foreach ($expectedTables as $table => $expectedColumns) {
        $tableExists->execute(['name' => $table]);
        if (!$tableExists->fetchColumn()) {
            $problems[] = "missing table {$table}";
            continue;
        }

        $actualColumns = [];
        $actualPrimaryKey = [];
        foreach ($pdo->query('PRAGMA table_info(' . linkvault_quote_identifier($table) . ')') as $column) {
            $actualColumns[] = (string)$column['name'];
            if ((int)$column['pk'] > 0) {
                $actualPrimaryKey[(int)$column['pk']] = (string)$column['name'];
            }
        }
        foreach (array_diff($expectedColumns, $actualColumns) as $column) {
            $problems[] = "missing column {$table}.{$column}";
        }
        if (isset($expectedPrimaryKeys[$table])) {
            ksort($actualPrimaryKey, SORT_NUMERIC);
            if (array_values($actualPrimaryKey) !== $expectedPrimaryKeys[$table]) {
                $problems[] = "invalid primary key {$table}";
            }
        }
    }

    $expectedIndexes = [
        'links_state_id_idx' => ['links', ['deleted_at', 'id']],
        'link_daily_stats_date_idx' => ['link_daily_stats', ['accessed_on']],
        'login_attempts_expiry_idx' => ['login_attempts', ['last_failed_at', 'locked_until']],
        'links_target_url_idx' => ['links', ['target_url']],
        'links_favorite_id_idx' => ['links', ['deleted_at', 'is_favorite', 'id']],
        'link_tags_tag_idx' => ['link_tags', ['tag', 'link_id']],
        'link_status_history_link_date_idx' => ['link_status_history', ['link_id', 'created_at']],
        'create_requests_created_idx' => ['create_requests', ['created_at']],
        'audit_events_created_idx' => ['audit_events', ['created_at', 'id']],
        'audit_events_outcome_created_idx' => ['audit_events', ['outcome', 'created_at']],
        'audit_events_entity_created_idx' => ['audit_events', ['entity_type', 'entity_id', 'created_at']],
        'idempotency_requests_expires_idx' => ['idempotency_requests', ['expires_at']],
        'link_daily_stats_archive_date_idx' => ['link_daily_stats_archive', ['accessed_on']],
        'api_tokens_state_expiry_idx' => ['api_tokens', ['revoked_at', 'expires_at']],
        'api_token_usage_used_idx' => ['api_token_usage', ['used_at', 'id']],
        'api_token_usage_token_used_idx' => ['api_token_usage', ['token_id', 'used_at', 'id']],
        'api_rate_limits_updated_idx' => ['api_rate_limits', ['updated_at']],
        'link_password_attempts_cleanup_idx' => ['link_password_attempts', ['last_failed_at', 'locked_until']],
        'api_tokens_rotation_expiry_idx' => ['api_tokens', ['rotation_expires_at']],
        'admin_recovery_codes_used_idx' => ['admin_recovery_codes', ['used_at', 'id']],
        'visitor_hourly_stats_hour_idx' => ['visitor_hourly_stats', ['accessed_hour']],
        'visitor_hourly_stats_link_hour_idx' => ['visitor_hourly_stats', ['link_id', 'accessed_hour']],
        'visitor_daily_stats_date_idx' => ['visitor_daily_stats', ['accessed_on']],
        'visitor_daily_stats_link_date_idx' => ['visitor_daily_stats', ['link_id', 'accessed_on']],
        'target_health_due_idx' => ['target_health', ['next_check_at', 'link_id']],
        'target_health_state_checked_idx' => ['target_health', ['state', 'checked_at', 'link_id']],
        'bulk_operations_retain_idx' => ['bulk_operations', ['retain_until']],
        'bulk_operations_status_created_idx' => ['bulk_operations', ['status', 'created_at']],
        'short_domains_state_idx' => ['short_domains', ['is_enabled', 'verified_at', 'hostname']],
        'links_short_domain_id_idx' => ['links', ['short_domain_id', 'id']],
        'webhook_outbox_delivery_idx' => ['webhook_outbox', ['status', 'available_at', 'leased_until', 'created_at']],
        'webhook_delivery_attempts_event_idx' => ['webhook_delivery_attempts', ['event_id', 'attempted_at', 'id']],
        'link_aliases_link_idx' => ['link_aliases', ['link_id', 'created_at']],
        'notification_claims_cleanup_idx' => ['notification_claims', ['completed_at', 'leased_until']],
        'admin_notifications_inbox_idx' => ['admin_notifications', ['dismissed_at', 'read_at', 'created_at', 'id']],
        'short_domain_retirement_jobs_status_idx' => ['short_domain_retirement_jobs', ['status', 'updated_at', 'id']],
        'analytics_export_jobs_queue_idx' => ['analytics_export_jobs', ['status', 'available_at', 'leased_until', 'created_at']],
        'analytics_export_jobs_owner_idx' => ['analytics_export_jobs', ['owner_hash', 'created_at']],
        'analytics_daily_dimensions_date_idx' => ['analytics_daily_dimensions', ['accessed_on']],
        'analytics_daily_dimensions_link_date_idx' => ['analytics_daily_dimensions', ['link_id', 'accessed_on']],
        'links_analytics_options_idx' => ['links', ['deleted_at', 'campaign_name', 'id']],
        'tag_rules_enabled_priority_idx' => ['tag_rules', ['is_enabled', 'priority', 'id']],
        'conversion_events_link_time_idx' => ['conversion_events', ['link_id', 'occurred_at', 'id']],
        'conversion_events_name_time_idx' => ['conversion_events', ['event_name', 'occurred_at', 'id']],
        'abuse_reports_status_created_idx' => ['abuse_reports', ['status', 'created_at', 'id']],
        'abuse_reports_link_created_idx' => ['abuse_reports', ['link_id', 'created_at', 'id']],
        'domain_blacklist_enabled_host_idx' => ['domain_blacklist', ['is_enabled', 'hostname']],
        'link_risk_scans_level_idx' => ['link_risk_scans', ['risk_level', 'score', 'scanned_at']],
        'abuse_actions_report_created_idx' => ['abuse_actions', ['report_id', 'created_at', 'id']],
    ];
    $indexExists = $pdo->prepare(
        "SELECT tbl_name FROM sqlite_master WHERE type = 'index' AND name = :name"
    );
    foreach ($expectedIndexes as $index => [$expectedTable, $expectedColumns]) {
        $indexExists->execute(['name' => $index]);
        $actualTable = $indexExists->fetchColumn();
        if ($actualTable === false) {
            $problems[] = "missing index {$index}";
            continue;
        }

        $actualColumns = [];
        foreach ($pdo->query('PRAGMA index_info(' . linkvault_quote_identifier($index) . ')') as $column) {
            $actualColumns[] = (string)$column['name'];
        }
        if ($actualTable !== $expectedTable || $actualColumns !== $expectedColumns) {
            $problems[] = "invalid index {$index}";
        }
    }

    if (!in_array('missing table links', $problems, true)) {
        $hasUniqueSlug = false;
        foreach ($pdo->query('PRAGMA index_list("links")') as $index) {
            if ((int)$index['unique'] !== 1 || (int)($index['partial'] ?? 0) !== 0) {
                continue;
            }
            $columns = [];
            foreach ($pdo->query(
                'PRAGMA index_info(' . linkvault_quote_identifier((string)$index['name']) . ')'
            ) as $column) {
                $columns[] = (string)$column['name'];
            }
            if ($columns === ['slug']) {
                $hasUniqueSlug = true;
                break;
            }
        }
        if (!$hasUniqueSlug) {
            $problems[] = 'missing unique constraint links.slug';
        }
    }

    foreach ([
        'link_daily_stats', 'link_tags', 'link_status_history', 'create_requests',
        'visitor_hourly_stats', 'visitor_daily_stats', 'analytics_daily_dimensions', 'link_campaign_snapshots',
        'link_password_attempts',
        'target_health', 'link_aliases', 'conversion_events', 'link_risk_scans',
    ] as $childTable) {
        $hasCascade = false;
        foreach ($pdo->query('PRAGMA foreign_key_list(' . linkvault_quote_identifier($childTable) . ')') as $foreignKey) {
            if ((string)($foreignKey['table'] ?? '') === 'links'
                && (string)($foreignKey['from'] ?? '') === 'link_id'
                && strtoupper((string)($foreignKey['on_delete'] ?? '')) === 'CASCADE') {
                $hasCascade = true;
                break;
            }
        }
        if (!$hasCascade) {
            $problems[] = "missing cascade foreign key {$childTable}.link_id";
        }
    }

    $hasWebhookAttemptCascade = false;
    foreach ($pdo->query('PRAGMA foreign_key_list("webhook_delivery_attempts")') as $foreignKey) {
        if ((string)($foreignKey['table'] ?? '') === 'webhook_outbox'
            && (string)($foreignKey['from'] ?? '') === 'event_id'
            && (string)($foreignKey['to'] ?? '') === 'event_id'
            && strtoupper((string)($foreignKey['on_delete'] ?? '')) === 'CASCADE') {
            $hasWebhookAttemptCascade = true;
            break;
        }
    }
    if (!$hasWebhookAttemptCascade) {
        $problems[] = 'missing cascade foreign key webhook_delivery_attempts.event_id';
    }

    foreach ([
        'links_fts_insert', 'links_fts_delete', 'links_fts_update',
        'link_campaign_snapshot_insert', 'link_campaign_snapshot_update',
        'links_password_reset_insert_guard', 'links_password_reset_activation_guard',
        'link_aliases_slug_collision_insert', 'links_alias_collision_insert', 'links_alias_collision_update',
    ] as $trigger) {
        $statement = $pdo->prepare(
            "SELECT 1 FROM sqlite_master WHERE type = 'trigger' AND name = :name"
        );
        $statement->execute(['name' => $trigger]);
        if (!$statement->fetchColumn()) {
            $problems[] = "missing trigger {$trigger}";
        }
    }

    return $problems;
}
