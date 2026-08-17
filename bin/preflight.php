<?php

declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');

$root = dirname(__DIR__);
$config = require $root . '/config.php';
require $root . '/app/bootstrap.php';
require $root . '/app/WebhookClient.php';
require_once $root . '/lib/runtime_paths.php';

$errors = [];
$databaseVersion = null;
$configuredUrl = null;

if (($config['environment'] ?? '') !== 'production' && ($config['environment'] ?? '') !== 'test') {
    $errors[] = 'LINKVAULT_ENV must be production for a production preflight.';
}

if (($config['environment'] ?? '') === 'production') {
    $productionPaths = [
        'LINKVAULT_DATABASE_PATH' => [(string)($config['database_path'] ?? ''), '/var/lib/linkvault'],
        'LINKVAULT_LOG_PATH' => [(string)($config['application_log_path'] ?? ''), '/var/log/linkvault'],
        'LINKVAULT_ANALYTICS_LOG_PATH' => [(string)($config['analytics_log_path'] ?? ''), '/var/log/linkvault'],
        'LINKVAULT_BACKUP_DIR' => [(string)($config['backup_directory'] ?? ''), '/var/backups/linkvault'],
        'LINKVAULT_ANALYTICS_STATE_PATH' => [(string)($config['analytics_state_path'] ?? ''), '/var/lib/linkvault'],
        'LINKVAULT_ANALYTICS_REPORT_CACHE_DIR' => [(string)($config['analytics_report_cache_directory'] ?? ''), '/var/lib/linkvault'],
        'LINKVAULT_ANALYTICS_EXPORT_DIR' => [(string)($config['analytics_export_directory'] ?? ''), '/var/lib/linkvault'],
        'LINKVAULT_TARGET_HEALTH_STATUS_PATH' => [(string)($config['target_health_status_path'] ?? ''), '/var/lib/linkvault'],
        'LINKVAULT_SYNTHETIC_STATUS_PATH' => [(string)($config['synthetic_status_path'] ?? ''), '/var/lib/linkvault'],
    ];
    foreach ($productionPaths as $name => [$path, $requiredRoot]) {
        if (!linkvault_path_is_within($path, $requiredRoot)) {
            $errors[] = $name . ' must be located under ' . $requiredRoot . ' in production.';
        }
    }
}

$environment = getenv();
foreach (is_array($environment) ? $environment : [] as $name => $value) {
    if (str_starts_with(strtoupper((string)$name), 'LINKVAULT_')
        && preg_match('/REPLACE(?:_|-|\s*)ME/i', (string)$value) === 1) {
        $errors[] = (string)$name . ' still contains a REPLACE_ME placeholder.';
    }
}

$password = (string)($config['admin_password'] ?? '');
if (!is_strong_admin_password($password)) {
    $errors[] = 'LINKVAULT_ADMIN_PASSWORD is missing or does not meet the password policy.';
}

