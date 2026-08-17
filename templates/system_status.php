<?php
$statusLabels = ['ok' => '正常', 'attention' => '关注', 'error' => '异常', 'unconfigured' => '未配置'];
$overallLabels = ['ok' => '系统正常', 'attention' => '需要关注', 'error' => '存在异常', 'unconfigured' => '未配置'];
$overallState = (string)($systemStatus['overall_state'] ?? 'error');
$restoreStatus = (string)($systemStatus['restore_drill']['status'] ?? '');
$restoreCompletedAt = (int)($systemStatus['restore_drill']['completed_at'] ?? 0);
$restoreLastSuccessAt = (int)($systemStatus['restore_drill']['last_success_at'] ?? 0);
$restoreSource = (string)($systemStatus['restore_drill']['source'] ?? '');
$restoreSourceLabel = ['local' => '本地源', 'remote' => '远端源'][$restoreSource] ?? '来源未知';
$restorePhase = (string)($systemStatus['restore_drill']['phase'] ?? '');
$restorePrimary = $restoreStatus === 'failure'
    ? '最近一次失败' . ($restoreCompletedAt > 0 ? ' · ' . gmdate('Y-m-d H:i:s', $restoreCompletedAt) . ' UTC' : '')
    : ($restoreCompletedAt > 0 ? '最近一次成功 · ' . gmdate('Y-m-d H:i:s', $restoreCompletedAt) . ' UTC' : '暂无演练记录');
$restoreSecondary = $restoreStatus === 'failure'
    ? $restoreSourceLabel . ($restorePhase !== '' ? ' · 阶段 ' . $restorePhase : '')
        . ($restoreLastSuccessAt > 0 ? ' · 上次成功 ' . gmdate('Y-m-d H:i:s', $restoreLastSuccessAt) . ' UTC' : '')
    : ($restoreCompletedAt > 0
        ? $restoreSourceLabel . (!empty($systemStatus['restore_drill']['duration_ms']) ? ' · ' . (int)$systemStatus['restore_drill']['duration_ms'] . ' ms' : '')
        : '等待演练');
