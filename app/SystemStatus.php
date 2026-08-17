<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/database_schema.php';
require_once dirname(__DIR__) . '/lib/operational_status.php';
require_once __DIR__ . '/AdminSecurityService.php';
require_once __DIR__ . '/LifecycleWebhookService.php';

final class SystemStatus
{
    public function __construct(private readonly PDO $pdo, private readonly array $config)
    {
    }

    public function collect(): array
    {
        $checks = readiness_checks($this->config, true);
        $databasePath = (string)($this->config['database_path'] ?? '');
        $databaseDirectory = $databasePath === '' ? '' : dirname($databasePath);
        $freeBytes = $databaseDirectory !== '' ? @disk_free_space($databaseDirectory) : false;
        $totalBytes = $databaseDirectory !== '' ? @disk_total_space($databaseDirectory) : false;
        $schemaVersion = (int)$this->pdo->query('PRAGMA user_version')->fetchColumn();
        $schemaProblems = linkvault_schema_problems($this->pdo);
        $backupHealth = linkvault_backup_health_status($this->config);
        $localBackup = $backupHealth['local'];
        $remoteBackup = $backupHealth['remote'];
        $restoreDrill = linkvault_restore_drill_status($this->config);
        $analytics = linkvault_analytics_status($this->config);
        $targetHealth = linkvault_target_health_status($this->config);
        $syntheticMonitor = linkvault_synthetic_monitor_status($this->config);
        $apiToken = (string)($this->config['api_token'] ?? '');
        $legacyApiConfigured = $apiToken !== '';
        $legacyApiEnabled = strlen($apiToken) >= 24;
        $storedTokenCount = (int)$this->pdo->query('SELECT COUNT(*) FROM api_tokens')->fetchColumn();
        $apiTokenAlertCount = (int)$this->pdo->query('SELECT COUNT(*) FROM api_token_alerts')->fetchColumn();
        $activeStoredTokenCount = 0;
        foreach ($this->pdo->query(<<<'SQL'
            SELECT scopes, expires_at, rotation_expires_at FROM api_tokens WHERE revoked_at IS NULL
        SQL) as $storedToken) {
            $expiresAt = $storedToken['expires_at'] ?? null;
            $rotationExpiresAt = $storedToken['rotation_expires_at'] ?? null;
            try {
                $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
                $naturalActive = !is_string($expiresAt) || $expiresAt === ''
                    || new DateTimeImmutable($expiresAt) > $now;
                $rotationActive = !is_string($rotationExpiresAt) || $rotationExpiresAt === ''
                    || new DateTimeImmutable($rotationExpiresAt) > $now;
                $scopes = array_values(array_filter(explode(' ', (string)($storedToken['scopes'] ?? ''))));
                if ($naturalActive && $rotationActive && in_array('links:create', $scopes, true)) {
                    $activeStoredTokenCount++;
                }
            } catch (Throwable) {
            }
        }
        $apiConfigured = $legacyApiConfigured || $storedTokenCount > 0;
        $apiEnabled = $legacyApiEnabled || $activeStoredTokenCount > 0;
        $databaseHealthy = !in_array(false, [$checks['database_read'], $checks['database_write']], true);
        $schemaHealthy = $schemaVersion === LINKVAULT_SCHEMA_VERSION && !$schemaProblems;
        $localBackupState = !empty($localBackup['fresh'])
            ? 'ok'
            : (!empty($localBackup['available']) ? 'attention' : 'error');
        $remoteBackupState = empty($remoteBackup['enabled'])
            ? 'unconfigured'
            : (!empty($remoteBackup['fresh'])
                ? 'ok'
                : (!empty($remoteBackup['available']) ? 'attention' : 'error'));
        $restoreDrillState = empty($restoreDrill['available'])
            ? 'unconfigured'
            : (($restoreDrill['status'] ?? null) === 'failure'
                ? 'error'
                : (!empty($restoreDrill['fresh']) ? 'ok' : 'attention'));
        $analyticsState = empty($analytics['available'])
            ? (($analytics['reason'] ?? null) === 'missing_marker' ? 'attention' : 'error')
            : (!empty($analytics['consecutive_failures'])
                ? 'error'
                : (empty($analytics['fresh']) || empty($analytics['log_exists']) || empty($analytics['complete'])
                ? 'attention'
                 : 'ok'));
        $targetHealthState = empty($targetHealth['enabled'])
            ? 'unconfigured'
            : (empty($targetHealth['available'])
                ? (($targetHealth['reason'] ?? null) === 'invalid_marker' ? 'error' : 'attention')
                : (($targetHealth['status'] ?? null) === 'failure'
                    ? 'error'
                    : (empty($targetHealth['fresh'])
                        || (int)$targetHealth['issues'] > 0
                        || (int)$targetHealth['backlog'] > 0 ? 'attention' : 'ok')));
        $syntheticConfiguredProbeCount = count(array_filter(
            (array)$syntheticMonitor['probes'],
            static fn (array $probe): bool => in_array(($probe['id'] ?? null), ['api', 'canary'], true)
                && ($probe['status'] ?? null) !== 'unconfigured'
        ));
        $syntheticRequiredConfigured = $syntheticConfiguredProbeCount === 2;
        $syntheticState = empty($syntheticMonitor['available'])
            ? (($syntheticMonitor['reason'] ?? null) === 'invalid_marker' ? 'error' : 'attention')
            : (($syntheticMonitor['status'] ?? null) === 'failure'
                ? 'error'
                : (empty($syntheticMonitor['fresh']) || !$syntheticRequiredConfigured ? 'attention' : 'ok'));
        $apiState = !$apiConfigured
            ? 'unconfigured'
            : ($apiEnabled && (!$legacyApiConfigured || $legacyApiEnabled)
                ? ($apiTokenAlertCount > 0 ? 'attention' : 'ok') : 'error');
        $adminSecurity = (new AdminSecurityService($this->pdo, $this->config))->status();
        $adminSecurityState = empty($adminSecurity['enabled'])
            ? 'unconfigured'
            : (empty($adminSecurity['available'])
                ? 'error'
                : ((int)$adminSecurity['recovery_codes_remaining'] > 0 ? 'ok' : 'attention'));

        $window = max(300, (int)($this->config['status_anomaly_window_seconds'] ?? 86400));
        $writeLock = $this->writeLockStatus($window);
        $writeLockState = $writeLock['failure_count'] > 0 ? 'attention' : 'ok';
        $anomalyQuery = $this->pdo->prepare(<<<'SQL'
            SELECT created_at, action, entity_type, entity_id, details_json
            FROM audit_events
            WHERE outcome = 'failure'
              AND action <> 'link_password_unlock'
              AND created_at >= :since
            ORDER BY created_at DESC, id DESC
            LIMIT 10
        SQL);
        $anomalyQuery->execute(['since' => gmdate('Y-m-d\TH:i:s\Z', time() - $window)]);
        $recentAnomalies = $anomalyQuery->fetchAll();
        $auditState = $recentAnomalies ? 'attention' : 'ok';
        $lifecycleCounts = ['pending' => 0, 'delivered' => 0, 'dead' => 0];
        foreach ($this->pdo->query('SELECT status, COUNT(*) AS event_count FROM webhook_outbox GROUP BY status') as $row) {
            $lifecycleCounts[(string)$row['status']] = (int)$row['event_count'];
        }
        $lifecycleEnabled = LifecycleWebhookService::enabled($this->config);
        $lifecycleState = !$lifecycleEnabled ? 'unconfigured' : ($lifecycleCounts['dead'] > 0 ? 'error' : 'ok');

        $componentStates = [
            $databaseHealthy ? 'ok' : 'error',
            $schemaHealthy ? 'ok' : 'error',
            $checks['disk_space'] ? 'ok' : 'error',
            $localBackupState,
            $remoteBackupState,
            $restoreDrillState,
            $analyticsState,
            $targetHealthState,
            $syntheticState,
            $apiState,
            $adminSecurityState,
            $auditState,
            $lifecycleState,
            $writeLockState,
        ];

        $warnings = [];
        if (in_array(false, $checks, true)) {
            $warnings[] = '数据库读写或磁盘健康检查未通过。';
        }
        if ($schemaVersion !== LINKVAULT_SCHEMA_VERSION || $schemaProblems) {
            $warnings[] = '数据库 Schema 与当前应用不一致。';
        }
        if (empty($localBackup['fresh'])) {
            $warnings[] = '最近本地备份缺失、过期或校验失败。';
        }
        if (!empty($remoteBackup['enabled']) && empty($remoteBackup['fresh'])) {
            $warnings[] = '最近异地备份缺失或过期。';
        }
        if (($restoreDrill['status'] ?? null) === 'failure') {
            $warnings[] = '最近一次自动恢复演练失败。';
        } elseif (empty($restoreDrill['source_matches']) && !empty($restoreDrill['available'])) {
            $warnings[] = '最近成功的恢复演练未使用当前配置的备份来源。';
        } elseif ($restoreDrillState === 'attention') {
            $warnings[] = '自动恢复演练尚未成功或已超过允许周期。';
        }
        if (($analytics['reason'] ?? null) === 'missing_marker') {
            $warnings[] = '访问分析尚无聚合运行记录。';
        } elseif (($analytics['reason'] ?? null) === 'invalid_marker') {
            $warnings[] = '访问分析聚合状态文件无效。';
        } elseif (($analytics['reason'] ?? null) === 'aggregation_failed') {
            $warnings[] = '访问分析聚合最近连续失败 ' . (int)$analytics['consecutive_failures'] . ' 次。';
        } elseif (($analytics['reason'] ?? null) === 'future_marker') {
            $warnings[] = '访问分析聚合完成时间晚于当前系统时间。';
        } elseif (($analytics['reason'] ?? null) === 'stale') {
            $warnings[] = '访问分析聚合状态已超过允许更新时间。';
        } elseif (!empty($analytics['available']) && empty($analytics['log_exists'])) {
            $warnings[] = '访问分析日志不存在。';
        } elseif (!empty($analytics['available']) && empty($analytics['complete'])) {
            $warnings[] = '访问分析聚合仍有未处理日志积压。';
        }
        if (!empty($targetHealth['enabled'])) {
            if (($targetHealth['reason'] ?? null) === 'missing_marker') {
                $warnings[] = '目标健康检查尚无运行记录。';
            } elseif (($targetHealth['reason'] ?? null) === 'invalid_marker') {
                $warnings[] = '目标健康检查状态文件无效。';
            } elseif (($targetHealth['reason'] ?? null) === 'checker_failed') {
                $warnings[] = '目标健康检查任务最近运行失败。';
            } elseif (($targetHealth['reason'] ?? null) === 'stale') {
                $warnings[] = '目标健康检查心跳已过期。';
            } elseif (($targetHealth['reason'] ?? null) === 'future_marker') {
                $warnings[] = '目标健康检查完成时间晚于当前系统时间。';
            }
            if ((int)$targetHealth['issues'] > 0) {
                $warnings[] = '最近一批目标健康检查发现 ' . (int)$targetHealth['issues'] . ' 条异常。';
            }
            if ((int)$targetHealth['backlog'] > 0) {
                $warnings[] = '目标健康检查仍有 ' . (int)$targetHealth['backlog'] . ' 条到期积压。';
            }
        }
        if (($syntheticMonitor['reason'] ?? null) === 'missing_marker') {
            $warnings[] = '合成监控尚无运行记录。';
        } elseif (($syntheticMonitor['reason'] ?? null) === 'invalid_marker') {
            $warnings[] = '合成监控状态文件无效。';
        } elseif (($syntheticMonitor['reason'] ?? null) === 'future_marker') {
            $warnings[] = '合成监控完成时间晚于当前系统时间。';
        } elseif (($syntheticMonitor['reason'] ?? null) === 'stale') {
            $warnings[] = '合成监控结果已超过允许更新时间。';
        } elseif (($syntheticMonitor['status'] ?? null) === 'failure') {
            $warnings[] = '合成监控发现 ' . (int)$syntheticMonitor['failed'] . ' 个异常探针。';
        } elseif (!empty($syntheticMonitor['available']) && !$syntheticRequiredConfigured) {
            $warnings[] = '合成监控 API 或 Canary 短链尚未启用。';
        }
        if ($recentAnomalies) {
            $warnings[] = '最近运行窗口内存在失败审计事件。';
        }
        if ($writeLock['failure_count'] > 0) {
            $warnings[] = '最近因 SQLite 写锁竞争失败 ' . $writeLock['failure_count'] . ' 次。';
        }
        if ($apiConfigured && !$apiEnabled) {
            $warnings[] = '没有可用的 API Token；数据库 Token 已失效或吊销。';
        } elseif ($legacyApiConfigured && !$legacyApiEnabled) {
            $warnings[] = 'LINKVAULT_API_TOKEN 已配置但不符合至少 24 个字符的要求。';
        }
        if ($apiTokenAlertCount > 0) {
            $warnings[] = 'API Token 存在 ' . $apiTokenAlertCount . ' 类异常使用告警。';
        }
        if (!empty($adminSecurity['enabled']) && empty($adminSecurity['available'])) {
            $warnings[] = 'TOTP 已启用，但当前进程无法解密密钥；请使用恢复码并检查 LINKVAULT_SECURITY_KEY。';
        }
        if ($lifecycleCounts['dead'] > 0) {
            $warnings[] = '生命周期 Webhook 有 ' . $lifecycleCounts['dead'] . ' 条死信待处理。';
        }
        if (!empty($adminSecurity['enabled']) && (int)$adminSecurity['recovery_codes_remaining'] === 0) {
            $warnings[] = 'TOTP 没有剩余恢复码，请尽快重新生成。';
        }
        $overallState = $this->reduceStates($componentStates);

        return [
            'release' => release_center_metadata($this->config),
            'database' => [
                'healthy' => $databaseHealthy,
                'state' => $databaseHealthy ? 'ok' : 'error',
                'read' => $checks['database_read'],
                'write' => $checks['database_write'],
                'size_bytes' => is_file($databasePath) ? (int)filesize($databasePath) : 0,
            ],
            'write_lock' => array_merge($writeLock, ['state' => $writeLockState]),
            'schema' => [
                'healthy' => $schemaHealthy,
                'state' => $schemaHealthy ? 'ok' : 'error',
                'current' => $schemaVersion,
                'expected' => LINKVAULT_SCHEMA_VERSION,
                'problems' => $schemaProblems,
            ],
            'disk' => [
                'healthy' => $checks['disk_space'],
                'state' => $checks['disk_space'] ? 'ok' : 'error',
                'free_bytes' => $freeBytes === false ? 0 : (int)$freeBytes,
                'total_bytes' => $totalBytes === false ? 0 : (int)$totalBytes,
                'minimum_bytes' => max(1, (int)($this->config['health_min_free_bytes'] ?? 128 * 1024 * 1024)),
            ],
            'local_backup' => array_merge($localBackup, ['state' => $localBackupState]),
            'remote_backup' => array_merge($remoteBackup, ['state' => $remoteBackupState]),
            'restore_drill' => array_merge($restoreDrill, ['state' => $restoreDrillState]),
            'analytics' => array_merge($analytics, ['state' => $analyticsState]),
            'target_health' => array_merge($targetHealth, ['state' => $targetHealthState]),
            'synthetic_monitor' => array_merge($syntheticMonitor, ['state' => $syntheticState]),
            'api' => [
                'configured' => $apiConfigured,
                'enabled' => $apiEnabled,
                'healthy' => in_array($apiState, ['ok', 'unconfigured'], true),
                'state' => $apiState,
                'stored_count' => $storedTokenCount,
                'active_stored_count' => $activeStoredTokenCount,
                'legacy_enabled' => $legacyApiEnabled,
                'alert_count' => $apiTokenAlertCount,
            ],
            'admin_security' => array_merge($adminSecurity, ['state' => $adminSecurityState]),
            'lifecycle_webhook' => [
                'enabled' => $lifecycleEnabled,
                'state' => $lifecycleState,
                'pending' => $lifecycleCounts['pending'],
                'delivered' => $lifecycleCounts['delivered'],
                'dead' => $lifecycleCounts['dead'],
            ],
            'audit' => [
                'state' => $auditState,
                'recent_failures' => count($recentAnomalies),
                'window_seconds' => $window,
            ],
            'data_governance' => [
                'raw_log_retention_days' => max(1, (int)($this->config['analytics_raw_log_retention_days'] ?? 30)),
                'hourly_retention_days' => max(1, (int)($this->config['analytics_hourly_retention_days'] ?? 90)),
                'aggregate_retention_days' => max(1, (int)($this->config['analytics_retention_days'] ?? 365)),
                'collects_ip_or_uv_fingerprint' => false,
            ],
            'overall_state' => $overallState,
            'warnings' => $warnings,
            'recent_anomalies' => $recentAnomalies,
        ];
    }