$apiToken = (string)($config['api_token'] ?? '');
if ($apiToken !== '' && strlen($apiToken) < 24) {
    $errors[] = 'LINKVAULT_API_TOKEN must be empty or contain at least 24 characters.';
}
$metricsToken = (string)($config['metrics_token'] ?? '');
if ($metricsToken !== '' && strlen($metricsToken) < 24) {
    $errors[] = 'LINKVAULT_METRICS_TOKEN must be empty or contain at least 24 characters.';
}
$idempotencyRetention = (int)($config['idempotency_retention_seconds'] ?? 0);
if ($idempotencyRetention < 60 || $idempotencyRetention > 30 * 86400) {
    $errors[] = 'LINKVAULT_IDEMPOTENCY_RETENTION_SECONDS must be between 60 and 2592000.';
}
$rotationOverlap = (int)($config['api_token_rotation_overlap_seconds'] ?? 0);
$rotationMaxOverlap = (int)($config['api_token_rotation_max_overlap_seconds'] ?? 0);
if ($rotationOverlap < 60 || $rotationOverlap > 86400) {
    $errors[] = 'LINKVAULT_API_TOKEN_ROTATION_OVERLAP_SECONDS must be between 60 and 86400.';
}
if ($rotationMaxOverlap < 60 || $rotationMaxOverlap > 86400 || $rotationOverlap > $rotationMaxOverlap) {
    $errors[] = 'LINKVAULT_API_TOKEN_ROTATION_MAX_OVERLAP_SECONDS must be between the default overlap and 86400.';
}
$apiTokenUsageRetention = (int)($config['api_token_usage_retention_days'] ?? 0);
if ($apiTokenUsageRetention < 1 || $apiTokenUsageRetention > 3650) {
    $errors[] = 'LINKVAULT_API_TOKEN_USAGE_RETENTION_DAYS must be between 1 and 3650.';
}
$apiTokenRetention = (int)($config['api_token_retention_days'] ?? 0);
if ($apiTokenRetention < 1 || $apiTokenRetention > 3650) {
    $errors[] = 'LINKVAULT_API_TOKEN_RETENTION_DAYS must be between 1 and 3650.';
}
$domainRetirementBatchSize = (int)($config['domain_retirement_batch_size'] ?? 0);
$domainRetirementMaxBatches = (int)($config['domain_retirement_max_batches'] ?? 0);
if ($domainRetirementBatchSize < 10 || $domainRetirementBatchSize > 400) {
    $errors[] = 'LINKVAULT_DOMAIN_RETIREMENT_BATCH_SIZE must be between 10 and 400.';
}
if ($domainRetirementMaxBatches < 1 || $domainRetirementMaxBatches > 100) {
    $errors[] = 'LINKVAULT_DOMAIN_RETIREMENT_MAX_BATCHES must be between 1 and 100.';
}
$backupCommandTimeout = (int)($config['backup_command_timeout_seconds'] ?? 0);
if ($backupCommandTimeout < 1 || $backupCommandTimeout > 86400) {
    $errors[] = 'LINKVAULT_BACKUP_COMMAND_TIMEOUT_SECONDS must be between 1 and 86400.';
}
$apiTokenFailedUsageLimit = (int)($config['api_token_failed_usage_max_records'] ?? 0);
if ($apiTokenFailedUsageLimit < 1 || $apiTokenFailedUsageLimit > 100000) {
    $errors[] = 'LINKVAULT_API_TOKEN_FAILED_USAGE_MAX_RECORDS must be between 1 and 100000.';
}
$securityKey = (string)($config['security_key'] ?? '');
if ($securityKey !== '' && strlen($securityKey) < 32) {
    $errors[] = 'LINKVAULT_SECURITY_KEY must be empty or contain at least 32 characters.';
}
if ($securityKey !== '' && (!function_exists('openssl_encrypt')
    || !in_array('aes-256-gcm', openssl_get_cipher_methods(), true))) {
    $errors[] = 'The OpenSSL extension with AES-256-GCM support is required for TOTP.';
}
$apiRateLimitRequests = (int)($config['api_rate_limit_requests'] ?? 0);
$apiRateLimitWindow = (int)($config['api_rate_limit_window_seconds'] ?? 0);
if ($apiRateLimitRequests < 1 || $apiRateLimitRequests > 100000) {
    $errors[] = 'LINKVAULT_API_RATE_LIMIT_REQUESTS must be between 1 and 100000.';
}
if ($apiRateLimitWindow < 1 || $apiRateLimitWindow > 86400) {
    $errors[] = 'LINKVAULT_API_RATE_LIMIT_WINDOW_SECONDS must be between 1 and 86400.';
}
if ((int)($config['link_unlock_max_attempts'] ?? 0) < 2
    || (int)($config['link_unlock_max_attempts'] ?? 0) > 1000) {
    $errors[] = 'LINKVAULT_UNLOCK_MAX_ATTEMPTS must be between 2 and 1000.';
}
if ((int)($config['link_unlock_attempt_window'] ?? 0) < 60
    || (int)($config['link_unlock_attempt_window'] ?? 0) > 86400) {
    $errors[] = 'LINKVAULT_UNLOCK_ATTEMPT_WINDOW_SECONDS must be between 60 and 86400.';
}
if ((int)($config['link_unlock_lock_duration'] ?? 0) < 60
    || (int)($config['link_unlock_lock_duration'] ?? 0) > 86400) {
    $errors[] = 'LINKVAULT_UNLOCK_LOCK_DURATION_SECONDS must be between 60 and 86400.';
}
if ((int)($config['link_unlock_grant_ttl'] ?? 0) < 30
    || (int)($config['link_unlock_grant_ttl'] ?? 0) > 600) {
    $errors[] = 'LINKVAULT_UNLOCK_GRANT_TTL_SECONDS must be between 30 and 600.';
}
$auditRetention = (int)($config['audit_retention_days'] ?? 0);
if ($auditRetention < 1 || $auditRetention > 3650) {
    $errors[] = 'LINKVAULT_AUDIT_RETENTION_DAYS must be between 1 and 3650.';
}
$maintenanceBatchSize = (int)($config['maintenance_batch_size'] ?? 0);
if ($maintenanceBatchSize < 10 || $maintenanceBatchSize > 5000) {
    $errors[] = 'LINKVAULT_MAINTENANCE_BATCH_SIZE must be between 10 and 5000.';
}
$sqliteCacheSizeKib = (int)($config['sqlite_cache_size_kib'] ?? 0);
$sqliteBusyTimeoutMs = (int)($config['sqlite_busy_timeout_ms'] ?? 0);
$sqliteSlowQueryMs = (int)($config['sqlite_slow_query_ms'] ?? -1);
if ($sqliteCacheSizeKib < 1024 || $sqliteCacheSizeKib > 1048576) {
    $errors[] = 'LINKVAULT_SQLITE_CACHE_SIZE_KIB must be between 1024 and 1048576.';
}
if ($sqliteBusyTimeoutMs < 1 || $sqliteBusyTimeoutMs > 60000) {
    $errors[] = 'LINKVAULT_SQLITE_BUSY_TIMEOUT_MS must be between 1 and 60000.';
}
if ($sqliteSlowQueryMs < 0 || $sqliteSlowQueryMs > 60000) {
    $errors[] = 'LINKVAULT_SQLITE_SLOW_QUERY_MS must be between 0 and 60000.';
}
$analyticsMaterializeMaxRows = (int)($config['analytics_materialize_max_rows'] ?? -1);
$analyticsReportCacheSeconds = (int)($config['analytics_report_cache_seconds'] ?? -1);
$analyticsReportCacheDirectory = rtrim((string)($config['analytics_report_cache_directory'] ?? ''), '/\\');
if ($analyticsMaterializeMaxRows < 0 || $analyticsMaterializeMaxRows > 2000000) {
    $errors[] = 'LINKVAULT_ANALYTICS_MATERIALIZE_MAX_ROWS must be between 0 and 2000000.';
}
if ($analyticsReportCacheSeconds < 0 || $analyticsReportCacheSeconds > 3600) {
    $errors[] = 'LINKVAULT_ANALYTICS_REPORT_CACHE_SECONDS must be between 0 and 3600.';
}
if ($analyticsReportCacheSeconds > 0) {
    $cacheParent = $analyticsReportCacheDirectory === '' ? '' : dirname($analyticsReportCacheDirectory);
    if ($analyticsReportCacheDirectory === ''
        || (is_dir($analyticsReportCacheDirectory) && !is_writable($analyticsReportCacheDirectory))
        || (!is_dir($analyticsReportCacheDirectory) && (!is_dir($cacheParent) || !is_writable($cacheParent)))) {
        $errors[] = 'LINKVAULT_ANALYTICS_REPORT_CACHE_DIR or its parent must be writable.';
    }
}
$statsRetention = (int)($config['daily_stats_retention_days'] ?? 0);
$statsRetentionMode = (string)($config['daily_stats_retention_mode'] ?? '');
$statsArchiveRetention = (int)($config['daily_stats_archive_retention_days'] ?? 0);
if ($statsRetention < 1 || $statsRetention > 36500) {
    $errors[] = 'LINKVAULT_DAILY_STATS_RETENTION_DAYS must be between 1 and 36500.';
}
if (!in_array($statsRetentionMode, ['archive', 'delete'], true)) {
    $errors[] = 'LINKVAULT_DAILY_STATS_RETENTION_MODE must be archive or delete.';
}
if ($statsArchiveRetention < $statsRetention || $statsArchiveRetention > 36500) {
    $errors[] = 'LINKVAULT_DAILY_STATS_ARCHIVE_RETENTION_DAYS must be between daily retention and 36500.';
}
$webhookRetention = (int)($config['lifecycle_webhook_retention_days'] ?? 0);
$webhookAttemptRetention = (int)($config['lifecycle_webhook_attempt_retention_days'] ?? 0);
if ($webhookRetention < 1 || $webhookRetention > 3650) {
    $errors[] = 'LINKVAULT_LIFECYCLE_WEBHOOK_RETENTION_DAYS must be between 1 and 3650.';
}
if ($webhookAttemptRetention < 1 || $webhookAttemptRetention > 3650) {
    $errors[] = 'LINKVAULT_LIFECYCLE_WEBHOOK_ATTEMPT_RETENTION_DAYS must be between 1 and 3650.';
}
$maintenanceThresholds = is_array($config['maintenance_thresholds'] ?? null) ? $config['maintenance_thresholds'] : [];
foreach (linkvault_maintenance_threshold_specification() as $name => $rule) {
    $value = (int)($maintenanceThresholds[$name] ?? 0);
    if ($value < $rule['min'] || $value > $rule['max']) {
        $errors[] = $rule['environment'] . ' must be between ' . $rule['min'] . ' and ' . $rule['max'] . '.';
    }
}

