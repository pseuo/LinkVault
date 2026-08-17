<?php

declare(strict_types=1);

    if (!extension_loaded('pdo_sqlite')) {
        throw new RuntimeException('The pdo_sqlite extension is required for smoke tests.');
    }

    $fpmPool = (string)file_get_contents($root . '/deploy/php-fpm-linkvault.conf');
    foreach ([
        'LINKVAULT_API_TOKEN',
        'LINKVAULT_METRICS_TOKEN',
        'LINKVAULT_RELEASE_CHANGELOG',
        'LINKVAULT_RELEASE_ROLLBACK_VERSION',
        'LINKVAULT_BACKUP_DIR',
        'LINKVAULT_BACKUP_COMMAND_TIMEOUT_SECONDS',
        'LINKVAULT_BACKUP_MAX_AGE_SECONDS',
        'LINKVAULT_BACKUP_INTEGRITY_CHECK_INTERVAL_SECONDS',
        'LINKVAULT_BACKUP_REMOTE_REQUIRED',
        'LINKVAULT_HEALTH_MIN_FREE_BYTES',
        'LINKVAULT_API_RATE_LIMIT_REQUESTS',
        'LINKVAULT_API_RATE_LIMIT_WINDOW_SECONDS',
        'LINKVAULT_API_TOKEN_ROTATION_OVERLAP_SECONDS',
        'LINKVAULT_API_TOKEN_ROTATION_MAX_OVERLAP_SECONDS',
        'LINKVAULT_API_TOKEN_USAGE_RETENTION_DAYS',
        'LINKVAULT_UNLOCK_MAX_ATTEMPTS',
        'LINKVAULT_UNLOCK_ATTEMPT_WINDOW_SECONDS',
        'LINKVAULT_UNLOCK_LOCK_DURATION_SECONDS',
        'LINKVAULT_UNLOCK_GRANT_TTL_SECONDS',
        'LINKVAULT_SECURITY_KEY',
        'LINKVAULT_HEALTH_BUSY_TIMEOUT_MS',
        'LINKVAULT_RESTORE_DRILL_SOURCE',
        'LINKVAULT_MAINTENANCE_EXPIRING_DAYS',
        'LINKVAULT_MAINTENANCE_STALE_DAYS',
        'LINKVAULT_MAINTENANCE_QUOTA_PERCENT',
        'LINKVAULT_ANALYTICS_STATE_PATH',
        'LINKVAULT_ANALYTICS_STATUS_MAX_AGE_SECONDS',
        'LINKVAULT_ANALYTICS_RAW_LOG_RETENTION_DAYS',
        'LINKVAULT_SYNTHETIC_STATUS_PATH',
        'LINKVAULT_SYNTHETIC_STATUS_MAX_AGE_SECONDS',
        'LINKVAULT_TARGET_HEALTH_ENABLED',
        'LINKVAULT_TARGET_HEALTH_INTERVAL_SECONDS',
        'LINKVAULT_TARGET_HEALTH_BATCH_SIZE',
        'LINKVAULT_TARGET_HEALTH_CONNECT_TIMEOUT_MS',
        'LINKVAULT_TARGET_HEALTH_HOP_TIMEOUT_MS',
        'LINKVAULT_TARGET_HEALTH_TOTAL_TIMEOUT_MS',
        'LINKVAULT_TARGET_HEALTH_MAX_REDIRECTS',
        'LINKVAULT_TARGET_HEALTH_HEADER_MAX_BYTES',
        'LINKVAULT_TARGET_HEALTH_BODY_MAX_BYTES',
        'LINKVAULT_TARGET_HEALTH_ALLOWED_PORTS',
        'LINKVAULT_TARGET_HEALTH_STATUS_PATH',
    ] as $fpmVariable) {
        assert_true(
            str_contains($fpmPool, 'env[' . $fpmVariable . '] = $' . $fpmVariable),
            "The PHP-FPM pool does not pass {$fpmVariable}."
        );
    }
    $caddyConfig = (string)file_get_contents($root . '/deploy/Caddyfile');
    assert_true(
        str_contains($caddyConfig, 'unix//run/php/php8.5-fpm-linkvault.sock'),
        'Caddy does not use the dedicated PHP-FPM pool socket.'
    );
    foreach (['api_per_client', 'unlock_per_client', 'readiness_per_client', 'health_per_client', 'linkvault-endpoints.log', 'linkvault-analytics.log', 'roll_disabled'] as $caddySetting) {
        assert_true(str_contains($caddyConfig, $caddySetting), "Caddy is missing endpoint control {$caddySetting}.");
    }
    foreach ([
        'request>uri regexp', 'request>headers>Referer regexp',
        'request>headers>X-Forwarded-For delete', 'request>headers>Forwarded delete',
    ] as $privacySetting) {
        assert_true(str_contains($caddyConfig, $privacySetting), "Caddy is missing analytics privacy filter {$privacySetting}.");
    }
    $nginxConfig = (string)file_get_contents($root . '/deploy/nginx.conf');
    foreach (['zone=linkvault_api_ip', 'zone=linkvault_unlock_ip', 'zone=linkvault_ready_ip', 'zone=linkvault_health_ip', 'linkvault-endpoints.log', 'linkvault-analytics.log'] as $nginxSetting) {
        assert_true(str_contains($nginxConfig, $nginxSetting), "Nginx is missing endpoint control {$nginxSetting}.");
    }
    assert_true(
        str_contains($nginxConfig, '"referrer_domain":"$linkvault_analytics_referrer_domain"')
            && !str_contains($nginxConfig, '"referrer":"$http_referer"'),
        'Nginx analytics logs must retain only the referrer domain.'
    );
    assert_true(
        str_contains($nginxConfig, 'linkvault-security.log')
            && str_contains(
                (string)file_get_contents($root . '/deploy/fail2ban/jail.d/linkvault.local'),
                '/var/log/nginx/linkvault-security.log'
            ),
        'Nginx Fail2ban does not monitor the dedicated login-rate-limit log.'
    );
    assert_true(
        str_contains(
            (string)file_get_contents($root . '/bin/check-http-endpoints.php'),
            'rotation_expires_at'
        ),
        'The endpoint monitor ignores managed-token rotation expiry.'
    );
    $endpointMonitorService = (string)file_get_contents($root . '/deploy/linkvault-endpoint-monitor.service');
    assert_true(
        str_contains($endpointMonitorService, 'OnFailure=linkvault-notify@%n.service')
            && str_contains($endpointMonitorService, 'ReadWritePaths=/var/lib/linkvault')
            && is_file($root . '/deploy/linkvault-endpoint-monitor.timer'),
        'Endpoint failures are not connected to the existing alert path.'
    );
    $logrotateConfig = (string)file_get_contents($root . '/deploy/linkvault-logrotate.conf');
    assert_true(
        str_starts_with($logrotateConfig, '/var/log/linkvault/application.log '),
        'Logrotate does not monitor the configured production application log.'
    );
    assert_true(str_contains($logrotateConfig, 'linkvault-analytics.log'), 'Analytics raw logs do not have a retention policy.');
    assert_true(
        !str_contains($logrotateConfig, 'copytruncate')
            && str_contains($logrotateConfig, 'nocompress')
            && str_contains($logrotateConfig, 'kill -USR1')
            && str_contains($logrotateConfig, 'systemctl reload caddy.service'),
        'Analytics log rotation must reopen writers without copytruncate or compression gaps.'
    );
    foreach ([
        $root . '/bin/notify-failure.php',
        $root . '/bin/notify-maintenance.php',
        $root . '/bin/check-analytics-anomalies.php',
    ] as $webhookScript) {
        $webhookSource = (string)file_get_contents($webhookScript);
        assert_true(
            str_contains($webhookSource, 'WebhookClient')
                && !str_contains($webhookSource, 'file_get_contents($webhook'),
            'All webhook scripts must use the guarded webhook client.'
        );
    }
    $fpmServiceDropIn = (string)file_get_contents($root . '/deploy/php-fpm-linkvault.service.conf');
    $migrateService = (string)file_get_contents($root . '/deploy/linkvault-migrate.service');
    $preflightService = (string)file_get_contents($root . '/deploy/linkvault-preflight.service');
    $lifecycleWebhookService = (string)file_get_contents($root . '/deploy/linkvault-lifecycle-webhook.service');
    assert_true(
        str_contains($fpmServiceDropIn, 'Requires=linkvault-migrate.service linkvault-preflight.service')
            && str_contains($fpmServiceDropIn, 'After=linkvault-migrate.service linkvault-preflight.service')
            && str_contains($migrateService, 'Before=linkvault-preflight.service')
            && str_contains($preflightService, 'Requires=linkvault-migrate.service')
            && str_contains($preflightService, 'After=local-fs.target linkvault-migrate.service'),
        'PHP-FPM is not blocked on successful migration and production preflight.'
    );
    assert_true(
        str_contains($lifecycleWebhookService, 'Requires=linkvault-migrate.service')
            && str_contains($lifecycleWebhookService, 'After=local-fs.target network-online.target linkvault-migrate.service'),
        'Lifecycle webhook dispatch can start before its schema migration.'
    );
    assert_true(
        is_file($root . '/deploy/linkvault-analytics.service')
            && is_file($root . '/deploy/linkvault-analytics.timer'),
        'Analytics aggregation is not connected to a scheduled service.'
    );
    $targetHealthService = (string)file_get_contents($root . '/deploy/linkvault-target-health.service');
    $targetHealthTimer = (string)file_get_contents($root . '/deploy/linkvault-target-health.timer');
    assert_true(
        str_contains($targetHealthService, 'network-online.target')
            && str_contains($targetHealthService, 'OnFailure=linkvault-notify@%n.service')
            && str_contains($targetHealthService, '/bin/check-target-health.php')
            && str_contains($targetHealthTimer, 'OnCalendar=*:0/15'),
        'Target health checks are not connected to a 15-minute network-online scheduled service.'
    );
    $cleanupService = (string)file_get_contents($root . '/deploy/linkvault-data-cleanup.service');
    $cleanupTimer = (string)file_get_contents($root . '/deploy/linkvault-data-cleanup.timer');
    assert_true(
        str_contains($cleanupService, '/bin/cleanup-data.php')
            && str_contains($cleanupService, 'Requires=linkvault-migrate.service')
            && str_contains($cleanupService, 'After=local-fs.target linkvault-migrate.service')
            && str_contains($cleanupTimer, 'OnCalendar=*-*-* 03:20:00 UTC')
            && !str_contains($cleanupTimer, 'RandomizedDelaySec'),
        'Operational data cleanup is not assigned to a fixed scheduled task.'
    );
    assert_true(
        !str_contains((string)file_get_contents($root . '/app/bootstrap.php'), 'DELETE FROM audit_events'),
        'Audit retention still depends on random request-triggered cleanup.'
    );

    if (!mkdir($testDirectory, 0775, true) && !is_dir($testDirectory)) {
        throw new RuntimeException('Cannot create the smoke-test directory.');
    }
    $restoreIdentityPath = $testDirectory . DIRECTORY_SEPARATOR . 'restore-age-identity.txt';
    $restoreRcloneConfigPath = $testDirectory . DIRECTORY_SEPARATOR . 'restore-rclone.conf';
    assert_true(file_put_contents($restoreIdentityPath, 'AGE-SECRET-KEY-TEST' . PHP_EOL) !== false, 'Cannot create restore identity fixture.');
    assert_true(file_put_contents($restoreRcloneConfigPath, '[production]' . PHP_EOL) !== false, 'Cannot create rclone config fixture.');

    $port = available_port();
    $baseUrl = 'http://127.0.0.1:' . $port;
    $validBackupDirectory = $testDirectory . DIRECTORY_SEPARATOR . 'valid-backups';
    if (!mkdir($validBackupDirectory, 0775, true) && !is_dir($validBackupDirectory)) {
        throw new RuntimeException('Cannot create the smoke-test backup directory.');
    }
    $environment = process_environment([
        'LINKVAULT_ENV' => 'test',
        'LINKVAULT_ADMIN_PASSWORD' => $password,
        'LINKVAULT_DATABASE_PATH' => $databasePath,
        'LINKVAULT_LOG_PATH' => $logPath,
        'LINKVAULT_ANALYTICS_LOG_PATH' => $analyticsLogPath,
        'LINKVAULT_ANALYTICS_STATE_PATH' => $analyticsStatePath,
        'LINKVAULT_SYNTHETIC_STATUS_PATH' => $syntheticStatusPath,
        'LINKVAULT_SYNTHETIC_STATUS_MAX_AGE_SECONDS' => '900',
        'LINKVAULT_BASE_URL' => $baseUrl,
        'LINKVAULT_RELEASE_VERSION' => '2.4.0',
        'LINKVAULT_BUILD_TIME' => '2026-08-06T08:00:00Z',
        'LINKVAULT_RELEASE_CHANGELOG' => '新增发布版本中心|补充合成监控结果',
        'LINKVAULT_RELEASE_ROLLBACK_VERSION' => '2.3.1',
        'LINKVAULT_TRUSTED_PROXIES' => '127.0.0.1',
        'LINKVAULT_BACKUP_DIR' => $validBackupDirectory,
        'LINKVAULT_BACKUP_REMOTE_REQUIRED' => '0',
        'LINKVAULT_BACKUP_AGE_RECIPIENT' => '',
        'LINKVAULT_BACKUP_RCLONE_REMOTE' => '',
        'LINKVAULT_RESTORE_DRILL_SOURCE' => 'local',
        'LINKVAULT_RESTORE_AGE_IDENTITY' => '',
        'LINKVAULT_RESTORE_RCLONE_CONFIG' => '',
        'LINKVAULT_ALERT_WEBHOOK_URL' => '',
        'LINKVAULT_ALERT_BEARER_TOKEN' => '',
        'LINKVAULT_MAINTENANCE_EXPIRING_DAYS' => '21',
        'LINKVAULT_MAINTENANCE_STALE_DAYS' => '120',
        'LINKVAULT_MAINTENANCE_QUOTA_PERCENT' => '65',
        'LINKVAULT_API_TOKEN' => $apiToken,
        'LINKVAULT_METRICS_TOKEN' => 'smoke-metrics-token-234567890',
        'LINKVAULT_ANALYTICS_RAW_LOG_RETENTION_DAYS' => '30',
        'LINKVAULT_SECURITY_KEY' => 'smoke-security-key-234567890-abcdef',
        'LINKVAULT_UNLOCK_MAX_ATTEMPTS' => '3',
        'LINKVAULT_UNLOCK_ATTEMPT_WINDOW_SECONDS' => '300',
        'LINKVAULT_UNLOCK_LOCK_DURATION_SECONDS' => '300',
        'LINKVAULT_UNLOCK_GRANT_TTL_SECONDS' => '120',
        'LINKVAULT_TARGET_HEALTH_ENABLED' => '0',
        'LINKVAULT_TARGET_HEALTH_STATUS_PATH' => $testDirectory . DIRECTORY_SEPARATOR . 'target-health-state.json',
    ]);

    $migration = run_process([PHP_BINARY, $root . '/bin/migrate.php'], $root, $environment);
    assert_true($migration['exit_code'] === 0, 'Initial migration failed: ' . $migration['stderr']);
    $migration = run_process([PHP_BINARY, $root . '/bin/migrate.php'], $root, $environment);
    assert_true($migration['exit_code'] === 0, 'The migration is not idempotent.');
    $schemaCheckPdo = new PDO('sqlite:' . $databasePath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $linkColumns = array_column($schemaCheckPdo->query('PRAGMA table_info("links")')->fetchAll(PDO::FETCH_ASSOC), 'name');
    assert_true(
        !array_diff(['access_password_hash', 'invalid_message', 'fallback_url'], $linkColumns),
        'Migration 015 did not add all link access-control columns.'
    );
    $attemptForeignKeys = $schemaCheckPdo->query('PRAGMA foreign_key_list("link_password_attempts")')->fetchAll(PDO::FETCH_ASSOC);
    assert_true(
        count(array_filter($attemptForeignKeys, static fn (array $key): bool => ($key['table'] ?? null) === 'links'
            && ($key['from'] ?? null) === 'link_id' && strtoupper((string)($key['on_delete'] ?? '')) === 'CASCADE')) === 1,
        'The link password throttle does not cascade with its link.'
    );
    assert_true(
        (int)$schemaCheckPdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'index' AND name = 'link_password_attempts_cleanup_idx'")->fetchColumn() === 1,
        'The link password throttle cleanup index is missing.'
    );
    $targetHealthColumns = array_column(
        $schemaCheckPdo->query('PRAGMA table_info("target_health")')->fetchAll(PDO::FETCH_ASSOC),
        'name'
    );
    assert_true(
        !array_diff([
            'link_id', 'target_url_hash', 'state', 'reason', 'checked_at', 'next_check_at',
            'last_healthy_at', 'http_status', 'latency_ms', 'effective_url', 'redirect_count',
            'redirect_state', 'consecutive_failures',
        ], $targetHealthColumns),
        'Migration 016 did not add the complete target health summary.'
    );
    assert_true(
        (int)$schemaCheckPdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'index' AND name = 'target_health_due_idx'")->fetchColumn() === 1
            && (int)$schemaCheckPdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'index' AND name = 'target_health_state_checked_idx'")->fetchColumn() === 1,
        'Target health due/state indexes are missing.'
    );
    $analyticsCursorColumns = array_column(
        $schemaCheckPdo->query('PRAGMA table_info("analytics_ingest_state")')->fetchAll(PDO::FETCH_ASSOC),
        'name'
    );
    assert_true(
        in_array('checkpoint_hash', $analyticsCursorColumns, true),
        'Migration 018 did not add the analytics cursor checkpoint.'
    );
    $schemaCheckPdo = null;

    $targetHealthTests = run_process([PHP_BINARY, $root . '/tests/target_health.php'], $root, $environment);
    assert_true($targetHealthTests['exit_code'] === 0, 'Target health policy tests failed: ' . $targetHealthTests['stderr']);
    $webhookTests = run_process([PHP_BINARY, $root . '/tests/webhook.php'], $root, $environment);
    assert_true($webhookTests['exit_code'] === 0, 'Webhook policy tests failed: ' . $webhookTests['stderr']);
    assert_true(
        !str_contains((string)file_get_contents($root . '/public/controllers/LinkController.php'), 'TargetHealthService'),
        'Public redirect routing must not invoke the target health service.'
    );
    $webEntryPoint = (string)file_get_contents($root . '/public/index.php');
    $publicDispatchPosition = strpos($webEntryPoint, 'PublicRedirectController::dispatch');
    $analyticsLoadPosition = strpos($webEntryPoint, '/app/AnalyticsReportService.php');
    $apiTokenInitPosition = strpos($webEntryPoint, 'new ApiTokenService');
    $totpCheckPosition = strpos($webEntryPoint, '->isEnabled()');
    assert_true(
        $publicDispatchPosition !== false
            && $analyticsLoadPosition !== false
            && $apiTokenInitPosition !== false
            && $totpCheckPosition !== false
            && $publicDispatchPosition < $analyticsLoadPosition
            && $publicDispatchPosition < $apiTokenInitPosition
            && $publicDispatchPosition < $totpCheckPosition,
        'Public redirects must exit before dashboard, API token, and TOTP services initialize.'
    );

    $invalidApiStatus = run_process([
        PHP_BINARY,
        '-r',
        '$config = require "config.php"; require "app/bootstrap.php"; require "app/SystemStatus.php"; $status = (new SystemStatus(database($config), $config))->collect(); exit(($status["api"]["state"] ?? null) === "error" && ($status["overall_state"] ?? null) === "error" ? 0 : 1);',
    ], $root, array_merge($environment, ['LINKVAULT_API_TOKEN' => 'too-short']));
    assert_true($invalidApiStatus['exit_code'] === 0, 'An invalid API token did not put the system status into warning.');
    $optionalStatus = run_process([
        PHP_BINARY,
        '-r',
        'require "app/bootstrap.php"; require "app/SystemStatus.php"; $reflection = new ReflectionMethod(SystemStatus::class, "reduceStates"); exit($reflection->invoke(new SystemStatus(new PDO("sqlite::memory:"), []), ["ok", "unconfigured"]) === "ok" ? 0 : 1);',
    ], $root, $environment);
    assert_true($optionalStatus['exit_code'] === 0, 'An unconfigured optional component degraded the overall system state.');

    $preflightEnvironment = array_merge($environment, [
        'LINKVAULT_BASE_URL' => 'https://linkvault.test',
        'LINKVAULT_TRUSTED_PROXIES' => '',
    ]);
    $preflight = run_process([PHP_BINARY, $root . '/bin/preflight.php'], $root, $preflightEnvironment);
    assert_true($preflight['exit_code'] === 0, 'Valid production preflight failed: ' . $preflight['stderr']);
    assert_true(!str_contains($preflight['stdout'], $password), 'Production preflight exposed the admin password.');

    $zeroConfig = run_process([
        PHP_BINARY,
        '-r',
        '$config = require "config.php"; exit($config["backup_integrity_check_interval_seconds"] === 0 && $config["backup_command_timeout_seconds"] === 0 && $config["api_token_usage_retention_days"] === 0 && $config["target_health_max_redirects"] === 0 ? 0 : 1);',
    ], $root, array_merge($preflightEnvironment, [
        'LINKVAULT_BACKUP_INTEGRITY_CHECK_INTERVAL_SECONDS' => '0',
        'LINKVAULT_BACKUP_COMMAND_TIMEOUT_SECONDS' => '0',
        'LINKVAULT_API_TOKEN_USAGE_RETENTION_DAYS' => '0',
        'LINKVAULT_TARGET_HEALTH_MAX_REDIRECTS' => '0',
    ]));
    assert_true($zeroConfig['exit_code'] === 0, 'Config defaults replaced explicit zero operational settings.');
    foreach ([
        'LINKVAULT_BACKUP_INTEGRITY_CHECK_INTERVAL_SECONDS',
        'LINKVAULT_BACKUP_COMMAND_TIMEOUT_SECONDS',
        'LINKVAULT_API_TOKEN_USAGE_RETENTION_DAYS',
        'LINKVAULT_TARGET_HEALTH_MAX_REDIRECTS',
    ] as $zeroVariable) {
        $zeroPreflight = run_process(
            [PHP_BINARY, $root . '/bin/preflight.php'],
            $root,
            array_merge($preflightEnvironment, [$zeroVariable => '0'])
        );
        assert_true($zeroPreflight['exit_code'] !== 0, "Production preflight accepted zero {$zeroVariable}.");
    }

    $missingBackupDirectory = $testDirectory . DIRECTORY_SEPARATOR . 'missing-backups';
    $missingBackupPreflight = run_process(
        [PHP_BINARY, $root . '/bin/preflight.php'],
        $root,
        array_merge($preflightEnvironment, ['LINKVAULT_BACKUP_DIR' => $missingBackupDirectory])
    );
    assert_true(
        $missingBackupPreflight['exit_code'] !== 0 && !file_exists($missingBackupDirectory),
        'Production preflight accepted or created a missing backup directory.'
    );

    $invalidPreflight = run_process([PHP_BINARY, $root . '/bin/preflight.php'], $root, array_merge(
        $preflightEnvironment,
        ['LINKVAULT_TRUSTED_PROXIES' => 'not-an-ip']
    ));
    assert_true($invalidPreflight['exit_code'] !== 0, 'Production preflight accepted an invalid trusted proxy.');
    $zeroMaintenancePreflight = run_process([PHP_BINARY, $root . '/bin/preflight.php'], $root, array_merge(
        $preflightEnvironment,
        ['LINKVAULT_MAINTENANCE_EXPIRING_DAYS' => '0']
    ));
    assert_true(
        $zeroMaintenancePreflight['exit_code'] !== 0,
        'Production preflight silently replaced an explicit zero maintenance threshold.'
    );

    $placeholderPreflight = run_process([PHP_BINARY, $root . '/bin/preflight.php'], $root, array_merge(
        $preflightEnvironment,
        ['LINKVAULT_BASE_URL' => 'https://s.example.com']
    ));
    assert_true($placeholderPreflight['exit_code'] !== 0, 'Production preflight accepted the example domain.');

    $remotePreflightEnvironment = array_merge($preflightEnvironment, [
        'LINKVAULT_BACKUP_REMOTE_REQUIRED' => '1',
        'LINKVAULT_BACKUP_AGE_RECIPIENT' => 'age1productionrecipient',
        'LINKVAULT_BACKUP_RCLONE_REMOTE' => 'production:linkvault',
        'LINKVAULT_RESTORE_DRILL_SOURCE' => 'remote',
        'LINKVAULT_RESTORE_AGE_IDENTITY' => $restoreIdentityPath,
        'LINKVAULT_RESTORE_RCLONE_CONFIG' => $restoreRcloneConfigPath,
        'LINKVAULT_ALERT_WEBHOOK_URL' => 'https://alerts.linkvault.test/backup-failed',
        'LINKVAULT_ALERT_BEARER_TOKEN' => 'alert-test-token',
    ]);
    $remotePreflight = run_process([PHP_BINARY, $root . '/bin/preflight.php'], $root, $remotePreflightEnvironment);
    assert_true($remotePreflight['exit_code'] === 0, 'Valid remote-backup preflight failed: ' . $remotePreflight['stderr']);
    $invalidRestoreSourcePreflight = run_process(
        [PHP_BINARY, $root . '/bin/preflight.php'],
        $root,
        array_merge($preflightEnvironment, ['LINKVAULT_RESTORE_DRILL_SOURCE' => 'mirror'])
    );
    assert_true($invalidRestoreSourcePreflight['exit_code'] !== 0, 'Production preflight accepted an invalid restore source.');
    $missingRestoreIdentityPreflight = run_process(
        [PHP_BINARY, $root . '/bin/preflight.php'],
        $root,
        array_merge($remotePreflightEnvironment, ['LINKVAULT_RESTORE_AGE_IDENTITY' => ''])
    );
    assert_true($missingRestoreIdentityPreflight['exit_code'] !== 0, 'Remote restore preflight accepted a missing age identity.');

    foreach ([
        'LINKVAULT_BACKUP_AGE_RECIPIENT' => 'age1REPLACE_ME',
        'LINKVAULT_BACKUP_RCLONE_REMOTE' => 'object-storage:linkvault-production',
        'LINKVAULT_ALERT_WEBHOOK_URL' => 'https://alerts.example.net/REPLACE_ME',
        'LINKVAULT_ALERT_BEARER_TOKEN' => 'REPLACE_ME',
    ] as $variable => $placeholder) {
        $placeholderPreflight = run_process(
            [PHP_BINARY, $root . '/bin/preflight.php'],
            $root,
            array_merge($remotePreflightEnvironment, [$variable => $placeholder])
        );
        assert_true(
            $placeholderPreflight['exit_code'] !== 0,
            "Production preflight accepted the {$variable} example value."
        );
    }

    $migrationPdo = new PDO('sqlite:' . $databasePath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $migrationPdo->exec('PRAGMA foreign_keys = ON');
    $migrationPdo->exec('PRAGMA user_version = ' . (LINKVAULT_SCHEMA_VERSION + 1));
    $migration = run_process([PHP_BINARY, $root . '/bin/migrate.php'], $root, $environment);
    assert_true($migration['exit_code'] !== 0, 'A newer unknown schema version must be rejected.');
    $migrationPdo->exec('PRAGMA user_version = ' . LINKVAULT_SCHEMA_VERSION);

    $historicalExpectedClicks = [1 => 7, 2 => 8, 3 => 9, 4 => 10, 5 => 11, 6 => 12];
    $migrationFixtureNames = [
        7 => 'operations',
        8 => 'maintenance_features',
        9 => 'api_token_lifecycle',
        10 => 'api_rate_limits',
        11 => 'active_maintenance_security',
        12 => 'visitor_analytics',
        13 => 'analytics_accounting',
        14 => 'analytics_operations',
        15 => 'link_access_controls',
        16 => 'target_health',
        17 => 'operational_workflows',
        18 => 'analytics_cursor_checkpoint',
        19 => 'imported_password_reset',
        20 => 'api_token_scopes',
        21 => 'domains_lifecycle_webhooks',
        22 => 'maintenance_branding',
        23 => 'workflow_extensions',
        24 => 'operational_jobs',
        25 => 'analytics_exports_rollups',
        26 => 'analytics_link_options_index',
        27 => 'operational_controls',
        28 => 'p2_capabilities',
    ];
    foreach (range(1, LINKVAULT_SCHEMA_VERSION - 1) as $legacyVersion) {
        $legacyDatabasePath = $testDirectory . DIRECTORY_SEPARATOR . "schema-v{$legacyVersion}.sqlite";
        $legacyPdo = new PDO('sqlite:' . $legacyDatabasePath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $fixtureSql = file_get_contents($root . '/tests/fixtures/schema_v' . min($legacyVersion, 6) . '.sql');
        assert_true(is_string($fixtureSql), "Cannot read the v{$legacyVersion} schema fixture.");
        $legacyPdo->exec($fixtureSql);
        for ($fixtureVersion = 7; $fixtureVersion <= $legacyVersion; $fixtureVersion++) {
            $migrationPath = $root . '/migrations/' . sprintf('%03d', $fixtureVersion)
                . '_' . $migrationFixtureNames[$fixtureVersion] . '.sql';
            $legacyPdo->exec(linkvault_verified_migration_sql($migrationPath, $fixtureVersion));
            $legacyPdo->exec('PRAGMA user_version = ' . $fixtureVersion);
        }
        $legacyPdo->exec('PRAGMA foreign_keys = ON');
        $legacyEnvironment = array_merge($environment, ['LINKVAULT_DATABASE_PATH' => $legacyDatabasePath]);
        $migration = run_process([PHP_BINARY, $root . '/bin/migrate.php'], $root, $legacyEnvironment);
        assert_true($migration['exit_code'] === 0, "Version {$legacyVersion} migration failed: " . $migration['stderr']);
        assert_true(
            (int)$legacyPdo->query('PRAGMA user_version')->fetchColumn() === LINKVAULT_SCHEMA_VERSION,
            "Version {$legacyVersion} migration did not reach the current schema version."
        );
        assert_true(!linkvault_schema_problems($legacyPdo), "Version {$legacyVersion} migration failed schema validation.");
        assert_true(
            (int)$legacyPdo->query('SELECT clicks FROM link_daily_stats WHERE link_id = 1')->fetchColumn()
                === ($historicalExpectedClicks[$legacyVersion] ?? 12),
            "Version {$legacyVersion} migration did not preserve valid daily statistics."
        );
        assert_true(
            (int)$legacyPdo->query('SELECT COUNT(*) FROM link_daily_stats WHERE link_id = 999')->fetchColumn() === 0,
            "Version {$legacyVersion} migration did not remove orphaned daily statistics."
        );
        $legacyPdo->exec('PRAGMA foreign_keys = ON');
        $legacyPdo->exec('DELETE FROM links WHERE id = 1');
        assert_true(
            (int)$legacyPdo->query('SELECT COUNT(*) FROM link_daily_stats WHERE link_id = 1')->fetchColumn() === 0,
            "Version {$legacyVersion} migration did not enable cascading deletes."
        );
    }

    $interruptedDatabasePath = $testDirectory . DIRECTORY_SEPARATOR . 'schema-interrupted-v9.sqlite';
    $interruptedPdo = new PDO('sqlite:' . $interruptedDatabasePath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $interruptedFixture = file_get_contents($root . '/tests/fixtures/schema_v6.sql');
    assert_true(is_string($interruptedFixture), 'Cannot read the interruption schema fixture.');
    $interruptedPdo->exec($interruptedFixture);
    for ($fixtureVersion = 7; $fixtureVersion <= 9; $fixtureVersion++) {
        $migrationPath = $root . '/migrations/' . sprintf('%03d', $fixtureVersion)
            . '_' . $migrationFixtureNames[$fixtureVersion] . '.sql';
        $interruptedPdo->exec(linkvault_verified_migration_sql($migrationPath, $fixtureVersion));
        $interruptedPdo->exec('PRAGMA user_version = ' . $fixtureVersion);
    }
    $interruptedPdo->exec('CREATE TABLE target_health (sentinel INTEGER)');
    $interruptedPdo = null;
    $interruptedEnvironment = array_merge($environment, [
        'LINKVAULT_DATABASE_PATH' => $interruptedDatabasePath,
    ]);
    $interruptedMigration = run_process([PHP_BINARY, $root . '/bin/migrate.php'], $root, $interruptedEnvironment);
    assert_true($interruptedMigration['exit_code'] !== 0, 'A blocked migration did not fail.');
    $interruptedCheck = new PDO('sqlite:' . $interruptedDatabasePath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $interruptedLinksColumns = array_column(
        $interruptedCheck->query('PRAGMA table_info("links")')->fetchAll(PDO::FETCH_ASSOC),
        'name'
    );
    assert_true(
        (int)$interruptedCheck->query('PRAGMA user_version')->fetchColumn() === 9
            && (int)$interruptedCheck->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'api_rate_limits'")->fetchColumn() === 0
            && !in_array('campaign_name', $interruptedLinksColumns, true)
            && (int)$interruptedCheck->query('SELECT clicks FROM link_daily_stats WHERE link_id = 1')->fetchColumn() === 12,
        'A failed multi-version migration did not roll back all intermediate changes.'
    );
    $interruptedCheck->exec('DROP TABLE target_health');
    $interruptedCheck = null;
    $interruptedRetry = run_process([PHP_BINARY, $root . '/bin/migrate.php'], $root, $interruptedEnvironment);
    assert_true(
        $interruptedRetry['exit_code'] === 0,
        'Migration retry after interruption failed: ' . $interruptedRetry['stderr']
    );
    $interruptedCheck = new PDO('sqlite:' . $interruptedDatabasePath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    assert_true(
        (int)$interruptedCheck->query('PRAGMA user_version')->fetchColumn() === LINKVAULT_SCHEMA_VERSION
            && linkvault_schema_problems($interruptedCheck) === [],
        'Migration retry did not recover to the complete current schema.'
    );
    $interruptedCheck = null;

    $invalidDatabasePath = $testDirectory . DIRECTORY_SEPARATOR . 'invalid-schema.sqlite';
    $invalidEnvironment = array_merge($environment, ['LINKVAULT_DATABASE_PATH' => $invalidDatabasePath]);
    $migration = run_process([PHP_BINARY, $root . '/bin/migrate.php'], $root, $invalidEnvironment);
    assert_true($migration['exit_code'] === 0, 'Schema-validation fixture migration failed.');
    $invalidPdo = new PDO('sqlite:' . $invalidDatabasePath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $invalidPdo->exec('DROP TRIGGER links_fts_update');
    $migration = run_process([PHP_BINARY, $root . '/bin/migrate.php'], $root, $invalidEnvironment);
    assert_true($migration['exit_code'] !== 0, 'Migration must reject an invalid current-version schema.');

    $backupEnvironment = array_merge($environment, [
        'LINKVAULT_BACKUP_DIR' => $validBackupDirectory,
        'LINKVAULT_SQLITE3_BIN' => 'sqlite3',
    ]);
    $backup = run_process([PHP_BINARY, $root . '/bin/backup.php'], $root, $backupEnvironment);
    assert_true($backup['exit_code'] === 0, 'Valid application backup failed: ' . $backup['stderr']);
    $backupFiles = glob($validBackupDirectory . DIRECTORY_SEPARATOR . 'linkvault-*.sqlite') ?: [];
    assert_true(count($backupFiles) === 1, 'Valid application backup did not create exactly one database file.');
    $backupPdo = new PDO('sqlite:' . $backupFiles[0], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    assert_true(!linkvault_schema_problems($backupPdo), 'The verified backup does not match the application schema.');
    $backupPdo = null;
    $restoreDrill = run_process([PHP_BINARY, $root . '/bin/restore-drill.php'], $root, array_merge($backupEnvironment, [
        'LINKVAULT_RESTORE_DRILL_DIR' => $restoreDirectory,
    ]));
    assert_true($restoreDrill['exit_code'] === 0, 'The isolated restore drill failed: ' . $restoreDrill['stderr']);
    $restoreSuccessPath = $validBackupDirectory . DIRECTORY_SEPARATOR . '.last-restore-success.json';
    assert_true(is_file($restoreSuccessPath), 'The restore drill did not write a success marker.');
    $localRestoreMarker = linkvault_read_json_marker($restoreSuccessPath);
    assert_true(
        ($localRestoreMarker['version'] ?? null) === 2
            && ($localRestoreMarker['source'] ?? null) === 'local'
            && ($localRestoreMarker['status'] ?? null) === 'success'
            && is_int($localRestoreMarker['total_links'] ?? null),
        'The local restore drill did not publish a valid v2 marker.'
    );
    $legacyRestoreMarker = linkvault_normalize_restore_marker([
        'version' => 1,
        'completed_at' => time(),
        'source_backup' => basename($backupFiles[0]),
        'schema_version' => LINKVAULT_SCHEMA_VERSION,
        'sampled_links' => 0,
        'duration_ms' => 1,
        'status' => 'success',
    ]);
    assert_true(($legacyRestoreMarker['source'] ?? null) === 'local', 'A v1 restore marker was not accepted as local.');
    $failedRestoreDrill = run_process([PHP_BINARY, $root . '/bin/restore-drill.php'], $root, array_merge($backupEnvironment, [
        'LINKVAULT_RESTORE_DRILL_DIR' => dirname($databasePath),
    ]));
    assert_true($failedRestoreDrill['exit_code'] !== 0, 'The restore drill accepted the live database directory as isolation.');
    assert_true(
        (int)$migrationPdo->query("SELECT COUNT(*) FROM audit_events WHERE action = 'restore_drill'")->fetchColumn() === 0,
        'A restore drill wrote an audit event to the live database.'
    );

    $fakeRclonePath = $testDirectory . DIRECTORY_SEPARATOR . 'fake-rclone.php';
    $fakeAgePath = $testDirectory . DIRECTORY_SEPARATOR . 'fake-age.php';
    $fakeRclone = <<<'PHP'
<?php
$arguments = array_slice($argv, 1);
if (getenv('LINKVAULT_TEST_RCLONE_FAIL') === '1') {
    fwrite(STDERR, "simulated download failure\n");
    exit(20);
}
if (($arguments[0] ?? null) !== 'copyto') {
    fwrite(STDERR, "only copyto is allowed\n");
    exit(21);
}
$position = 1;
if (($arguments[$position] ?? null) === '--config') {
    if (($arguments[$position + 1] ?? null) !== getenv('LINKVAULT_TEST_RCLONE_CONFIG')) {
        exit(22);
    }
    $position += 2;
}
if (($arguments[$position] ?? null) !== getenv('LINKVAULT_TEST_REMOTE_OBJECT')
    || !isset($arguments[$position + 1])
    || isset($arguments[$position + 2])) {
    fwrite(STDERR, "unexpected remote command\n");
    exit(23);
}
exit(copy((string)getenv('LINKVAULT_TEST_REMOTE_FILE'), $arguments[$position + 1]) ? 0 : 24);
PHP;
    $fakeAge = <<<'PHP'
<?php
$arguments = array_slice($argv, 1);
if (getenv('LINKVAULT_TEST_AGE_FAIL') === '1') {
    fwrite(STDERR, "simulated decrypt failure\n");
    exit(30);
}
if (($arguments[0] ?? null) !== '--decrypt'
    || ($arguments[1] ?? null) !== '--identity'
    || ($arguments[2] ?? null) !== getenv('LINKVAULT_TEST_AGE_IDENTITY')
    || ($arguments[3] ?? null) !== '--output'
    || !isset($arguments[4], $arguments[5])
    || isset($arguments[6])) {
    fwrite(STDERR, "unexpected age command\n");
    exit(31);
}
exit(copy($arguments[5], $arguments[4]) ? 0 : 32);
PHP;
    assert_true(file_put_contents($fakeRclonePath, $fakeRclone) !== false, 'Cannot create fake rclone command.');
    assert_true(file_put_contents($fakeAgePath, $fakeAge) !== false, 'Cannot create fake age command.');
    $remoteObjectName = basename($backupFiles[0]) . '.age';
    $remoteObjectPath = $testDirectory . DIRECTORY_SEPARATOR . $remoteObjectName;
    assert_true(copy($backupFiles[0], $remoteObjectPath), 'Cannot create encrypted remote fixture.');
    $remoteObjectHash = hash_file('sha256', $remoteObjectPath);
    assert_true(is_string($remoteObjectHash), 'Cannot hash encrypted remote fixture.');
    $remoteBackupMarkerPath = $validBackupDirectory . DIRECTORY_SEPARATOR . '.last-remote-success.json';
    $remoteBackupMarker = [
        'version' => 1,
        'completed_at' => time(),
        'object_name' => $remoteObjectName,
        'size_bytes' => (int)filesize($remoteObjectPath),
        'sha256' => $remoteObjectHash,
        'verification' => 'remote_size',
    ];
    linkvault_write_json_marker($remoteBackupMarkerPath, $remoteBackupMarker);
    $remoteRestoreEnvironment = array_merge($backupEnvironment, [
        'LINKVAULT_RESTORE_DRILL_DIR' => $remoteRestoreDirectory,
        'LINKVAULT_RESTORE_DRILL_SOURCE' => 'remote',
        'LINKVAULT_RESTORE_AGE_IDENTITY' => $restoreIdentityPath,
        'LINKVAULT_RESTORE_RCLONE_CONFIG' => $restoreRcloneConfigPath,
        'LINKVAULT_BACKUP_RCLONE_REMOTE' => 'production:linkvault',
        'LINKVAULT_RCLONE_BIN' => $fakeRclonePath,
        'LINKVAULT_AGE_BIN' => $fakeAgePath,
        'LINKVAULT_TEST_RCLONE_CONFIG' => realpath($restoreRcloneConfigPath),
        'LINKVAULT_TEST_REMOTE_OBJECT' => 'production:linkvault/' . $remoteObjectName,
        'LINKVAULT_TEST_REMOTE_FILE' => $remoteObjectPath,
        'LINKVAULT_TEST_AGE_IDENTITY' => realpath($restoreIdentityPath),
        'LINKVAULT_TEST_RCLONE_FAIL' => '0',
        'LINKVAULT_TEST_AGE_FAIL' => '0',
    ]);
    $remoteRestore = run_process([PHP_BINARY, $root . '/bin/restore-drill.php'], $root, $remoteRestoreEnvironment);
    assert_true($remoteRestore['exit_code'] === 0, 'The remote restore drill failed: ' . $remoteRestore['stderr']);
    $remoteRestoreMarker = linkvault_read_json_marker($restoreSuccessPath);
    assert_true(
        ($remoteRestoreMarker['version'] ?? null) === 2
            && ($remoteRestoreMarker['source'] ?? null) === 'remote'
            && ($remoteRestoreMarker['source_backup'] ?? null) === $remoteObjectName
            && ($remoteRestoreMarker['schema_version'] ?? null) === LINKVAULT_SCHEMA_VERSION,
        'The remote restore drill did not publish the expected v2 marker.'
    );
    foreach (scandir($remoteRestoreDirectory) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            assert_true(!is_dir($remoteRestoreDirectory . DIRECTORY_SEPARATOR . $entry), 'The remote plaintext run directory was retained.');
        }
    }
    $preservedRemoteSuccess = (string)file_get_contents($restoreSuccessPath);

    $badHashMarker = $remoteBackupMarker;
    $badHashMarker['sha256'] = str_repeat('0', 64);
    linkvault_write_json_marker($remoteBackupMarkerPath, $badHashMarker);
    $remoteHashFailure = run_process([PHP_BINARY, $root . '/bin/restore-drill.php'], $root, $remoteRestoreEnvironment);
    assert_true($remoteHashFailure['exit_code'] !== 0, 'Remote restore accepted an encrypted hash mismatch.');
    $remoteAttempt = linkvault_read_json_marker($validBackupDirectory . DIRECTORY_SEPARATOR . '.last-restore-attempt.json');
    assert_true(($remoteAttempt['phase'] ?? null) === 'hash_validation', 'Remote hash failure did not report its phase.');
    assert_true((string)file_get_contents($restoreSuccessPath) === $preservedRemoteSuccess, 'Hash failure replaced the previous restore success.');

    linkvault_write_json_marker($remoteBackupMarkerPath, $remoteBackupMarker);
    $remoteDecryptFailure = run_process(
        [PHP_BINARY, $root . '/bin/restore-drill.php'],
        $root,
        array_merge($remoteRestoreEnvironment, ['LINKVAULT_TEST_AGE_FAIL' => '1'])
    );
    assert_true($remoteDecryptFailure['exit_code'] !== 0, 'Remote restore accepted a decryption failure.');
    $remoteAttempt = linkvault_read_json_marker($validBackupDirectory . DIRECTORY_SEPARATOR . '.last-restore-attempt.json');
    assert_true(($remoteAttempt['phase'] ?? null) === 'decrypt', 'Remote decrypt failure did not report its phase.');
    assert_true((string)file_get_contents($restoreSuccessPath) === $preservedRemoteSuccess, 'Decrypt failure replaced the previous restore success.');

    $remoteDownloadFailure = run_process(
        [PHP_BINARY, $root . '/bin/restore-drill.php'],
        $root,
        array_merge($remoteRestoreEnvironment, ['LINKVAULT_TEST_RCLONE_FAIL' => '1'])
    );
    assert_true($remoteDownloadFailure['exit_code'] !== 0, 'Remote restore accepted a download failure.');
    $remoteAttempt = linkvault_read_json_marker($validBackupDirectory . DIRECTORY_SEPARATOR . '.last-restore-attempt.json');
    assert_true(($remoteAttempt['phase'] ?? null) === 'download', 'Remote download failure did not report its phase.');
    assert_true((string)file_get_contents($restoreSuccessPath) === $preservedRemoteSuccess, 'Download failure replaced the previous restore success.');

    $failedRestoreStatus = run_process([
        PHP_BINARY,
        '-r',
        '$config = require "config.php"; require "app/bootstrap.php"; require "app/SystemStatus.php"; $status = (new SystemStatus(database($config), $config))->collect(); exit(($status["restore_drill"]["status"] ?? null) === "failure" && ($status["restore_drill"]["state"] ?? null) === "error" && ($status["overall_state"] ?? null) === "error" ? 0 : 1);',
    ], $root, $environment);
    assert_true($failedRestoreStatus['exit_code'] === 0, 'A recent failed restore drill was hidden by its previous success marker.');
    $maintenanceSkipped = run_process([PHP_BINARY, $root . '/bin/notify-maintenance.php'], $root, array_merge($environment, [
        'LINKVAULT_MAINTENANCE_WEBHOOK_URL' => '',
    ]));
    assert_true($maintenanceSkipped['exit_code'] === 0, 'An unconfigured maintenance webhook must be skipped safely.');
    $backupSummary = run_process([
        PHP_BINARY,
        '-r',
        '$config = require "config.php"; require "app/bootstrap.php"; $summary = linkvault_backup_maintenance_summary($config); exit(($summary["count"] ?? -1) === 0 && ($summary["local"]["fresh"] ?? false) && !($summary["remote"]["enabled"] ?? true) ? 0 : 1);',
    ], $root, $environment);
    assert_true($backupSummary['exit_code'] === 0, 'Daily maintenance backup summary did not report the fresh local backup correctly.');

    $versionBackupDirectory = $testDirectory . DIRECTORY_SEPARATOR . 'wrong-version-backups';
    $migrationPdo->exec('PRAGMA user_version = ' . (LINKVAULT_SCHEMA_VERSION - 1));
    $versionBackup = run_process([PHP_BINARY, $root . '/bin/backup.php'], $root, array_merge($environment, [
        'LINKVAULT_BACKUP_DIR' => $versionBackupDirectory,
        'LINKVAULT_SQLITE3_BIN' => 'sqlite3',
    ]));
    $migrationPdo->exec('PRAGMA user_version = ' . LINKVAULT_SCHEMA_VERSION);
    assert_true($versionBackup['exit_code'] !== 0, 'Backup must reject a database with the wrong schema version.');
    assert_true(!(glob($versionBackupDirectory . DIRECTORY_SEPARATOR . '*.sqlite') ?: []), 'Rejected version backup was retained.');

    $invalidBackupDirectory = $testDirectory . DIRECTORY_SEPARATOR . 'invalid-schema-backups';
    $invalidBackup = run_process([PHP_BINARY, $root . '/bin/backup.php'], $root, array_merge($invalidEnvironment, [
        'LINKVAULT_BACKUP_DIR' => $invalidBackupDirectory,
        'LINKVAULT_SQLITE3_BIN' => 'sqlite3',
    ]));
    assert_true($invalidBackup['exit_code'] !== 0, 'Backup must reject an invalid current-version schema.');
    assert_true(!(glob($invalidBackupDirectory . DIRECTORY_SEPARATOR . '*.sqlite') ?: []), 'Rejected schema backup was retained.');

    $constraintPdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $constraintPdo->exec(<<<'SQL'
        CREATE TABLE links (
            id INTEGER PRIMARY KEY,
            slug TEXT NOT NULL,
            target_url TEXT NOT NULL,
            title TEXT NOT NULL,
            clicks INTEGER NOT NULL,
            is_active INTEGER NOT NULL,
            expires_at TEXT,
            deleted_at TEXT,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            last_accessed_at TEXT
        );
        CREATE UNIQUE INDEX links_active_slug_idx ON links(slug) WHERE deleted_at IS NULL;
        CREATE TABLE login_attempts (
            identifier TEXT NOT NULL,
            failures INTEGER NOT NULL,
            window_started_at INTEGER NOT NULL,
            last_failed_at INTEGER NOT NULL,
            locked_until INTEGER NOT NULL
        );
    SQL);
    $constraintProblems = linkvault_schema_problems($constraintPdo);
    assert_true(
        in_array('missing unique constraint links.slug', $constraintProblems, true),
        'A partial slug index must not satisfy schema validation.'
    );
    assert_true(
        in_array('invalid primary key login_attempts', $constraintProblems, true),
        'The login throttle primary key must be validated.'
    );

    $ipv6Check = run_process([
        PHP_BINARY,
        '-r',
        'require "app/bootstrap.php"; $value = configured_base_url(["base_url" => "http://[::1]:8080"]); exit(($value["host"] ?? null) === "::1" ? 0 : 1);',
    ], $root, $environment);
    assert_true($ipv6Check['exit_code'] === 0, 'A valid IPv6-literal base URL was rejected.');

    $routePrefixCheck = run_process([
        PHP_BINARY,
        '-r',
        '$_SERVER["SCRIPT_NAME"] = "/s/index.php"; $_SERVER["REQUEST_URI"] = "/slogin"; require "app/bootstrap.php"; exit(request_path() === "/slogin" ? 0 : 1);',
    ], $root, $environment);
    assert_true($routePrefixCheck['exit_code'] === 0, 'A route prefix without a path boundary was stripped.');

    $reservedRouteCheck = run_process([
        PHP_BINARY,
        '-r',
        'require "app/bootstrap.php"; foreach (array_keys(fixed_routes()) as $path) { $slug = explode("/", ltrim($path, "/"), 2)[0]; if ($slug !== "" && preg_match("/^[A-Za-z0-9_-]{3,64}$/", $slug) && valid_slug($slug)) { exit(1); } } exit(valid_slug("import-report") || valid_slug("assets") ? 1 : 0);',
    ], $root, $environment);
    assert_true($reservedRouteCheck['exit_code'] === 0, 'A fixed route can still be created as a short code.');
    $reservedDirectoryCheck = run_process([
        PHP_BINARY,
        '-r',
        'require "app/bootstrap.php"; foreach ([getcwd(), getcwd() . "/public"] as $directory) { foreach (scandir($directory) ?: [] as $entry) { if ($entry !== "." && $entry !== ".." && is_dir($directory . DIRECTORY_SEPARATOR . $entry) && preg_match("/^[A-Za-z0-9_-]{3,64}$/", $entry) && valid_slug($entry)) { exit(1); } } } exit(0);',
    ], $root, $environment);
    assert_true($reservedDirectoryCheck['exit_code'] === 0, 'A project or public directory can still be created as a short code.');

    $loopbackCheck = run_process([
        PHP_BINARY,
        '-r',
        'require "app/bootstrap.php"; exit(is_loopback_address("127.0.0.42") && is_loopback_address("::1") && is_loopback_address("::ffff:127.0.0.1") && !is_loopback_address("203.0.113.10") ? 0 : 1);',
    ], $root, $environment);
    assert_true($loopbackCheck['exit_code'] === 0, 'Loopback peer-address validation is incorrect.');

    $remoteLocalhostCheck = run_process([
        PHP_BINARY,
        '-r',
        '$_SERVER["HTTP_HOST"] = "localhost"; $_SERVER["REMOTE_ADDR"] = "203.0.113.10"; $_SERVER["REQUEST_METHOD"] = "GET"; require "app/bootstrap.php"; enforce_request_host(["base_url" => "", "application_log_path" => ""]);',
    ], $root, $environment);
    assert_true(
        str_contains($remoteLocalhostCheck['stdout'], '服务域名尚未配置'),
        'A remote peer using Host: localhost bypassed the BASE_URL requirement.'
    );