$adminSecurity = $systemStatus['admin_security'] ?? [
    'state' => 'unconfigured', 'enabled' => false, 'available' => false, 'recovery_codes_remaining' => 0,
];
$analyticsStatus = $systemStatus['analytics'] ?? [
    'state' => 'unconfigured', 'available' => false, 'fresh' => false, 'log_exists' => false,
    'offset' => 0, 'observed_size' => 0, 'backlog_bytes' => 0, 'read' => 0, 'accepted' => 0, 'skipped' => 0,
    'failure_count' => 0, 'consecutive_failures' => 0, 'consumer_lag_seconds' => null,
];
$analyticsCompletedAt = (int)($analyticsStatus['completed_at'] ?? 0);
$analyticsCompletedIso = $analyticsCompletedAt > 0 ? gmdate('Y-m-d\TH:i:s\Z', $analyticsCompletedAt) : '';
$targetHealthStatus = $systemStatus['target_health'] ?? [
    'state' => 'unconfigured', 'enabled' => false, 'completed_at' => 0,
    'processed' => 0, 'healthy' => 0, 'issues' => 0, 'backlog' => 0,
];
$targetHealthCompletedAt = (int)($targetHealthStatus['completed_at'] ?? 0);
$releaseStatus = $systemStatus['release'] ?? [
    'version' => 'development', 'build_time' => 'unknown', 'schema_version' => 0,
    'changelog' => [], 'rollback_version' => '',
];
$syntheticStatus = $systemStatus['synthetic_monitor'] ?? [
    'state' => 'attention', 'available' => false, 'fresh' => false, 'status' => null,
    'completed_at' => 0, 'duration_ms' => 0, 'failed' => 0, 'probes' => [],
];
$syntheticCompletedAt = (int)($syntheticStatus['completed_at'] ?? 0);
$syntheticProbes = (array)($syntheticStatus['probes'] ?? []);
$syntheticOk = count(array_filter(
    $syntheticProbes,
    static fn (array $probe): bool => ($probe['status'] ?? null) === 'ok'
));
$syntheticUnconfigured = count(array_filter(
    $syntheticProbes,
    static fn (array $probe): bool => ($probe['status'] ?? null) === 'unconfigured'
));
$probeStatusLabels = ['ok' => '通过', 'error' => '失败', 'unconfigured' => '未配置'];
$writeLockStatus = $systemStatus['write_lock'] ?? [
    'state' => 'ok', 'failure_count' => 0, 'last_failure_at' => null,
    'average_wait_ms' => 0, 'max_wait_ms' => 0, 'busy_timeout_ms' => 250, 'retry_attempts' => 2,
];
$dataGovernance = $systemStatus['data_governance'] ?? [
    'raw_log_retention_days' => 30,
    'hourly_retention_days' => 90,
    'aggregate_retention_days' => 365,
    'collects_ip_or_uv_fingerprint' => false,
];
$statusItems = [
    ['database', '数据库', $systemStatus['database']['state'], '读取 ' . ($systemStatus['database']['read'] ? '正常' : '异常') . ' · 写入 ' . ($systemStatus['database']['write'] ? '正常' : '异常'), format_bytes((int)$systemStatus['database']['size_bytes']), '#status-runbook-database'],
    ['write_lock', 'SQLite 写锁', $writeLockStatus['state'], (int)$writeLockStatus['failure_count'] . ' 次失败 · 平均 ' . (int)$writeLockStatus['average_wait_ms'] . ' ms', '最大 ' . (int)$writeLockStatus['max_wait_ms'] . ' ms · 跳转容量边界 ' . (int)$writeLockStatus['busy_timeout_ms'] . ' ms × ' . (int)$writeLockStatus['retry_attempts'] . ' 次', '#status-runbook-write_lock'],
    ['schema', 'Schema', $systemStatus['schema']['state'], '当前 v' . (int)$systemStatus['schema']['current'] . ' · 期望 v' . (int)$systemStatus['schema']['expected'], $systemStatus['schema']['problems'] ? implode('；', $systemStatus['schema']['problems']) : '结构完整', '#status-runbook-schema'],
    ['disk', '磁盘空间', $systemStatus['disk']['state'], '可用 ' . format_bytes((int)$systemStatus['disk']['free_bytes']) . ' / ' . format_bytes((int)$systemStatus['disk']['total_bytes']), '最低要求 ' . format_bytes((int)$systemStatus['disk']['minimum_bytes']), '#status-runbook-disk'],
    ['local_backup', '本地备份', $systemStatus['local_backup']['state'], !empty($systemStatus['local_backup']['completed_at']) ? gmdate('Y-m-d H:i:s', (int)$systemStatus['local_backup']['completed_at']) . ' UTC' : '暂无有效记录', !empty($systemStatus['local_backup']['size_bytes']) ? format_bytes((int)$systemStatus['local_backup']['size_bytes']) : '等待备份', '#status-runbook-local_backup'],
    ['remote_backup', '异地备份', $systemStatus['remote_backup']['state'], empty($systemStatus['remote_backup']['enabled']) ? '未配置' : (!empty($systemStatus['remote_backup']['completed_at']) ? gmdate('Y-m-d H:i:s', (int)$systemStatus['remote_backup']['completed_at']) . ' UTC' : '暂无有效记录'), !empty($systemStatus['remote_backup']['object_name']) ? (string)$systemStatus['remote_backup']['object_name'] : '', '#status-runbook-remote_backup'],
    ['api', 'API', $systemStatus['api']['state'], $systemStatus['api']['enabled'] ? '已启用' : (!empty($systemStatus['api']['configured']) ? '无可用 Token' : '未配置'), (int)$systemStatus['api']['active_stored_count'] . ' 个数据库 Token 可用' . (!empty($systemStatus['api']['legacy_enabled']) ? ' · 环境 Token 已启用' : ''), list_path('', 'active', 1, 'all', 'created_desc', '', false, 0, 'api')],
    ['admin_security', '管理员验证', $adminSecurity['state'], !empty($adminSecurity['enabled']) ? 'TOTP 已启用' : '仅密码登录', !empty($adminSecurity['enabled']) ? (int)$adminSecurity['recovery_codes_remaining'] . ' 个恢复码可用' : (!empty($adminSecurity['available']) ? '可选启用' : '安全密钥未配置'), list_path('', 'active', 1, 'all', 'created_desc', '', false, 0, 'security')],
    ['restore', '恢复演练', $systemStatus['restore_drill']['state'], $restorePrimary, $restoreSecondary, '#status-runbook-restore'],
    ['target_health', '目标健康检查', $targetHealthStatus['state'], empty($targetHealthStatus['enabled'])
        ? '未启用'
        : ($targetHealthCompletedAt > 0 ? gmdate('Y-m-d H:i:s', $targetHealthCompletedAt) . ' UTC' : '暂无运行记录'),
        '处理 ' . (int)$targetHealthStatus['processed'] . ' · 正常 ' . (int)$targetHealthStatus['healthy']
            . ' · 问题 ' . (int)$targetHealthStatus['issues'] . ' · 积压 ' . (int)$targetHealthStatus['backlog'], list_path('', 'active', 1, 'all', 'created_desc', '', false, 0, 'maintenance', 'target_health')],
    ['synthetic_monitor', '合成监控', $syntheticStatus['state'], $syntheticCompletedAt > 0
        ? gmdate('Y-m-d H:i:s', $syntheticCompletedAt) . ' UTC'
        : '暂无运行记录',
        '通过 ' . $syntheticOk . ' · 失败 ' . (int)$syntheticStatus['failed']
            . ($syntheticUnconfigured > 0 ? ' · 未配置 ' . $syntheticUnconfigured : ''), '#status-runbook-synthetic_monitor'],
    ['audit', '近期审计', $systemStatus['audit']['state'], (int)$systemStatus['audit']['recent_failures'] . ' 条失败事件', '最近 ' . max(1, (int)round((int)$systemStatus['audit']['window_seconds'] / 3600)) . ' 小时', list_path('', 'active', 1, 'all', 'created_desc', '', false, 0, 'audit')],
];
?>
<section class="status-center">
    <?php if ($section === 'status'): ?>
    <div class="status-title"><div><h2>系统状态中心</h2><p class="muted">数据库、备份、访问分析、API 与恢复能力的当前状态。</p></div><span class="live-status <?= e($overallState) ?>-status"><?= e($overallLabels[$overallState] ?? '存在异常') ?></span></div>
    <?php if ($systemStatus['warnings']): ?><div class="status-alerts <?= e($overallState) ?>" role="alert"><strong>状态提示</strong><ul><?php foreach ($systemStatus['warnings'] as $warning): ?><li><?= e((string)$warning) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <section class="panel release-center" id="release-center" aria-labelledby="release-center-title">
        <div class="stats-heading"><span class="section-heading"><span class="section-icon"><svg class="icon" aria-hidden="true"><use href="#icon-restore"/></svg></span><div><h3 id="release-center-title">发布版本中心</h3><p class="muted">当前部署与已验证回滚目标</p></div></span><span class="release-version-badge">当前版本</span></div>
        <dl class="release-metadata-grid">
            <div><dt>版本号</dt><dd><code><?= e((string)$releaseStatus['version']) ?></code></dd></div>
            <div><dt>构建时间</dt><dd><?php if ((string)$releaseStatus['build_time'] !== 'unknown'): ?><time datetime="<?= e((string)$releaseStatus['build_time']) ?>" data-local-time><?= e((string)$releaseStatus['build_time']) ?> UTC</time><?php else: ?>未知<?php endif; ?></dd></div>
            <div><dt>Schema 版本</dt><dd><code>v<?= (int)$releaseStatus['schema_version'] ?></code></dd></div>
            <div><dt>回滚版本</dt><dd><?php if ((string)$releaseStatus['rollback_version'] !== ''): ?><code><?= e((string)$releaseStatus['rollback_version']) ?></code><?php else: ?><span class="muted">未指定</span><?php endif; ?></dd></div>
        </dl>
        <div class="release-changelog"><h4>变更记录</h4><?php if ((array)$releaseStatus['changelog']): ?><ul><?php foreach ((array)$releaseStatus['changelog'] as $change): ?><li><?= e((string)$change) ?></li><?php endforeach; ?></ul><?php else: ?><p class="muted">当前发布未提供变更记录。</p><?php endif; ?></div>
        <div class="status-item-actions"><a href="#status-runbook-release">查看发布与回滚手册</a></div>
    </section>
    <div class="status-grid">
        <?php foreach ($statusItems as [$key, $label, $state, $primary, $secondary, $actionUrl]): ?><article class="status-item"><div class="status-item-heading"><h3><?= e((string)$label) ?></h3><span class="health-dot <?= e((string)$state) ?>"><?= e($statusLabels[$state] ?? '异常') ?></span></div><strong><?= e((string)$primary) ?></strong><span class="muted"><?= e((string)$secondary) ?></span><div class="status-item-actions"><?php if ($state !== 'ok'): ?><a class="button button-secondary button-small" href="<?= e((string)$actionUrl) ?>">立即处理</a><?php endif; ?><a href="#status-runbook-<?= e((string)$key) ?>">运行手册</a></div></article><?php endforeach; ?>
        <article class="status-item analytics-status-item">
            <div class="status-item-heading"><h3>访问分析聚合</h3><span class="health-dot <?= e((string)$analyticsStatus['state']) ?>"><?= e($statusLabels[$analyticsStatus['state']] ?? '异常') ?></span></div>
            <?php if ($analyticsCompletedIso !== ''): ?>
                <strong><time datetime="<?= e($analyticsCompletedIso) ?>" data-local-time><?= e($analyticsCompletedIso) ?> UTC</time></strong>
                <span class="muted">上次聚合 · <span data-timezone-label>UTC</span></span>
            <?php else: ?>
                <strong>暂无聚合记录</strong><span class="muted">等待首次成功运行</span>
            <?php endif; ?>
            <?php if (!empty($analyticsStatus['available'])): ?>
                <span class="muted">待处理日志量 <?= e(format_bytes((int)$analyticsStatus['backlog_bytes'])) ?> · 偏移 <?= e(format_bytes((int)$analyticsStatus['offset'])) ?> / <?= e(format_bytes((int)$analyticsStatus['observed_size'])) ?></span>
                <span class="muted">本次读取 <?= (int)$analyticsStatus['read'] ?> · 接收 <?= (int)$analyticsStatus['accepted'] ?> · 跳过 <?= (int)$analyticsStatus['skipped'] ?><?= empty($analyticsStatus['log_exists']) ? ' · 日志不存在' : '' ?></span>
                <span class="muted">吞吐 <?= (int)($analyticsStatus['throughput_per_second'] ?? 0) ?> 行/秒 · 耗时 <?= (int)($analyticsStatus['duration_ms'] ?? 0) ?> ms · 锁等待 <?= (int)($analyticsStatus['lock_wait_ms'] ?? 0) ?> ms</span>
                <span class="muted">累计失败 <?= (int)($analyticsStatus['failure_count'] ?? 0) ?> 次 · 连续失败 <?= (int)($analyticsStatus['consecutive_failures'] ?? 0) ?> 次<?php if (is_int($analyticsStatus['consumer_lag_seconds'] ?? null)): ?> · 消费延迟 <?= e(format_duration((int)$analyticsStatus['consumer_lag_seconds'])) ?><?php endif; ?></span>
            <?php endif; ?>
            <div class="status-item-actions"><?php if (($analyticsStatus['state'] ?? 'error') !== 'ok'): ?><a class="button button-secondary button-small" href="#status-runbook-analytics">立即处理</a><?php endif; ?><a href="#status-runbook-analytics">运行手册</a></div>
        </article>
    </div>
    <section class="panel synthetic-monitor-panel" id="synthetic-monitor-details" aria-labelledby="synthetic-monitor-title">
        <div class="stats-heading"><span class="section-heading"><span class="section-icon"><svg class="icon" aria-hidden="true"><use href="#icon-gauge"/></svg></span><div><h3 id="synthetic-monitor-title">合成监控</h3><p class="muted">定时验证公开访问与关键鉴权路径</p></div></span><span class="health-dot <?= e((string)$syntheticStatus['state']) ?>"><?= e($statusLabels[$syntheticStatus['state']] ?? '异常') ?></span></div>
        <div class="synthetic-runtime"><span><?php if ($syntheticCompletedAt > 0): ?>最近运行 <time datetime="<?= e(gmdate('Y-m-d\TH:i:s\Z', $syntheticCompletedAt)) ?>" data-local-time><?= e(gmdate('Y-m-d\TH:i:s\Z', $syntheticCompletedAt)) ?> UTC</time><?php else: ?>等待首次运行<?php endif; ?></span><?php if (!empty($syntheticStatus['available'])): ?><span>总耗时 <?= (int)$syntheticStatus['duration_ms'] ?> ms</span><?php endif; ?></div>
        <?php if (!$syntheticProbes): ?><div class="empty compact-empty">暂无探针结果。定时任务运行后会在此显示首页、登录页、API 和 Canary 状态。</div><?php else: ?>
            <div class="synthetic-table-wrap" role="region" aria-label="合成监控探针结果，可横向滚动" tabindex="0"><table class="synthetic-table"><thead><tr><th scope="col">探针</th><th scope="col">路径</th><th scope="col">结果</th><th scope="col">HTTP</th><th scope="col">耗时</th><th scope="col">处理</th></tr></thead><tbody><?php foreach ($syntheticProbes as $probe): ?><?php $probeState = (string)($probe['status'] ?? 'error'); ?><tr><td data-label="探针"><strong><?= e((string)$probe['label']) ?></strong><div class="muted"><?= e((string)$probe['detail']) ?></div></td><td data-label="路径"><code><?= e((string)$probe['path']) ?></code></td><td data-label="结果"><span class="health-dot <?= e($probeState) ?>"><?= e($probeStatusLabels[$probeState] ?? '失败') ?></span></td><td data-label="HTTP"><?= is_int($probe['http_status'] ?? null) ? (int)$probe['http_status'] : '-' ?></td><td data-label="耗时"><?= is_int($probe['latency_ms'] ?? null) ? (int)$probe['latency_ms'] . ' ms' : '-' ?></td><td data-label="处理"><?php if ($probeState !== 'ok'): ?><a href="#status-runbook-synthetic_monitor">处理异常</a><?php else: ?><span class="muted">无需处理</span><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div>
        <?php endif; ?>
    </section>
    <section class="panel" id="data-governance" aria-labelledby="data-governance-title">
        <div class="stats-heading"><span class="section-heading"><span class="section-icon"><svg class="icon" aria-hidden="true"><use href="#icon-lock"/></svg></span><div><h3 id="data-governance-title">隐私与数据治理</h3><p class="muted">访问分析字段、用途、保留与删除边界</p></div></span><span class="status active">已明确</span></div>
        <div class="table-scroll"><table><thead><tr><th scope="col">数据</th><th scope="col">用途</th><th scope="col">保存形式</th><th scope="col">保留与删除</th></tr></thead><tbody>
            <tr><td data-label="数据">User-Agent</td><td data-label="用途">识别设备、浏览器、系统及自动化流量大类</td><td data-label="保存形式">仅在原始日志与聚合时内存中处理；SQLite 不保存原文</td><td data-label="保留与删除">原始日志 <?= (int)$dataGovernance['raw_log_retention_days'] ?> 天后由 logrotate 删除</td></tr>
            <tr><td data-label="数据">来源域名</td><td data-label="用途">统计访问来源</td><td data-label="保存形式">只保存域名，不保存来源路径、查询参数或完整 URL</td><td data-label="保留与删除">小时聚合 <?= (int)$dataGovernance['hourly_retention_days'] ?> 天；日聚合 <?= (int)$dataGovernance['aggregate_retention_days'] ?> 天</td></tr>
            <tr><td data-label="数据">国家/地区</td><td data-label="用途">区域分布统计</td><td data-label="保存形式">受信任 CDN 提供的国家码；不可信或缺失时记为未知</td><td data-label="保留与删除">随聚合数据到期删除</td></tr>
            <tr><td data-label="数据">活动字段</td><td data-label="用途">按活动、来源、媒介和内容归因</td><td data-label="保存形式">链接配置快照与匿名聚合计数</td><td data-label="保留与删除">聚合按上述期限清理；永久删除链接时关联数据级联删除</td></tr>
        </tbody></table></div>
        <p class="muted">不采集 IP、UV 指纹或 IP 摘要，也不保存 Cookie、Authorization 和请求查询字符串。保留任务由定时清理服务执行。</p>
    </section>
    <section class="panel status-runbooks" aria-labelledby="status-runbooks-title"><div class="stats-heading"><h3 id="status-runbooks-title">运行手册</h3><span class="muted">处置入口与核验命令</span></div>
        <details id="status-runbook-release"><summary>发布与回滚</summary><div><p>发布前确认健康接口、应用日志和最新备份 marker 中的版本、构建时间与 Schema 一致。回滚时使用版本中心登记的已验证版本，并先确认其支持当前 Schema。</p><code>curl -fsS "$LINKVAULT_BASE_URL/readyz"</code></div></details>
        <details id="status-runbook-database"><summary>数据库读写</summary><div><p>检查 PHP-FPM 用户权限、SQLite 锁和磁盘 I/O；修复后重新请求就绪探针。</p><code>systemctl status php-fpm-linkvault</code></div></details>
        <details id="status-runbook-write_lock"><summary>限次链接写锁</summary><div><p>限次和一次性链接必须原子消费，超过当前等待与重试边界会返回 503。降低并发写任务的批次，或迁移到支持行级写并发的数据库后端。</p><code>journalctl -u php-fpm-linkvault --since today</code></div></details>
        <details id="status-runbook-schema"><summary>Schema 迁移</summary><div><p>先完成备份，再运行迁移并执行生产预检；不要在 Web 请求中迁移。</p><code>php bin/migrate.php &amp;&amp; php bin/preflight.php</code></div></details>
        <details id="status-runbook-disk"><summary>磁盘空间</summary><div><p>确认数据库分区、日志与备份目录容量，清理过期文件后复核健康探针。</p><code>df -h /var/lib/linkvault /var/backups/linkvault</code></div></details>
        <details id="status-runbook-local_backup"><summary>本地备份</summary><div><p>检查备份定时器和最近任务日志，手动补做备份后运行年龄检查。</p><code>systemctl start linkvault-backup.service</code></div></details>
        <details id="status-runbook-remote_backup"><summary>异地备份</summary><div><p>检查 age 密钥、rclone 远端和对象大小校验；修复后重新运行备份任务。</p><code>journalctl -u linkvault-backup.service -n 100</code></div></details>
        <details id="status-runbook-api"><summary>API Token</summary><div><p>创建或轮换可用 Token，并在调用方完成切换后吊销旧 Token。</p><a href="<?= e(list_path('', 'active', 1, 'all', 'created_desc', '', false, 0, 'api')) ?>">打开 Token 管理</a></div></details>
        <details id="status-runbook-admin_security"><summary>管理员验证</summary><div><p>补充恢复码或启用 TOTP；密钥不可用时先核对进程环境中的安全密钥。</p><a href="<?= e(list_path('', 'active', 1, 'all', 'created_desc', '', false, 0, 'security')) ?>">打开第二因素管理</a></div></details>
        <details id="status-runbook-restore"><summary>恢复演练</summary><div><p>检查失败阶段、备份来源和凭据，修复后重新运行隔离恢复演练。</p><code>systemctl start linkvault-restore-drill.service</code></div></details>
        <details id="status-runbook-target_health"><summary>目标健康检查</summary><div><p>先查看异常目标和重定向链；积压时检查定时器、网络和 cURL 扩展。</p><a href="<?= e(list_path('', 'active', 1, 'all', 'created_desc', '', false, 0, 'maintenance', 'target_health')) ?>">打开目标异常列表</a></div></details>
        <details id="status-runbook-synthetic_monitor"><summary>合成监控</summary><div><p>按失败探针检查公开代理路由、登录表单、API 鉴权和 Canary 配置；修复后手动运行一次任务并确认状态更新时间。</p><code>systemctl start linkvault-endpoint-monitor.service</code><code>journalctl -u linkvault-endpoint-monitor.service -n 100</code></div></details>
        <details id="status-runbook-audit"><summary>失败审计</summary><div><p>按操作类型检查失败详情与请求编号，确认是否为权限、并发或输入问题。</p><a href="<?= e(list_path('', 'active', 1, 'all', 'created_desc', '', false, 0, 'audit')) ?>">打开审计记录</a></div></details>
        <details id="status-runbook-analytics"><summary>访问分析聚合</summary><div><p>检查访问日志权限、聚合偏移与任务日志；积压消化后复核数据更新时间。</p><code>systemctl start linkvault-analytics.service</code></div></details>
    </section>
    <?php endif; ?>
    <?php if ($section === 'security'): ?>
    <div class="status-title"><div><h2>安全配置</h2><p class="muted">管理第二因素验证与恢复凭据。</p></div><span class="health-dot <?= e((string)$adminSecurity['state']) ?>"><?= e($statusLabels[$adminSecurity['state']] ?? '异常') ?></span></div>
    <section class="panel admin-security-panel" id="admin-security-management">
        <div class="stats-heading"><span class="section-heading"><span class="section-icon"><svg class="icon"><use href="#icon-lock"/></svg></span><div><h3>管理员第二因素</h3><p class="muted">TOTP 密钥加密保存；恢复码可在单管理员被锁定时替代动态口令。</p></div></span><span class="health-dot <?= e((string)$adminSecurity['state']) ?>"><?= e($statusLabels[$adminSecurity['state']] ?? '异常') ?></span></div>
        <?php if (is_array($totpSetup)): ?>
            <div class="totp-setup-grid">
                <div class="qr-panel totp-qr-panel"><div class="qr-code" data-qr-value="<?= e($totpProvisioningUri) ?>" data-qr-label="TOTP 配置二维码" role="status" aria-live="polite">正在生成二维码</div></div>
                <div class="totp-setup-details"><label>手动设置密钥<input id="totp-secret" type="text" value="<?= e((string)$totpSetup['secret']) ?>" readonly></label><button class="button-secondary button-small" type="button" data-copy="<?= e((string)$totpSetup['secret']) ?>" data-copy-target="totp-secret"><svg class="icon"><use href="#icon-copy"/></svg>复制密钥</button><form method="post" action="<?= e(app_path('/security/totp/enable')) ?>"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><label>6 位动态口令<input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required></label><button type="submit"><svg class="icon"><use href="#icon-check-circle"/></svg>确认启用</button></form><form method="post" action="<?= e(app_path('/security/totp/cancel')) ?>"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><button class="button-secondary" type="submit">取消设置</button></form></div>
            </div>
        <?php elseif (empty($adminSecurity['enabled'])): ?>
            <form class="security-enable-form" method="post" action="<?= e(app_path('/security/totp/setup')) ?>"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><label>管理员密码<input type="password" name="password" autocomplete="current-password" required></label><button type="submit"<?= empty($adminSecurity['available']) ? ' disabled' : '' ?>><svg class="icon"><use href="#icon-plus"/></svg>设置 TOTP</button></form>
            <?php if (empty($adminSecurity['available'])): ?><p class="field-note">配置至少 32 位的 <code>LINKVAULT_SECURITY_KEY</code> 并重启服务后可启用。</p><?php endif; ?>
        <?php else: ?>
            <div class="security-enabled-summary"><strong>剩余 <?= (int)$adminSecurity['recovery_codes_remaining'] ?> 个恢复码</strong><?php if (!empty($adminSecurity['enabled_at'])): ?><span class="muted">启用于 <time datetime="<?= e((string)$adminSecurity['enabled_at']) ?>" data-local-time><?= e((string)$adminSecurity['enabled_at']) ?> UTC</time></span><?php endif; ?></div>
            <div class="security-management-grid">
                <form method="post" action="<?= e(app_path('/security/recovery-codes/regenerate')) ?>" data-confirm="重置后所有旧恢复码立即作废，确定继续吗？"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><h4>重置恢复码</h4><label>管理员密码<input type="password" name="password" autocomplete="current-password" required></label><label>动态口令或恢复码<input type="text" name="second_factor" autocomplete="one-time-code" maxlength="20" required></label><button class="button-secondary" type="submit"><svg class="icon"><use href="#icon-restore"/></svg>生成新恢复码</button></form>
                <form method="post" action="<?= e(app_path('/security/totp/disable')) ?>" data-confirm="停用后所有恢复码同时作废，确定继续吗？"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><h4>停用 TOTP</h4><label>管理员密码<input type="password" name="password" autocomplete="current-password" required></label><label>动态口令或恢复码<input type="text" name="second_factor" autocomplete="one-time-code" maxlength="20" required></label><button class="button-danger" type="submit"><svg class="icon"><use href="#icon-power"/></svg>停用第二因素</button></form>
            </div>
        <?php endif; ?>
    </section>
    <?php endif; ?>
    <?php if ($section === 'domains'): ?>
    <div class="status-title"><div><h2>域名配置</h2><p class="muted">管理短链域名、DNS 验证与品牌外观。</p></div></div>
    <section class="panel domain-manager-panel" id="short-domain-management">
        <div class="stats-heading"><span class="section-heading"><span class="section-icon"><svg class="icon"><use href="#icon-globe"/></svg></span><div><h3>短链域名</h3><p class="muted">自定义域名仅承载品牌主页和短链访问；管理端与 API 保留在默认域名。</p></div></span><?php $lifecycleStatus = $systemStatus['lifecycle_webhook'] ?? []; ?><span class="muted">生命周期 Webhook：<?= !empty($lifecycleStatus['enabled']) ? '待发 ' . (int)$lifecycleStatus['pending'] . ' · 死信 ' . (int)$lifecycleStatus['dead'] : '未配置' ?></span></div>
        <form class="domain-create-form" method="post" action="<?= e(app_path('/domains/create')) ?>"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><label>域名<input type="text" name="hostname" maxlength="253" placeholder="go.example.com" required></label><label>品牌名称<input type="text" name="brand_name" maxlength="60" value="链匣 LinkVault" required></label><label>品牌标语<input type="text" name="brand_tagline" maxlength="160" value="你的链接，收放自如。"></label><label>主题<select name="brand_theme"><option value="graphite">石墨</option><option value="indigo">靛蓝</option><option value="emerald">翠绿</option><option value="crimson">绯红</option></select></label><button type="submit"><svg class="icon"><use href="#icon-plus"/></svg>添加域名</button></form>
        <?php if (!$shortDomains): ?><div class="empty compact-empty">尚未添加自定义短链域名。</div><?php else: ?><div class="domain-list"><?php foreach ($shortDomains as $domain): ?><article><div class="domain-heading"><div><strong><?= e((string)$domain['hostname']) ?></strong><span class="status <?= $domain['verified_at'] === null ? 'off' : ((int)$domain['is_enabled'] === 1 ? 'active' : 'off') ?>"><?= $domain['verified_at'] === null ? '待验证' : ((int)$domain['is_enabled'] === 1 ? '已启用' : '已停用') ?></span></div><code>_linkvault-challenge.<?= e((string)$domain['hostname']) ?> TXT linkvault-verification=<?= e((string)$domain['verification_token']) ?></code></div><div class="domain-actions"><?php if ($domain['verified_at'] === null): ?><form method="post" action="<?= e(app_path('/domains/verify')) ?>"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int)$domain['id'] ?>"><button class="button-secondary" type="submit"><svg class="icon"><use href="#icon-check"/></svg>验证 DNS</button></form><?php else: ?><form method="post" action="<?= e(app_path('/domains/toggle')) ?>"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int)$domain['id'] ?>"><input type="hidden" name="enabled" value="<?= (int)$domain['is_enabled'] === 1 ? '0' : '1' ?>"><button class="button-secondary" type="submit"><?= (int)$domain['is_enabled'] === 1 ? '停用' : '启用' ?></button></form><?php endif; ?><details><summary class="button button-secondary">品牌配置</summary><form method="post" action="<?= e(app_path('/domains/update')) ?>"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int)$domain['id'] ?>"><label>品牌名称<input type="text" name="brand_name" maxlength="60" value="<?= e((string)$domain['brand_name']) ?>" required></label><label>品牌标语<input type="text" name="brand_tagline" maxlength="160" value="<?= e((string)$domain['brand_tagline']) ?>"></label><label>主题<select name="brand_theme"><?php foreach (ShortDomainService::THEMES as $theme): ?><option value="<?= e($theme) ?>"<?= $domain['brand_theme'] === $theme ? ' selected' : '' ?>><?= e($theme) ?></option><?php endforeach; ?></select></label><button type="submit"><svg class="icon"><use href="#icon-save"/></svg>保存</button></form></details></div></article><?php endforeach; ?></div><?php endif; ?>
        <?php if ($shortDomains): ?><div class="domain-delete-list" aria-label="域名删除状态"><?php foreach ($shortDomains as $domain): ?><?php $domainLinkCount = (int)($domain['link_count'] ?? 0); ?><div><span><strong><?= e((string)$domain['hostname']) ?></strong><small><?= $domainLinkCount ?> 条链接使用</small></span><form method="post" action="<?= e(app_path('/domains/delete')) ?>" data-confirm="确定删除短链域名 <?= e((string)$domain['hostname']) ?> 吗？此操作无法撤销。"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int)$domain['id'] ?>"><button class="button-danger button-small" type="submit"<?= $domainLinkCount > 0 ? ' disabled title="仍有链接使用，不能删除"' : '' ?>><svg class="icon" aria-hidden="true"><use href="#icon-trash"/></svg>删除</button></form></div><?php endforeach; ?></div><?php endif; ?>
        <?php if ($shortDomains): ?>
        <div class="domain-delete-list" role="group" aria-label="域名迁移与退役">
            <?php foreach ($shortDomains as $domain): ?><?php $domainLinkCount = (int)($domain['link_count'] ?? 0); ?>
                <?php if ($domainLinkCount > 0): ?>
                <div>
                    <span><strong><?= e((string)$domain['hostname']) ?></strong><small><?= $domainLinkCount ?> 条链接待迁移</small></span>
                    <form method="post" action="<?= e(app_path('/domains/retire')) ?>" data-confirm="将迁移 <?= $domainLinkCount ?> 条链接并永久删除域名 <?= e((string)$domain['hostname']) ?>，确定继续吗？">
                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="id" value="<?= (int)$domain['id'] ?>">
                        <label><span class="sr-only">迁移目标</span><select name="destination_id"><option value="">默认域名</option><?php foreach ($shortDomains as $destination): ?><?php if ((int)$destination['id'] !== (int)$domain['id'] && $destination['verified_at'] !== null && (int)$destination['is_enabled'] === 1): ?><option value="<?= (int)$destination['id'] ?>"><?= e((string)$destination['hostname']) ?></option><?php endif; ?><?php endforeach; ?></select></label>
                        <button class="button-danger button-small" type="submit"><svg class="icon" aria-hidden="true"><use href="#icon-restore"/></svg>迁移并删除</button>
                    </form>
                </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php if ($domainRetirementJobs): ?>
        <div class="token-table-wrap"><table class="token-table"><thead><tr><th>退役任务</th><th>状态</th><th>进度</th><th>操作</th></tr></thead><tbody>
        <?php foreach ($domainRetirementJobs as $job): ?><?php
            $jobStatus = (string)$job['status'];
            $jobLabels = ['pending' => '等待中', 'running' => '执行中', 'paused' => '已暂停', 'completed' => '已完成', 'failed' => '失败', 'canceled' => '已取消'];
            $jobPercent = (int)$job['total_count'] > 0
                ? min(100, (int)round((int)$job['migrated_count'] * 100 / (int)$job['total_count']))
                : ($jobStatus === 'completed' ? 100 : 0);
        ?>
        <tr><td data-label="退役任务"><strong><?= e((string)$job['source_hostname']) ?></strong><div class="muted">目标：<?= e($job['destination_id'] === null ? '默认域名' : (string)($job['destination_hostname'] ?? '#' . $job['destination_id'])) ?></div></td><td data-label="状态"><span class="status <?= in_array($jobStatus, ['failed', 'canceled'], true) ? 'off' : 'active' ?>"><?= e($jobLabels[$jobStatus] ?? $jobStatus) ?></span><?php if ((string)($job['last_error'] ?? '') !== ''): ?><div class="field-error"><?= e((string)$job['last_error']) ?></div><?php endif; ?></td><td data-label="进度"><strong><?= $jobPercent ?>%</strong><div class="muted"><?= (int)$job['migrated_count'] ?> / <?= (int)$job['total_count'] ?> 条 · 尝试 <?= (int)$job['attempt_count'] ?> 次</div><progress max="<?= max(1, (int)$job['total_count']) ?>" value="<?= (int)$job['migrated_count'] ?>"><?= $jobPercent ?>%</progress></td><td data-label="操作"><div class="token-actions"><?php foreach (match ($jobStatus) { 'pending', 'running' => ['pause', 'cancel'], 'paused' => ['resume', 'cancel'], 'failed' => ['retry', 'cancel'], default => [] } as $jobAction): ?><form method="post" action="<?= e(app_path('/domains/retire/' . $jobAction)) ?>"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int)$job['id'] ?>"><button class="<?= $jobAction === 'cancel' ? 'button-danger' : 'button-secondary' ?> button-small" type="submit"><?= e(['pause' => '暂停', 'resume' => '继续', 'retry' => '重试', 'cancel' => '取消'][$jobAction]) ?></button></form><?php endforeach; ?></div></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
        <?php endif; ?>
    </section>
    <section class="panel domain-branding-panel" id="domain-branding-management">
        <div class="stats-heading"><span class="section-heading"><span class="section-icon"><svg class="icon"><use href="#icon-palette"/></svg></span><div><h3>品牌外观与失效页</h3><p class="muted">资源地址必须是无凭据的 HTTPS URL；留空使用系统默认资源。</p></div></span></div>
        <?php foreach ($shortDomains as $domain): ?>
            <form class="domain-branding-form" method="post" action="<?= e(app_path('/domains/update-appearance')) ?>">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int)$domain['id'] ?>">
                <strong><?= e((string)$domain['hostname']) ?></strong>
                <label>品牌色<input type="text" name="brand_color" pattern="#[0-9A-Fa-f]{6}" maxlength="7" value="<?= e((string)$domain['brand_color']) ?>" aria-describedby="brand-color-note-<?= (int)$domain['id'] ?>" required><span class="field-note" id="brand-color-note-<?= (int)$domain['id'] ?>">品牌色用于按钮和标识；系统会自动使用可读的文字色</span></label>
                <label>Logo URL<input type="url" name="logo_url" maxlength="2048" value="<?= e((string)$domain['logo_url']) ?>" placeholder="https://cdn.example/logo.png"></label>
                <label>Favicon URL<input type="url" name="favicon_url" maxlength="2048" value="<?= e((string)$domain['favicon_url']) ?>" placeholder="https://cdn.example/favicon.ico"></label>
                <label>失效页标题<input type="text" name="invalid_page_title" maxlength="80" value="<?= e((string)$domain['invalid_page_title']) ?>" required></label>
                <label class="field-wide">失效页内容<textarea name="invalid_page_message" maxlength="500" rows="2" required><?= e((string)$domain['invalid_page_message']) ?></textarea></label>
                <button class="button-secondary" type="submit"><svg class="icon"><use href="#icon-save"/></svg>保存 <?= e((string)$domain['hostname']) ?></button>
            </form>
        <?php endforeach; ?>
    </section>
    <?php endif; ?>
    <?php if ($section === 'api'): ?>
    <div class="status-title"><div><h2>API 配置</h2><p class="muted">创建、轮换和审计 API Token。</p></div><span class="health-dot <?= e((string)$systemStatus['api']['state']) ?>"><?= e($statusLabels[$systemStatus['api']['state']] ?? '异常') ?></span></div>
    <section class="panel token-manager-panel" id="api-token-management">
        <div class="stats-heading"><span class="section-heading"><span class="section-icon"><svg class="icon"><use href="#icon-key"/></svg></span><div><h3>API Token</h3><p class="muted">明文仅在创建或轮换后显示一次，数据库只保存 SHA-256 摘要。</p></div></span><span class="muted">作用域控制链接 API 权限</span></div>
        <form class="token-create-form" method="post" action="<?= e(app_path('/api-tokens/create')) ?>">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <label>名称<input type="text" name="name" maxlength="60" placeholder="例如 自动化脚本" required></label>
            <label>失效时间，可选（<span data-timezone-label>UTC</span>）<input type="datetime-local" name="expires_at" data-expiration-input><input type="hidden" name="expires_at_offset" value="0" data-expiration-offset></label>
            <label>独立配额请求数<input type="number" name="quota_requests" min="1" max="1000000" placeholder="留空使用全局配额"></label>
            <label>配额窗口（秒）<input type="number" name="quota_window_seconds" min="1" max="86400" placeholder="与请求数同时填写"></label>
            <label class="field-wide">允许的 CIDR<input type="text" name="allowed_cidrs" maxlength="2000" placeholder="例如 10.0.0.0/8, 2001:db8::/32"></label>
            <fieldset class="token-scope-field"><legend>作用域</legend><label class="check-field"><input type="checkbox" name="scopes[]" value="links:create" checked>创建</label><label class="check-field"><input type="checkbox" name="scopes[]" value="links:read">查询</label><label class="check-field"><input type="checkbox" name="scopes[]" value="links:write">编辑与停用</label><label class="check-field"><input type="checkbox" name="scopes[]" value="links:delete">删除</label><label class="check-field"><input type="checkbox" name="scopes[]" value="conversions:write">写入转化事件</label></fieldset>
            <button type="submit"><svg class="icon"><use href="#icon-plus"/></svg>生成 Token</button>
        </form>
        <?php if (!$apiTokens): ?>
            <div class="empty compact-empty">尚未创建数据库 Token。<?= !empty($systemStatus['api']['legacy_enabled']) ? '当前仍可使用环境变量 Token。' : '' ?></div>
        <?php else: ?>
            <div class="token-table-wrap"><table class="token-table"><thead><tr><th>Token</th><th>状态</th><th>使用情况</th><th>时间</th><th>操作</th></tr></thead><tbody>
            <?php foreach ($apiTokens as $token): ?><?php
                $tokenNaturalExpired = false;
                if (is_string($token['expires_at'] ?? null) && $token['expires_at'] !== '') {
                    try {
                        $tokenNaturalExpired = new DateTimeImmutable((string)$token['expires_at']) <= new DateTimeImmutable('now', new DateTimeZone('UTC'));
                    } catch (Throwable) {
                        $tokenNaturalExpired = true;
                    }
                }
                $rotationSet = is_string($token['rotation_expires_at'] ?? null) && $token['rotation_expires_at'] !== '';
                $rotationExpired = false;
                if ($rotationSet) {
                    try {
                        $rotationExpired = new DateTimeImmutable((string)$token['rotation_expires_at']) <= new DateTimeImmutable('now', new DateTimeZone('UTC'));
                    } catch (Throwable) {
                        $rotationExpired = true;
                    }
                }
                $tokenRevoked = $token['revoked_at'] !== null;
                $tokenExpired = $tokenNaturalExpired || $rotationExpired;
                $tokenState = $tokenRevoked ? '已吊销' : ($tokenExpired ? '已失效' : ($rotationSet ? '过渡可用' : '可用'));
                $tokenStateClass = $tokenRevoked || $tokenExpired ? 'off' : 'active';
            ?>
                <tr>
                    <td data-label="Token"><strong><?= e((string)$token['name']) ?></strong><div><code><?= e((string)$token['token_prefix']) ?>...</code></div><div class="token-scopes"><?php foreach (ApiTokenService::parseStoredScopes((string)$token['scopes']) as $tokenScope): ?><code><?= e($tokenScope) ?></code><?php endforeach; ?></div><div class="muted"><?= $token['quota_requests'] === null ? '全局配额' : (int)$token['quota_requests'] . ' 次 / ' . (int)$token['quota_window_seconds'] . ' 秒' ?><?= (string)$token['allowed_cidrs'] !== '' ? ' · CIDR ' . e((string)$token['allowed_cidrs']) : '' ?></div><?php if ($token['rotated_from_id'] !== null): ?><span class="muted">由 #<?= (int)$token['rotated_from_id'] ?> 轮换</span><?php endif; ?></td>
                    <td data-label="状态"><span class="status <?= e($tokenStateClass) ?>"><?= e($tokenState) ?></span></td>
                    <td data-label="使用情况"><strong><?= (int)$token['use_count'] ?> 次</strong><div class="muted"><?php if ($token['last_used_at'] !== null): ?>最近 <time datetime="<?= e((string)$token['last_used_at']) ?>" data-local-time><?= e((string)$token['last_used_at']) ?> UTC</time><?php else: ?>尚未使用<?php endif; ?></div></td>
                    <td data-label="时间"><span class="muted">创建 <time datetime="<?= e((string)$token['created_at']) ?>" data-local-time><?= e((string)$token['created_at']) ?> UTC</time></span><div class="muted"><?php if ($token['expires_at'] !== null): ?>失效 <time datetime="<?= e((string)$token['expires_at']) ?>" data-local-time><?= e((string)$token['expires_at']) ?> UTC</time><?php else: ?>永不自动失效<?php endif; ?></div><?php if ($rotationSet): ?><div class="muted">过渡截止 <time datetime="<?= e((string)$token['rotation_expires_at']) ?>" data-local-time><?= e((string)$token['rotation_expires_at']) ?> UTC</time></div><?php endif; ?></td>
                    <td data-label="操作"><?php if (!$tokenRevoked && !$tokenExpired): ?><div class="token-actions"><?php if (!$rotationSet): ?><form class="token-rotate-form" method="post" action="<?= e(app_path('/api-tokens/rotate')) ?>" data-confirm="轮换后新旧 Token 会在设定窗口内并行，确定继续吗？"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int)$token['id'] ?>"><label><span class="sr-only">新 Token 失效时间</span><input type="datetime-local" name="expires_at" data-expiration-input title="新 Token 失效时间；留空表示不过期"><input type="hidden" name="expires_at_offset" value="0" data-expiration-offset></label><label class="token-overlap-field"><span class="sr-only">新旧 Token 并行分钟数</span><input type="number" name="overlap_minutes" min="1" max="<?= $tokenRotationMaxMinutes ?>" value="<?= $tokenRotationDefaultMinutes ?>" title="新旧 Token 并行分钟数" required></label><button class="button-secondary button-small" type="submit"><svg class="icon"><use href="#icon-restore"/></svg>轮换</button></form><?php endif; ?><form method="post" action="<?= e(app_path('/api-tokens/revoke')) ?>" data-confirm="吊销后该 Token 无法恢复，确定继续吗？"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int)$token['id'] ?>"><button class="button-danger button-small" type="submit"><svg class="icon"><use href="#icon-power"/></svg>吊销</button></form></div><?php else: ?><span class="muted">不可操作</span><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody></table></div>
            <?php $apiTokenLastPage = max(1, (int)ceil($apiTokenTotal / $apiTokenPageSize)); if ($apiTokenLastPage > 1): ?><div class="pagination"><?php if ($apiTokenPage > 1): ?><a class="button button-secondary button-small" href="<?= e(app_path('/?section=api&token_page=' . ($apiTokenPage - 1))) ?>">上一页</a><?php endif; ?><span class="muted">第 <?= $apiTokenPage ?> / <?= $apiTokenLastPage ?> 页</span><?php if ($apiTokenPage < $apiTokenLastPage): ?><a class="button button-secondary button-small" href="<?= e(app_path('/?section=api&token_page=' . ($apiTokenPage + 1))) ?>">下一页</a><?php endif; ?></div><?php endif; ?>
        <?php endif; ?>
    </section>
    <section class="panel token-usage-panel"><div class="stats-heading"><h3>Token 异常使用告警</h3><?php if ($apiTokenAlerts): ?><form method="post" action="<?= e(app_path('/api-tokens/alerts/clear')) ?>" data-confirm="确定确认并清除全部 Token 异常告警吗？"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><button class="button-secondary button-small" type="submit">确认全部</button></form><?php else: ?><span class="muted">CIDR 拒绝与配额耗尽</span><?php endif; ?></div><?php if (!$apiTokenAlerts): ?><div class="empty compact-empty">暂无异常告警。</div><?php else: ?><div class="table-scroll"><table class="token-usage-table"><thead><tr><th scope="col">Token</th><th scope="col">类型</th><th scope="col">次数</th><th scope="col">最近来源</th><th scope="col">最近发生</th></tr></thead><tbody><?php foreach ($apiTokenAlerts as $alert): ?><tr><td data-label="Token"><strong><?= e((string)$alert['token_name']) ?></strong><div><code><?= e((string)$alert['token_prefix']) ?>...</code></div></td><td data-label="类型"><?= e($alert['alert_type'] === 'cidr_denied' ? 'CIDR 拒绝' : '配额耗尽') ?></td><td data-label="次数"><?= (int)$alert['occurrence_count'] ?></td><td data-label="最近来源"><code><?= e((string)$alert['last_client_ip']) ?></code><div class="muted"><?= e((string)$alert['last_endpoint']) ?></div></td><td data-label="最近发生"><time datetime="<?= e((string)$alert['last_seen_at']) ?>" data-local-time><?= e((string)$alert['last_seen_at']) ?> UTC</time></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
    <section class="panel token-usage-panel"><div class="stats-heading"><h3>最近 Token 使用记录</h3><span class="muted">最多显示 50 条 · 保留 <?= max(1, (int)($config['api_token_usage_retention_days'] ?? 90)) ?> 天</span></div><?php if (!$apiTokenUsage): ?><div class="empty compact-empty">暂无使用记录。</div><?php else: ?><div class="table-scroll token-usage-table-scroll" role="region" aria-label="Token 使用记录，可横向滚动" tabindex="0"><table class="token-usage-table"><thead><tr><th>时间</th><th>Token</th><th>结果</th><th>接口</th><th>请求编号</th></tr></thead><tbody><?php foreach ($apiTokenUsage as $usage): ?><tr><td data-label="时间"><time datetime="<?= e((string)$usage['used_at']) ?>" data-local-time><?= e((string)$usage['used_at']) ?> UTC</time></td><td data-label="Token"><strong><?= e((string)$usage['token_name']) ?></strong><div><code><?= e((string)$usage['token_prefix']) ?><?= $usage['token_prefix'] === 'env' ? '' : '...' ?></code></div></td><td data-label="结果"><span class="audit-outcome <?= $usage['outcome'] === 'accepted' ? '' : 'failure' ?>"><?= e(match ((string)$usage['outcome']) { 'accepted' => '通过', 'expired' => '已失效', default => '已吊销' }) ?></span></td><td data-label="接口"><code><?= e((string)$usage['endpoint']) ?></code></td><td data-label="请求编号"><code><?= e((string)($usage['request_id'] ?? '')) ?></code></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
    <?php endif; ?>
    <?php if ($section === 'status'): ?><section class="panel anomaly-panel"><div class="stats-heading"><h3>最近异常审计</h3><span class="muted">最多显示 10 条</span></div><?php if (!$systemStatus['recent_anomalies']): ?><div class="empty compact-empty">当前窗口没有失败事件。</div><?php else: ?><ol class="history-list"><?php foreach ($systemStatus['recent_anomalies'] as $anomaly): ?><li><span><?= e((string)$anomaly['action']) ?><?= !empty($anomaly['entity_id']) ? ' · ' . e((string)$anomaly['entity_id']) : '' ?></span><time datetime="<?= e((string)$anomaly['created_at']) ?>" data-local-time><?= e((string)$anomaly['created_at']) ?> UTC</time></li><?php endforeach; ?></ol><?php endif; ?></section><?php endif; ?>
</section>