try {
    $configuredUrl = configured_base_url($config);
    if ($configuredUrl === null) {
        $errors[] = 'LINKVAULT_BASE_URL is required for production.';
    } else {
        $scheme = strtolower((string)parse_url($configuredUrl['url'], PHP_URL_SCHEME));
        if ($scheme !== 'https') {
            $errors[] = 'LINKVAULT_BASE_URL must use HTTPS in production.';
        }
        if (preg_match('/(^|\.)example\.(com|net|org)$/i', (string)$configuredUrl['host'])) {
            $errors[] = 'LINKVAULT_BASE_URL still uses a reserved example domain.';
        }
    }
} catch (InvalidArgumentException $exception) {
    $errors[] = $exception->getMessage();
}

$trustedProxies = (array)($config['trusted_proxies'] ?? []);
foreach ($trustedProxies as $proxy) {
    if (!is_string($proxy) || !filter_var($proxy, FILTER_VALIDATE_IP)) {
        $errors[] = 'LINKVAULT_TRUSTED_PROXIES contains an invalid IP address.';
        break;
    }
}
if (count($trustedProxies) !== count(array_unique($trustedProxies))) {
    $errors[] = 'LINKVAULT_TRUSTED_PROXIES contains duplicate IP addresses.';
}

$databasePath = (string)($config['database_path'] ?? '');
if (linkvault_path_is_within($databasePath, $root)) {
    $errors[] = 'The production database must not be stored inside the application source directory.';
}
if ($databasePath === '' || !is_file($databasePath)) {
    $errors[] = 'The configured database file does not exist.';
} else {
    $databaseDirectory = dirname($databasePath);
    if (!is_readable($databasePath) || !is_writable($databasePath)
        || !is_readable($databaseDirectory) || !is_writable($databaseDirectory)) {
        $errors[] = 'The runtime user cannot read and write the database file and its directory.';
    }

    try {
        $pdo = database($config, 5000, true);
        $databaseVersion = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
        $integrityRows = $pdo->query('PRAGMA integrity_check')->fetchAll(PDO::FETCH_COLUMN);
        if ($integrityRows !== ['ok']) {
            $errors[] = 'Database integrity_check did not return ok.';
        }
        if ($pdo->query('PRAGMA foreign_key_check')->fetchColumn() !== false) {
            $errors[] = 'The database contains foreign key violations.';
        }
        $totpEnabled = (bool)$pdo->query('SELECT 1 FROM admin_security WHERE id = 1')->fetchColumn();
        if ($totpEnabled && strlen($securityKey) < 32) {
            $errors[] = 'LINKVAULT_SECURITY_KEY is required because TOTP is enabled.';
        }
    } catch (Throwable $exception) {
        $errors[] = 'Database validation failed: ' . $exception->getMessage();
    }
}

