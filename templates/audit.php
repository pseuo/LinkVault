<?php
$auditLabels = [
    'create' => '创建', 'create_replayed' => '重复创建返回', 'edit' => '编辑',
    'delete' => '移入回收站', 'restore' => '恢复', 'purge' => '永久删除',
    'clear_expiration' => '清除过期时间', 'import' => '导入', 'status_change' => '状态调整',
    'favorite_change' => '收藏调整', 'export_links' => '导出链接', 'export_snapshot' => '审计数据快照',
    'save_filter' => '保存筛选', 'delete_filter' => '删除筛选',
    'maintenance_notification' => '维护通知', 'daily_stats_retention' => '统计保留',
    'operational_data_cleanup' => '运行数据清理',
    'restore_drill' => '恢复演练', 'configuration_error' => '配置异常',
    'api_create' => 'API 创建', 'api_create_replayed' => 'API 幂等重放',
    'api_duplicate_reused' => 'API 复用',
    'api_token_create' => '创建 API Token', 'api_token_rotate' => '轮换 API Token',
    'api_token_revoke' => '吊销 API Token',
    'login' => '管理员登录', 'totp_setup_started' => '开始设置 TOTP',
    'totp_enable' => '启用 TOTP', 'totp_disable' => '停用 TOTP',
    'recovery_codes_regenerate' => '重置恢复码',
    'link_password_unlock' => '短链接密码验证',
    'logout' => '管理员退出',
    'rename_filter' => '重命名筛选',
    'save_analytics_view' => '保存分析视图', 'rename_analytics_view' => '重命名分析视图',
    'delete_analytics_view' => '删除分析视图', 'export_analytics' => '导出访问分析',
    'bulk_preview' => '预览批量操作', 'bulk_apply' => '执行批量操作', 'bulk_undo' => '撤销批量操作',
    'api_update' => 'API 编辑链接', 'api_disable' => 'API 停用链接', 'api_delete' => 'API 删除链接',
    'short_domain_create' => '添加短链域名', 'short_domain_verify' => '验证短链域名',
    'short_domain_brand_update' => '更新域名品牌', 'short_domain_toggle' => '切换短链域名状态',
    'short_domain_delete' => '删除短链域名',
    'target_health_check' => '目标健康定时检查', 'target_health_manual_check' => '手动检查目标健康',
    'target_health_repair' => '修复目标健康异常', 'analytics_anomaly_notification' => '访问分析异常通知',
    'seed_canary' => '创建监控探针链接', 'save_link_preset' => '保存链接预设',
    'delete_link_preset' => '删除链接预设', 'webhook_replay' => '重放 Webhook',
];
$formatAuditJson = static function (mixed $value): string {
    $json = json_encode(
        $value,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE
    );
    return is_string($json) ? $json : '{}';
};
?>
<section class="panel audit-panel">
    <div class="stats-heading">
        <span class="section-heading"><span class="section-icon"><svg class="icon"><use href="#icon-eye"/></svg></span><div><h2>全局操作审计</h2><p class="muted">记录管理操作、导出、维护任务和配置异常。</p></div></span>
        <span class="muted">共 <?= $auditTotal ?> 条</span>
    </div>
    <form class="audit-filter" method="get" action="<?= e(app_path('/')) ?>">
        <input type="hidden" name="section" value="audit">
        <label>操作类型<select name="action"><option value="all">全部操作</option><?php foreach ($auditActions as $actionOption): ?><option value="<?= e((string)$actionOption) ?>"<?= $auditAction === $actionOption ? ' selected' : '' ?>><?= e($auditLabels[$actionOption] ?? (string)$actionOption) ?></option><?php endforeach; ?></select></label>
        <button class="button-secondary" type="submit"><svg class="icon"><use href="#icon-filter"/></svg>筛选</button>
    </form>
    <?php if (!$auditEvents): ?>
        <div class="empty">暂无审计记录。</div>
    <?php else: ?>
        <div class="table-scroll audit-table-scroll" role="region" aria-label="审计记录，可横向滚动" tabindex="0"><table class="audit-table"><thead><tr><th>时间</th><th>操作</th><th>结果</th><th>对象</th><th>请求编号</th><th>摘要</th></tr></thead><tbody>
        <?php foreach ($auditEvents as $audit): ?>
            <?php
            $details = json_decode((string)$audit['details_json'], true);
            $details = is_array($details) ? $details : [];
            $hasDiff = array_key_exists('before', $details) || array_key_exists('after', $details);
            $summaryDetails = $details;
            unset($summaryDetails['before'], $summaryDetails['after']);
            $detailText = implode(' · ', array_map(
                static fn ($key, $value): string => $key . '=' . (is_bool($value)
                    ? ($value ? 'true' : 'false')
                    : (is_scalar($value) || $value === null ? (string)$value : '[结构化数据]')),
                array_keys($summaryDetails),
                array_values($summaryDetails)
            ));
            ?>
            <tr>
                <td data-label="时间"><time datetime="<?= e((string)$audit['created_at']) ?>" data-local-time><?= e((string)$audit['created_at']) ?> UTC</time></td>
                <td data-label="操作"><strong><?= e($auditLabels[$audit['action']] ?? (string)$audit['action']) ?></strong><div class="muted"><?= e((string)$audit['actor_type']) ?></div></td>
                <td data-label="结果"><span class="audit-outcome <?= e((string)$audit['outcome']) ?>"><?= $audit['outcome'] === 'success' ? '成功' : '失败' ?></span></td>
                <td data-label="对象"><?= e(trim((string)($audit['entity_type'] ?? '') . ' ' . (string)($audit['entity_id'] ?? ''))) ?></td>
                <td data-label="请求编号"><code><?= e((string)($audit['request_id'] ?? '')) ?></code></td>
                <td data-label="摘要" class="audit-details">
                    <?php if ($detailText !== ''): ?><span><?= e($detailText) ?></span><?php endif; ?>
                    <?php if ($hasDiff): ?><details class="audit-diff"><summary>查看字段变更</summary><div><section><strong>before</strong><pre><?= e($formatAuditJson($details['before'] ?? null)) ?></pre></section><section><strong>after</strong><pre><?= e($formatAuditJson($details['after'] ?? null)) ?></pre></section></div></details><?php elseif ($details && $detailText === ''): ?><details class="audit-diff"><summary>查看结构化数据</summary><pre><?= e($formatAuditJson($details)) ?></pre></details><?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div>
        <?php $lastPage = max(1, (int)ceil($auditTotal / 50)); if ($lastPage > 1): ?>
            <div class="pagination"><?php $auditQuery = $auditAction === 'all' ? '' : '&action=' . rawurlencode($auditAction); ?><?php if ($page > 1): ?><a class="button button-secondary button-small" href="<?= e(app_path('/?section=audit&page=' . ($page - 1) . $auditQuery)) ?>">上一页</a><?php endif; ?><span class="muted">第 <?= $page ?> / <?= $lastPage ?> 页</span><?php if ($page < $lastPage): ?><a class="button button-secondary button-small" href="<?= e(app_path('/?section=audit&page=' . ($page + 1) . $auditQuery)) ?>">下一页</a><?php endif; ?></div>
        <?php endif; ?>
    <?php endif; ?>
</section>
