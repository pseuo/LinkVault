<?php

require_once __DIR__ . '/lib/maintenance_policy.php';
require_once __DIR__ . '/lib/runtime_paths.php';

$backupIntegrityCheckInterval = getenv('LINKVAULT_BACKUP_INTEGRITY_CHECK_INTERVAL_SECONDS');
$backupCommandTimeoutSeconds = getenv('LINKVAULT_BACKUP_COMMAND_TIMEOUT_SECONDS');
$apiTokenUsageRetentionDays = getenv('LINKVAULT_API_TOKEN_USAGE_RETENTION_DAYS');
$apiTokenRetentionDays = getenv('LINKVAULT_API_TOKEN_RETENTION_DAYS');
$sqliteSlowQueryMs = getenv('LINKVAULT_SQLITE_SLOW_QUERY_MS');
$analyticsMaterializeMaxRows = getenv('LINKVAULT_ANALYTICS_MATERIALIZE_MAX_ROWS');
$analyticsReportCacheSeconds = getenv('LINKVAULT_ANALYTICS_REPORT_CACHE_SECONDS');
$targetHealthMaxRedirects = getenv('LINKVAULT_TARGET_HEALTH_MAX_REDIRECTS');
$businessSummaryEmailFrom = trim((string)(getenv('LINKVAULT_BUSINESS_SUMMARY_EMAIL_FROM') ?: ''));
$businessSummaryRecipients = static function (string $name): array {
    $recipients = [];
    foreach (explode(',', (string)(getenv($name) ?: '')) as $recipient) {
        $recipient = trim($recipient);
        if ($recipient !== '' && filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            $recipients[$recipient] = true;
        }
    }
    return array_keys($recipients);
};
$environment = strtolower(trim((string)(getenv('LINKVAULT_ENV') ?: 'development')));
$production = $environment === 'production';
$stateRoot = $production ? '/var/lib/linkvault' : __DIR__ . '/data';
$logRoot = $production ? '/var/log/linkvault' : __DIR__ . '/data';
$backupRoot = $production ? '/var/backups/linkvault' : __DIR__ . '/backups';