$logPath = (string)($config['application_log_path'] ?? '');
$logDirectory = $logPath === '' ? '' : dirname($logPath);
if ($logDirectory === '' || !is_dir($logDirectory) || !is_writable($logDirectory)) {
    $errors[] = 'The application log directory does not exist or is not writable by the runtime user.';
}

$remoteBackupRequired = !empty($config['backup_remote_required']);
$backupDirectory = (string)($config['backup_directory'] ?? '');
$backupStatusDirectory = rtrim((string)($config['backup_status_directory'] ?? ''), '/\\');
if ($backupDirectory !== DIRECTORY_SEPARATOR
    && preg_match('/^[A-Za-z]:[\\\\\/]$/D', $backupDirectory) !== 1) {
    $backupDirectory = rtrim($backupDirectory, '/\\');
}
$backupIntegrityInterval = (int)($config['backup_integrity_check_interval_seconds'] ?? 0);
$ageRecipient = trim((string)($config['backup_age_recipient'] ?? ''));
$rcloneRemote = rtrim(trim((string)($config['backup_rclone_remote'] ?? '')), '/');
$restoreSource = (string)($config['restore_drill_source'] ?? 'local');
$restoreDirectory = rtrim((string)($config['restore_drill_directory'] ?? ''), '/\\');
$restoreIdentity = trim((string)($config['restore_age_identity'] ?? ''));
$restoreRcloneConfig = trim((string)($config['restore_rclone_config'] ?? ''));
$restoreMaxAge = (int)($config['restore_drill_max_age_seconds'] ?? 0);
$alertWebhook = trim((string)($config['alert_webhook_url'] ?? ''));
$maintenanceWebhook = trim((string)($config['maintenance_webhook_url'] ?? ''));
$alertWebhookToken = (string)($config['alert_webhook_bearer_token'] ?? '');
$maintenanceWebhookToken = (string)($config['maintenance_webhook_bearer_token'] ?? '');
$lifecycleWebhook = trim((string)($config['lifecycle_webhook_url'] ?? ''));
$lifecycleWebhookToken = (string)($config['lifecycle_webhook_bearer_token'] ?? '');
$lifecycleSigningSecret = (string)($config['lifecycle_webhook_signing_secret'] ?? '');
$analyticsStatePath = trim((string)($config['analytics_state_path'] ?? ''));
$analyticsStateDirectory = $analyticsStatePath === '' ? '' : dirname($analyticsStatePath);
$analyticsStatusMaxAge = (int)($config['analytics_status_max_age_seconds'] ?? 0);
$analyticsHourlyDays = (int)($config['analytics_hourly_retention_days'] ?? 0);
$analyticsRetentionDays = (int)($config['analytics_retention_days'] ?? 0);
$analyticsRawLogDays = (int)($config['analytics_raw_log_retention_days'] ?? 0);
if ($analyticsRawLogDays < 1 || $analyticsRawLogDays > 365) {
    $errors[] = 'LINKVAULT_ANALYTICS_RAW_LOG_RETENTION_DAYS must be between 1 and 365.';
}
$analyticsBatchLines = (int)($config['analytics_batch_max_lines'] ?? 0);
$syntheticStatusPath = trim((string)($config['synthetic_status_path'] ?? ''));
$syntheticStatusDirectory = $syntheticStatusPath === '' ? '' : dirname($syntheticStatusPath);
$syntheticStatusMaxAge = (int)($config['synthetic_status_max_age_seconds'] ?? 0);
$targetHealthEnabled = !empty($config['target_health_enabled']);
$targetHealthEnabledRaw = getenv('LINKVAULT_TARGET_HEALTH_ENABLED');
$targetHealthInterval = (int)($config['target_health_interval_seconds'] ?? 0);
$targetHealthBatch = (int)($config['target_health_batch_size'] ?? 0);
$targetHealthConnectTimeout = (int)($config['target_health_connect_timeout_ms'] ?? 0);
$targetHealthHopTimeout = (int)($config['target_health_hop_timeout_ms'] ?? 0);
$targetHealthTotalTimeout = (int)($config['target_health_total_timeout_ms'] ?? 0);
$targetHealthMaxRedirects = (int)($config['target_health_max_redirects'] ?? -1);
$targetHealthHeaderMax = (int)($config['target_health_header_max_bytes'] ?? 0);
$targetHealthBodyMax = (int)($config['target_health_body_max_bytes'] ?? 0);
$targetHealthPorts = (array)($config['target_health_allowed_ports'] ?? []);
$targetHealthStatusPath = trim((string)($config['target_health_status_path'] ?? ''));
$targetHealthStatusDirectory = $targetHealthStatusPath === '' ? '' : dirname($targetHealthStatusPath);