    private function reduceStates(array $states): string
    {
        foreach (['error', 'attention'] as $state) {
            if (in_array($state, $states, true)) {
                return $state;
            }
        }
        return 'ok';
    }

    /** @return array{failure_count: int, last_failure_at: ?string, average_wait_ms: int, max_wait_ms: int, window_seconds: int, busy_timeout_ms: int, retry_attempts: int} */
    private function writeLockStatus(int $window): array
    {
        $result = [
            'failure_count' => 0,
            'last_failure_at' => null,
            'average_wait_ms' => 0,
            'max_wait_ms' => 0,
            'window_seconds' => $window,
            'busy_timeout_ms' => max(1, (int)($this->config['redirect_busy_timeout_ms'] ?? 250)),
            'retry_attempts' => max(1, (int)($this->config['redirect_retry_attempts'] ?? 2)),
        ];
        $path = (string)($this->config['application_log_path'] ?? '');
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            return $result;
        }
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return $result;
        }
        try {
            $size = (int)(filesize($path) ?: 0);
            $readBytes = min($size, 2 * 1024 * 1024);
            if ($readBytes < $size) {
                fseek($handle, -$readBytes, SEEK_END);
                fgets($handle);
            }
            $since = time() - $window;
            $waitTotal = 0;
            while (($line = fgets($handle)) !== false) {
                $entry = json_decode($line, true);
                if (!is_array($entry)) {
                    continue;
                }
                $event = $entry['event'] ?? null;
                if ($event !== 'sqlite_lock_wait') {
                    continue;
                }
                $occurredAt = strtotime((string)($entry['time'] ?? ''));
                if ($occurredAt === false || $occurredAt < $since) {
                    continue;
                }
                $wait = max(0, (int)($entry['duration_ms'] ?? 0));
                $result['failure_count']++;
                $waitTotal += $wait;
                $result['max_wait_ms'] = max($result['max_wait_ms'], $wait);
                if ($result['last_failure_at'] === null || (string)$entry['time'] > $result['last_failure_at']) {
                    $result['last_failure_at'] = (string)$entry['time'];
                }
            }
            if ($result['failure_count'] > 0) {
                $result['average_wait_ms'] = (int)round($waitTotal / $result['failure_count']);
            }
        } finally {
            fclose($handle);
        }
        return $result;
    }
}