return [
    'environment' => $environment,
    'release_version' => getenv('LINKVAULT_RELEASE_VERSION') ?: 'V2.0.0',
    'build_time' => getenv('LINKVAULT_BUILD_TIME')
        ?: gmdate('Y-m-d\TH:i:s\Z', (int)(filemtime(__FILE__) ?: time())),
    // Separate release notes with "|". The rollback version should identify the last verified deploy.
    'release_changelog' => getenv('LINKVAULT_RELEASE_CHANGELOG') ?: '',
    'release_rollback_version' => getenv('LINKVAULT_RELEASE_ROLLBACK_VERSION') ?: '',

    // The application refuses to serve requests until a strong password is set.
    'admin_password' => getenv('LINKVAULT_ADMIN_PASSWORD') ?: '',

    // Required for every non-loopback deployment; incoming Host must match it.
    // Example: 'https://s.example.com'
    'base_url' => getenv('LINKVAULT_BASE_URL') ?: '',
    // Public contact shown in the browser extension privacy policy.
    'browser_extension_privacy_contact' => trim((string)(getenv('LINKVAULT_BROWSER_EXTENSION_PRIVACY_CONTACT') ?: '')),
    // Comma-separated proxy IPs allowed to provide X-Forwarded-* headers.
    'trusted_proxies' => array_values(array_filter(array_map(
        'trim',
        explode(',', getenv('LINKVAULT_TRUSTED_PROXIES') ?: '')
    ))),

    'database_path' => getenv('LINKVAULT_DATABASE_PATH') ?: $stateRoot . '/linkvault.sqlite',
    'application_log_path' => getenv('LINKVAULT_LOG_PATH') ?: $logRoot . '/application.log',
    'backup_directory' => getenv('LINKVAULT_BACKUP_DIR') ?: $backupRoot,
    'backup_status_directory' => getenv('LINKVAULT_BACKUP_STATUS_DIR') ?: '',
    'sqlite3_binary' => getenv('LINKVAULT_SQLITE3_BIN') ?: 'sqlite3',
    'backup_retention_days' => (int)(getenv('LINKVAULT_BACKUP_RETENTION_DAYS') ?: 14),
    'backup_command_timeout_seconds' => (int)($backupCommandTimeoutSeconds === false
        ? 900
        : $backupCommandTimeoutSeconds),
    'backup_max_age_seconds' => (int)(getenv('LINKVAULT_BACKUP_MAX_AGE_SECONDS') ?: 8 * 3600),
    'backup_integrity_check_interval_seconds' => (int)($backupIntegrityCheckInterval === false
        ? 300
        : $backupIntegrityCheckInterval),
    'backup_remote_required' => filter_var(
        getenv('LINKVAULT_BACKUP_REMOTE_REQUIRED') ?: 'false',
        FILTER_VALIDATE_BOOL
    ),
    'backup_age_binary' => getenv('LINKVAULT_AGE_BIN') ?: 'age',
    'backup_age_recipient' => getenv('LINKVAULT_BACKUP_AGE_RECIPIENT') ?: '',
    'backup_rclone_binary' => getenv('LINKVAULT_RCLONE_BIN') ?: 'rclone',
    'backup_rclone_remote' => getenv('LINKVAULT_BACKUP_RCLONE_REMOTE') ?: '',
    'health_min_free_bytes' => (int)(getenv('LINKVAULT_HEALTH_MIN_FREE_BYTES') ?: 128 * 1024 * 1024),
    'restore_drill_directory' => getenv('LINKVAULT_RESTORE_DRILL_DIR')
        ?: ($production ? '/var/lib/linkvault-restore-drill' : __DIR__ . '/restore-drill'),
    'restore_drill_source' => strtolower((string)(getenv('LINKVAULT_RESTORE_DRILL_SOURCE') ?: 'local')),
    'restore_age_identity' => getenv('LINKVAULT_RESTORE_AGE_IDENTITY') ?: '',
    'restore_rclone_config' => getenv('LINKVAULT_RESTORE_RCLONE_CONFIG') ?: '',
    'restore_drill_max_age_seconds' => (int)(getenv('LINKVAULT_RESTORE_DRILL_MAX_AGE_SECONDS') ?: 8 * 86400),
    'status_anomaly_window_seconds' => (int)(getenv('LINKVAULT_STATUS_ANOMALY_WINDOW_SECONDS') ?: 86400),
    // Required to expose /metrics. Use a dedicated secret, not an API or administrator token.
    'metrics_token' => getenv('LINKVAULT_METRICS_TOKEN') ?: '',
    'slug_length' => 6,
    'target_url_max_length' => 2048,
    'import_max_bytes' => 2 * 1024 * 1024,
    'import_max_records' => 5000,
    'import_batch_size' => 100,
    'api_max_bytes' => 64 * 1024,
    // Authenticated business quota is isolated per managed Token (plus one legacy-token bucket).
    'api_rate_limit_requests' => (int)(getenv('LINKVAULT_API_RATE_LIMIT_REQUESTS') ?: 60),
    'api_rate_limit_window_seconds' => (int)(getenv('LINKVAULT_API_RATE_LIMIT_WINDOW_SECONDS') ?: 60),
    'conversion_signature_tolerance_seconds' => (int)(getenv('LINKVAULT_CONVERSION_SIGNATURE_TOLERANCE_SECONDS') ?: 300),
    'abuse_report_quota_requests' => (int)(getenv('LINKVAULT_ABUSE_REPORT_QUOTA_REQUESTS') ?: 5),
    'abuse_report_quota_window_seconds' => (int)(getenv('LINKVAULT_ABUSE_REPORT_QUOTA_WINDOW_SECONDS') ?: 3600),
    'risk_scan_batch_size' => (int)(getenv('LINKVAULT_RISK_SCAN_BATCH_SIZE') ?: 100),
    'sqlite_busy_timeout_ms' => (int)(getenv('LINKVAULT_SQLITE_BUSY_TIMEOUT_MS') ?: 5000),
    // SQLite interprets a negative cache_size as KiB rather than a page count.
    'sqlite_cache_size_kib' => (int)(getenv('LINKVAULT_SQLITE_CACHE_SIZE_KIB') ?: 32768),
    'sqlite_slow_query_ms' => (int)($sqliteSlowQueryMs === false ? 250 : $sqliteSlowQueryMs),
    // Redirect statistics are best-effort and must not hold a worker for admin-sized lock budgets.
    'redirect_busy_timeout_ms' => (int)(getenv('LINKVAULT_REDIRECT_BUSY_TIMEOUT_MS') ?: 250),
    'redirect_retry_attempts' => (int)(getenv('LINKVAULT_REDIRECT_RETRY_ATTEMPTS') ?: 2),
    'health_busy_timeout_ms' => (int)(getenv('LINKVAULT_HEALTH_BUSY_TIMEOUT_MS') ?: 100),

    // Synthetic probes use HEAD so the operational canary does not affect click totals.
    'canary_enabled' => filter_var(
        getenv('LINKVAULT_CANARY_ENABLED') ?: 'false',
        FILTER_VALIDATE_BOOL
    ),
    'canary_slug' => getenv('LINKVAULT_CANARY_SLUG') ?: 'monitor-canary',
    'canary_target_url' => getenv('LINKVAULT_CANARY_TARGET_URL') ?: '',
    'synthetic_status_path' => getenv('LINKVAULT_SYNTHETIC_STATUS_PATH')
        ?: $stateRoot . '/.synthetic-monitor-state.json',
    'synthetic_status_max_age_seconds' => (int)(getenv('LINKVAULT_SYNTHETIC_STATUS_MAX_AGE_SECONDS') ?: 900),

    // Optional legacy create-only Bearer token. Managed tokens can be created in the status center.
    'api_token' => getenv('LINKVAULT_API_TOKEN') ?: '',
    'idempotency_retention_seconds' => (int)(getenv('LINKVAULT_IDEMPOTENCY_RETENTION_SECONDS') ?: 86400),
    'api_token_rotation_overlap_seconds' => (int)(getenv('LINKVAULT_API_TOKEN_ROTATION_OVERLAP_SECONDS') ?: 900),
    'api_token_rotation_max_overlap_seconds' => (int)(getenv('LINKVAULT_API_TOKEN_ROTATION_MAX_OVERLAP_SECONDS') ?: 86400),
    'api_token_usage_retention_days' => (int)($apiTokenUsageRetentionDays === false
        ? 90
        : $apiTokenUsageRetentionDays),
    'api_token_retention_days' => (int)($apiTokenRetentionDays === false ? 180 : $apiTokenRetentionDays),
    'api_token_failed_usage_max_records' => (int)(getenv('LINKVAULT_API_TOKEN_FAILED_USAGE_MAX_RECORDS') ?: 1000),

    // Required only when TOTP is enabled. Use an independent random value of at least 32 characters.
    'security_key' => getenv('LINKVAULT_SECURITY_KEY') ?: '',
    'totp_issuer' => 'LinkVault',

    'audit_retention_days' => (int)(getenv('LINKVAULT_AUDIT_RETENTION_DAYS') ?: 180),
    'maintenance_batch_size' => (int)(getenv('LINKVAULT_MAINTENANCE_BATCH_SIZE') ?: 500),
    'domain_retirement_batch_size' => (int)(getenv('LINKVAULT_DOMAIN_RETIREMENT_BATCH_SIZE') ?: 200),
    'domain_retirement_max_batches' => (int)(getenv('LINKVAULT_DOMAIN_RETIREMENT_MAX_BATCHES') ?: 10),

    // Daily maintenance notifications are disabled when the webhook URL is empty.
    'maintenance_webhook_url' => getenv('LINKVAULT_MAINTENANCE_WEBHOOK_URL') ?: '',
    'maintenance_webhook_bearer_token' => getenv('LINKVAULT_MAINTENANCE_WEBHOOK_BEARER_TOKEN') ?: '',
    'maintenance_thresholds' => linkvault_maintenance_thresholds_from_environment(),
    'lifecycle_webhook_url' => getenv('LINKVAULT_LIFECYCLE_WEBHOOK_URL') ?: '',
    'lifecycle_webhook_bearer_token' => getenv('LINKVAULT_LIFECYCLE_WEBHOOK_BEARER_TOKEN') ?: '',
    'lifecycle_webhook_signing_secret' => getenv('LINKVAULT_LIFECYCLE_WEBHOOK_SIGNING_SECRET') ?: '',
    'lifecycle_webhook_retention_days' => (int)(getenv('LINKVAULT_LIFECYCLE_WEBHOOK_RETENTION_DAYS') ?: 180),
    'lifecycle_webhook_attempt_retention_days' => (int)(getenv('LINKVAULT_LIFECYCLE_WEBHOOK_ATTEMPT_RETENTION_DAYS') ?: 90),

    // Cumulative links.clicks is never pruned. Only per-day rows follow this policy.
    'daily_stats_retention_days' => (int)(getenv('LINKVAULT_DAILY_STATS_RETENTION_DAYS') ?: 365),
    'daily_stats_retention_mode' => strtolower((string)(getenv('LINKVAULT_DAILY_STATS_RETENTION_MODE') ?: 'archive')),
    'daily_stats_archive_retention_days' => (int)(getenv('LINKVAULT_DAILY_STATS_ARCHIVE_RETENTION_DAYS') ?: 1095),

    // Proxy JSON logs are classified in batches; SQLite stores aggregates and the ingest position.
    'analytics_log_path' => getenv('LINKVAULT_ANALYTICS_LOG_PATH') ?: $logRoot . '/analytics-access.log',
    // Operational status marker only; ingestion does not read its offset after migration.
    'analytics_state_path' => getenv('LINKVAULT_ANALYTICS_STATE_PATH') ?: $stateRoot . '/.analytics-ingest-state.json',
    'analytics_status_max_age_seconds' => (int)(getenv('LINKVAULT_ANALYTICS_STATUS_MAX_AGE_SECONDS') ?: 900),
    'analytics_raw_log_retention_days' => (int)(getenv('LINKVAULT_ANALYTICS_RAW_LOG_RETENTION_DAYS') ?: 30),
    'analytics_hourly_retention_days' => (int)(getenv('LINKVAULT_ANALYTICS_HOURLY_RETENTION_DAYS') ?: 90),
    'analytics_retention_days' => (int)(getenv('LINKVAULT_ANALYTICS_RETENTION_DAYS') ?: 365),
    'analytics_batch_max_lines' => (int)(getenv('LINKVAULT_ANALYTICS_BATCH_MAX_LINES') ?: 100000),
    'analytics_materialize_max_rows' => (int)($analyticsMaterializeMaxRows === false
        ? 250000
        : $analyticsMaterializeMaxRows),
    'analytics_report_cache_seconds' => (int)($analyticsReportCacheSeconds === false
        ? 60
        : $analyticsReportCacheSeconds),
    'analytics_report_cache_directory' => getenv('LINKVAULT_ANALYTICS_REPORT_CACHE_DIR')
        ?: $stateRoot . '/analytics-report-cache',
    'analytics_anomaly_spike_factor' => (float)(getenv('LINKVAULT_ANALYTICS_ANOMALY_SPIKE_FACTOR') ?: 3),
    'analytics_anomaly_min_requests' => (int)(getenv('LINKVAULT_ANALYTICS_ANOMALY_MIN_REQUESTS') ?: 20),
    'analytics_anomaly_zero_hours' => (int)(getenv('LINKVAULT_ANALYTICS_ANOMALY_ZERO_HOURS') ?: 6),
    'analytics_anomaly_bot_ratio' => (float)(getenv('LINKVAULT_ANALYTICS_ANOMALY_BOT_RATIO') ?: 0.8),
    'analytics_anomaly_cooldown_seconds' => (int)(getenv('LINKVAULT_ANALYTICS_ANOMALY_COOLDOWN_SECONDS') ?: 21600),
    'analytics_export_directory' => getenv('LINKVAULT_ANALYTICS_EXPORT_DIR') ?: $stateRoot . '/analytics-exports',
    'analytics_export_retention_hours' => (int)(getenv('LINKVAULT_ANALYTICS_EXPORT_RETENTION_HOURS') ?: 24),
    'analytics_export_lease_seconds' => (int)(getenv('LINKVAULT_ANALYTICS_EXPORT_LEASE_SECONDS') ?: 900),
    'analytics_export_worker_batch_size' => (int)(getenv('LINKVAULT_ANALYTICS_EXPORT_WORKER_BATCH_SIZE') ?: 5),
    'analytics_export_max_rows' => (int)(getenv('LINKVAULT_ANALYTICS_EXPORT_MAX_ROWS') ?: 500000),
    'alert_webhook_url' => getenv('LINKVAULT_ALERT_WEBHOOK_URL') ?: '',
    'alert_webhook_bearer_token' => getenv('LINKVAULT_ALERT_BEARER_TOKEN') ?: '',
    // A period is delivered only after it fully closes. Recipients are comma-separated email addresses.
    'business_summary_weekly_webhook_url' => getenv('LINKVAULT_BUSINESS_SUMMARY_WEEKLY_WEBHOOK_URL') ?: '',
    'business_summary_weekly_webhook_bearer_token' => getenv('LINKVAULT_BUSINESS_SUMMARY_WEEKLY_WEBHOOK_BEARER_TOKEN') ?: '',
    'business_summary_weekly_email_recipients' => $businessSummaryRecipients('LINKVAULT_BUSINESS_SUMMARY_WEEKLY_EMAIL_TO'),
    'business_summary_monthly_webhook_url' => getenv('LINKVAULT_BUSINESS_SUMMARY_MONTHLY_WEBHOOK_URL') ?: '',
    'business_summary_monthly_webhook_bearer_token' => getenv('LINKVAULT_BUSINESS_SUMMARY_MONTHLY_WEBHOOK_BEARER_TOKEN') ?: '',
    'business_summary_monthly_email_recipients' => $businessSummaryRecipients('LINKVAULT_BUSINESS_SUMMARY_MONTHLY_EMAIL_TO'),
    'business_summary_email_from' => filter_var($businessSummaryEmailFrom, FILTER_VALIDATE_EMAIL)
        ? $businessSummaryEmailFrom : '',

    // Target checks run only from the scheduled CLI worker, never from redirect requests.
    'target_health_enabled' => filter_var(
        getenv('LINKVAULT_TARGET_HEALTH_ENABLED') ?: 'false',
        FILTER_VALIDATE_BOOL
    ),
    'target_health_interval_seconds' => (int)(getenv('LINKVAULT_TARGET_HEALTH_INTERVAL_SECONDS') ?: 900),
    'target_health_batch_size' => (int)(getenv('LINKVAULT_TARGET_HEALTH_BATCH_SIZE') ?: 50),
    'target_health_connect_timeout_ms' => (int)(getenv('LINKVAULT_TARGET_HEALTH_CONNECT_TIMEOUT_MS') ?: 3000),
    'target_health_hop_timeout_ms' => (int)(getenv('LINKVAULT_TARGET_HEALTH_HOP_TIMEOUT_MS') ?: 8000),
    'target_health_total_timeout_ms' => (int)(getenv('LINKVAULT_TARGET_HEALTH_TOTAL_TIMEOUT_MS') ?: 30000),
    'target_health_max_redirects' => (int)($targetHealthMaxRedirects === false ? 5 : $targetHealthMaxRedirects),
    'target_health_header_max_bytes' => (int)(getenv('LINKVAULT_TARGET_HEALTH_HEADER_MAX_BYTES') ?: 32768),
    'target_health_body_max_bytes' => (int)(getenv('LINKVAULT_TARGET_HEALTH_BODY_MAX_BYTES') ?: 65536),
    'target_health_allowed_ports' => array_values(array_filter(array_map(
        'trim',
        explode(',', getenv('LINKVAULT_TARGET_HEALTH_ALLOWED_PORTS') ?: '80,443')
    ), static fn (string $value): bool => $value !== '')),
    'target_health_status_path' => getenv('LINKVAULT_TARGET_HEALTH_STATUS_PATH')
        ?: $stateRoot . '/.target-health-state.json',

    'session_idle_timeout' => 1800,
    'session_absolute_timeout' => 28800,

    'login_max_attempts' => 5,
    'login_attempt_window' => 900,
    'login_lock_duration' => 900,

    'link_unlock_max_attempts' => (int)(getenv('LINKVAULT_UNLOCK_MAX_ATTEMPTS') ?: 5),
    'link_unlock_attempt_window' => (int)(getenv('LINKVAULT_UNLOCK_ATTEMPT_WINDOW_SECONDS') ?: 900),
    'link_unlock_lock_duration' => (int)(getenv('LINKVAULT_UNLOCK_LOCK_DURATION_SECONDS') ?: 900),
    'link_unlock_grant_ttl' => (int)(getenv('LINKVAULT_UNLOCK_GRANT_TTL_SECONDS') ?: 120),
];