if ($backupIntegrityInterval < 30 || $backupIntegrityInterval > 86400) {
    $errors[] = 'LINKVAULT_BACKUP_INTEGRITY_CHECK_INTERVAL_SECONDS must be between 30 and 86400.';
}
if ($backupStatusDirectory !== '') {
    if (!linkvault_backup_status_directory_secure($backupStatusDirectory)) {
        $errors[] = 'LINKVAULT_BACKUP_STATUS_DIR must be readable and must not be writable by group or other users.';
    }
} elseif ($backupDirectory === '' || !is_dir($backupDirectory)) {
    $errors[] = 'The configured backup directory does not exist.';
} elseif (is_link($backupDirectory)) {
    $errors[] = 'LINKVAULT_BACKUP_DIR must not be a symlink.';
} elseif (!is_readable($backupDirectory) || !is_writable($backupDirectory)) {
    $errors[] = 'The runtime user cannot read and write the configured backup directory.';
}

if ($analyticsStateDirectory === '' || !is_dir($analyticsStateDirectory) || !is_writable($analyticsStateDirectory)) {
    $errors[] = 'The analytics state directory does not exist or is not writable by the runtime user.';
}
if ($analyticsStatusMaxAge < 60 || $analyticsStatusMaxAge > 86400) {
    $errors[] = 'LINKVAULT_ANALYTICS_STATUS_MAX_AGE_SECONDS must be between 60 and 86400.';
}
if ($analyticsHourlyDays < 1 || $analyticsHourlyDays > 36500
    || $analyticsRetentionDays < $analyticsHourlyDays || $analyticsRetentionDays > 36500) {
    $errors[] = 'Analytics retention must keep daily data at least as long as hourly data.';
}
if ($analyticsBatchLines < 1 || $analyticsBatchLines > 1000000) {
    $errors[] = 'LINKVAULT_ANALYTICS_BATCH_MAX_LINES must be between 1 and 1000000.';
}
if ($syntheticStatusDirectory === '' || !is_dir($syntheticStatusDirectory)
    || !is_writable($syntheticStatusDirectory)) {
    $errors[] = 'The synthetic monitor status directory does not exist or is not writable by the runtime user.';
}
if ($syntheticStatusPath === '' || strlen($syntheticStatusPath) > 4096 || is_link($syntheticStatusPath)) {
    $errors[] = 'LINKVAULT_SYNTHETIC_STATUS_PATH must be a non-symlink path of at most 4096 bytes.';
}
if ($syntheticStatusMaxAge < 60 || $syntheticStatusMaxAge > 86400) {
    $errors[] = 'LINKVAULT_SYNTHETIC_STATUS_MAX_AGE_SECONDS must be between 60 and 86400.';
}
if ($targetHealthInterval < 60 || $targetHealthInterval > 604800) {
    $errors[] = 'LINKVAULT_TARGET_HEALTH_INTERVAL_SECONDS must be between 60 and 604800.';
}
if ($targetHealthEnabledRaw !== false
    && filter_var($targetHealthEnabledRaw, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) === null) {
    $errors[] = 'LINKVAULT_TARGET_HEALTH_ENABLED must be a boolean value.';
}
if ($targetHealthBatch < 1 || $targetHealthBatch > 500) {
    $errors[] = 'LINKVAULT_TARGET_HEALTH_BATCH_SIZE must be between 1 and 500.';
}
if ($targetHealthConnectTimeout < 100 || $targetHealthConnectTimeout > 30000) {
    $errors[] = 'LINKVAULT_TARGET_HEALTH_CONNECT_TIMEOUT_MS must be between 100 and 30000.';
}
if ($targetHealthHopTimeout < 500 || $targetHealthHopTimeout > 60000
    || $targetHealthConnectTimeout > $targetHealthHopTimeout) {
    $errors[] = 'Target health hop timeout must be 500-60000 ms and at least the connect timeout.';
}
if ($targetHealthTotalTimeout < $targetHealthHopTimeout || $targetHealthTotalTimeout > 300000) {
    $errors[] = 'Target health total timeout must be at least the hop timeout and at most 300000 ms.';
}
if ($targetHealthMaxRedirects < 1 || $targetHealthMaxRedirects > 10) {
    $errors[] = 'LINKVAULT_TARGET_HEALTH_MAX_REDIRECTS must be between 1 and 10.';
}
if ($targetHealthHeaderMax < 1024 || $targetHealthHeaderMax > 262144
    || $targetHealthBodyMax < 1024 || $targetHealthBodyMax > 1048576) {
    $errors[] = 'Target health response caps are outside the allowed bounds.';
}
$normalizedTargetHealthPorts = [];
foreach ($targetHealthPorts as $targetHealthPort) {
    if ((!is_int($targetHealthPort) && !is_string($targetHealthPort))
        || !ctype_digit((string)$targetHealthPort)
        || (int)$targetHealthPort < 1
        || (int)$targetHealthPort > 65535) {
        $errors[] = 'LINKVAULT_TARGET_HEALTH_ALLOWED_PORTS contains an invalid port.';
        break;
    }
    $normalizedTargetHealthPorts[] = (int)$targetHealthPort;
}
if (!$normalizedTargetHealthPorts || count($normalizedTargetHealthPorts) > 32
    || count($normalizedTargetHealthPorts) !== count(array_unique($normalizedTargetHealthPorts))) {
    $errors[] = 'LINKVAULT_TARGET_HEALTH_ALLOWED_PORTS must contain 1-32 unique ports.';
}
if (($targetHealthEnabled || $alertWebhook !== '' || $maintenanceWebhook !== '' || $lifecycleWebhook !== '') && !extension_loaded('curl')) {
    $errors[] = 'The curl extension is required when target health checks or webhooks are enabled.';
}
if ($targetHealthEnabled && ($targetHealthStatusDirectory === ''
    || !is_dir($targetHealthStatusDirectory) || !is_writable($targetHealthStatusDirectory))) {
    $errors[] = 'The target health status directory does not exist or is not writable by the runtime user.';
}
if ($targetHealthEnabled && ($targetHealthStatusPath === '' || strlen($targetHealthStatusPath) > 4096
    || is_link($targetHealthStatusPath))) {
    $errors[] = 'LINKVAULT_TARGET_HEALTH_STATUS_PATH must be a non-symlink path of at most 4096 bytes.';
}

if (strcasecmp($rcloneRemote, 'object-storage:linkvault-production') === 0) {
    $errors[] = 'LINKVAULT_BACKUP_RCLONE_REMOTE still uses the example destination.';
}
if ($rcloneRemote !== '' && !linkvault_valid_rclone_remote($rcloneRemote)) {
    $errors[] = 'LINKVAULT_BACKUP_RCLONE_REMOTE is not a valid rclone remote destination.';
}
if (!in_array($restoreSource, ['local', 'remote'], true)) {
    $errors[] = 'LINKVAULT_RESTORE_DRILL_SOURCE must be local or remote.';
}
if ($restoreDirectory === '') {
    $errors[] = 'LINKVAULT_RESTORE_DRILL_DIR must not be empty.';
} elseif (is_link($restoreDirectory)) {
    $errors[] = 'LINKVAULT_RESTORE_DRILL_DIR must not be a symlink.';
}
if ($restoreMaxAge < 3600 || $restoreMaxAge > 365 * 86400) {
    $errors[] = 'LINKVAULT_RESTORE_DRILL_MAX_AGE_SECONDS must be between 3600 and 31536000.';
}
if ($restoreSource === 'remote') {
    if ($restoreIdentity === '') {
        $errors[] = 'LINKVAULT_RESTORE_AGE_IDENTITY is required for a remote restore drill.';
    } elseif (!is_file($restoreIdentity) || is_link($restoreIdentity) || !is_readable($restoreIdentity)) {
        $errors[] = 'LINKVAULT_RESTORE_AGE_IDENTITY must be a readable regular non-symlink file.';
    }
    if ($restoreRcloneConfig !== ''
        && (!is_file($restoreRcloneConfig) || is_link($restoreRcloneConfig) || !is_readable($restoreRcloneConfig))) {
        $errors[] = 'LINKVAULT_RESTORE_RCLONE_CONFIG must be a readable regular non-symlink file when configured.';
    }
    if ($rcloneRemote === '') {
        $errors[] = 'LINKVAULT_BACKUP_RCLONE_REMOTE is required for a remote restore drill.';
    }
    if (trim((string)($config['backup_age_binary'] ?? '')) === '') {
        $errors[] = 'LINKVAULT_AGE_BIN is required for a remote restore drill.';
    }
    if (trim((string)($config['backup_rclone_binary'] ?? '')) === '') {
        $errors[] = 'LINKVAULT_RCLONE_BIN is required for a remote restore drill.';
    }
}

if ($maintenanceWebhook !== '') {
    $maintenanceWebhookHost = (string)parse_url($maintenanceWebhook, PHP_URL_HOST);
    try {
        WebhookClient::assertConfiguration($maintenanceWebhook, $maintenanceWebhookToken);
    } catch (Throwable $exception) {
        $errors[] = 'LINKVAULT_MAINTENANCE_WEBHOOK_URL or its Bearer token is unsafe: ' . $exception->getMessage();
    }
    if (preg_match('/(^|\.)example\.(com|net|org)$/i', $maintenanceWebhookHost) === 1) {
        $errors[] = 'LINKVAULT_MAINTENANCE_WEBHOOK_URL still uses a reserved example domain.';
    }
}

if ($alertWebhook !== '') {
    $webhookHost = (string)parse_url($alertWebhook, PHP_URL_HOST);
    try {
        WebhookClient::assertConfiguration($alertWebhook, $alertWebhookToken);
    } catch (Throwable $exception) {
        $errors[] = 'LINKVAULT_ALERT_WEBHOOK_URL or its Bearer token is unsafe: ' . $exception->getMessage();
    }
    if (preg_match('/(^|\.)example\.(com|net|org)$/i', $webhookHost) === 1) {
        $errors[] = 'LINKVAULT_ALERT_WEBHOOK_URL still uses a reserved example domain.';
    }
}

if ($lifecycleWebhook !== '') {
    $lifecycleWebhookHost = (string)parse_url($lifecycleWebhook, PHP_URL_HOST);
    try {
        WebhookClient::assertConfiguration($lifecycleWebhook, $lifecycleWebhookToken);
    } catch (Throwable $exception) {
        $errors[] = 'LINKVAULT_LIFECYCLE_WEBHOOK_URL or its Bearer token is unsafe: ' . $exception->getMessage();
    }
    if (strlen($lifecycleSigningSecret) < 32) {
        $errors[] = 'LINKVAULT_LIFECYCLE_WEBHOOK_SIGNING_SECRET must contain at least 32 characters.';
    }
    if (preg_match('/(^|\.)example\.(com|net|org)$/i', $lifecycleWebhookHost) === 1) {
        $errors[] = 'LINKVAULT_LIFECYCLE_WEBHOOK_URL still uses a reserved example domain.';
    }
} elseif ($lifecycleSigningSecret !== '' || $lifecycleWebhookToken !== '') {
    $errors[] = 'Lifecycle webhook credentials are configured without LINKVAULT_LIFECYCLE_WEBHOOK_URL.';
}

if ($remoteBackupRequired) {
    if ($ageRecipient === '') {
        $errors[] = 'LINKVAULT_BACKUP_AGE_RECIPIENT is required when remote backup is mandatory.';
    }
    if ($rcloneRemote === '') {
        $errors[] = 'LINKVAULT_BACKUP_RCLONE_REMOTE is required when remote backup is mandatory.';
    }
    if ($alertWebhook === '') {
        $errors[] = 'LINKVAULT_ALERT_WEBHOOK_URL is required when remote backup is mandatory.';
    }
}

if ($errors) {
    fwrite(STDERR, 'Production preflight failed:' . PHP_EOL);
    foreach (array_values(array_unique($errors)) as $error) {
        fwrite(STDERR, '- ' . $error . PHP_EOL);
    }
    exit(1);
}

$proxySummary = $trustedProxies ? implode(', ', $trustedProxies) : 'none (direct-client topology)';
fwrite(STDOUT, 'Production preflight passed.' . PHP_EOL);
fwrite(STDOUT, '- Base URL: ' . $configuredUrl['url'] . PHP_EOL);
fwrite(STDOUT, '- Trusted proxies: ' . $proxySummary . PHP_EOL);
fwrite(STDOUT, '- Database schema: v' . $databaseVersion . PHP_EOL);
