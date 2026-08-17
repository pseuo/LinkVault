<?php $maintenanceLabels = [
    'expiring' => $maintenanceExpiringDays . ' 日内过期',
    'stale_zero' => $maintenanceStaleDays . ' 日零点击',
    'quota' => '配额达到 ' . $maintenanceQuotaPercent . '%',
    'invalid' => '已失效',
    'target_health' => '目标异常',
]; ?>
<section class="panel maintenance-panel">
    <div class="stats-heading">
        <span class="section-heading"><span class="section-icon"><svg class="icon"><use href="#icon-gauge"/></svg></span><div><h2>链接维护</h2><p class="muted">集中处理需要关注的链接。</p></div></span>
        <span class="muted">当前分类 <?= $totalLinks ?> 条</span>
    </div>
    <nav class="maintenance-tabs" aria-label="维护分类">
        <?php foreach ($maintenanceLabels as $categoryKey => $categoryLabel): ?><a href="<?= e(list_path('', 'active', 1, 'all', 'created_desc', '', false, 0, 'maintenance', $categoryKey)) ?>"<?= $maintenanceCategory === $categoryKey ? ' aria-current="page"' : '' ?>><span><?= e($categoryLabel) ?></span><strong><?= (int)$maintenanceCounts[$categoryKey] ?></strong></a><?php endforeach; ?>
    </nav>
    <div class="toolbar maintenance-toolbar">
        <form class="filter-form maintenance-filter" method="get" action="<?= e(app_path('/')) ?>">
            <input type="hidden" name="section" value="maintenance"><input type="hidden" name="maintenance" value="<?= e($maintenanceCategory) ?>">
            <label class="search-field"><span class="sr-only">搜索维护链接</span><svg class="icon" aria-hidden="true"><use href="#icon-search"/></svg><input type="search" name="q" value="<?= e($search) ?>" placeholder="搜索标题、短码、标签或目标域名"></label>
            <label class="compact-field"><span class="sr-only">标签</span><select name="tag" aria-label="标签筛选"><option value="">全部标签</option><?php foreach ($allTags as $tagOption): ?><option value="<?= e((string)$tagOption['tag']) ?>"<?= $tag === (string)$tagOption['tag'] ? ' selected' : '' ?>><?= e((string)$tagOption['tag']) ?></option><?php endforeach; ?></select></label>
            <button class="button-secondary" type="submit"><svg class="icon"><use href="#icon-filter"/></svg>筛选</button>
        </form>
    </div>
    <?php if ($links): ?>
        <form id="maintenance-bulk-form" class="bulk-form maintenance-bulk" method="post" action="<?= e(app_path('/bulk')) ?>" data-bulk-form data-bulk-preview-action="<?= e(app_path('/bulk/preview')) ?>">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="return_section" value="maintenance"><input type="hidden" name="return_maintenance" value="<?= e($maintenanceCategory) ?>"><input type="hidden" name="return_q" value="<?= e($search) ?>"><input type="hidden" name="return_tag" value="<?= e($tag) ?>"><input type="hidden" name="return_sort" value="<?= e($sort) ?>"><input type="hidden" name="return_page" value="<?= $page ?>">
            <span class="muted" data-selected-count aria-live="polite">已选择 0 条</span>
            <select name="bulk_action" aria-label="批量维护操作" required data-bulk-action><option value="">批量操作</option><option value="extend">延期</option><option value="add_tags">添加标签</option><option value="remove_tags">移除标签</option><option value="enable">启用</option><option value="disable">停用</option></select>
            <label class="bulk-extra" data-bulk-days hidden><span class="sr-only">延期天数</span><input type="number" name="bulk_days" min="1" max="3650" value="30" aria-label="延期天数"></label>
            <label class="bulk-extra" data-bulk-tags hidden><span class="sr-only">批量标签</span><input type="text" name="bulk_tags" maxlength="260" placeholder="标签，逗号分隔" aria-label="批量标签"></label>
            <?php if ($maintenanceCategory === 'target_health'): ?><button class="button-secondary" type="submit" formaction="<?= e(app_path('/maintenance/recheck')) ?>" formnovalidate data-maintenance-recheck disabled><svg class="icon" aria-hidden="true"><use href="#icon-refresh"/></svg>重新检查</button><?php endif; ?>
            <button class="button-secondary" type="submit" data-bulk-submit disabled>预览影响</button>
        </form>
        <div class="table-scroll maintenance-table-scroll" role="region" aria-label="维护链接列表，可横向滚动" tabindex="0">
        <?php if ($maintenanceCategory === 'target_health'): ?>
        <table class="maintenance-table"><thead><tr><th class="column-select"><input type="checkbox" data-select-all aria-label="选择本页全部链接"></th><th>链接</th><th>检查结果</th><th>有效目标</th><th>重定向</th><th>修复</th></tr></thead><tbody>
        <?php foreach ($links as $link): ?>
            <?php
            $redirectChain = json_decode((string)($link['target_health_redirect_chain_json'] ?? '[]'), true);
            $redirectChain = is_array($redirectChain) ? $redirectChain : [];
            $diagnosis = implode("\n", [
                'LinkVault target diagnosis',
                'slug: ' . (string)$link['slug'],
                'target: ' . (string)$link['target_url'],
                'state: ' . (string)$link['target_health_state'],
                'reason: ' . (string)$link['target_health_reason'],
                'http_status: ' . (string)($link['target_health_http_status'] ?? 'n/a'),
                'latency_ms: ' . (string)($link['target_health_latency_ms'] ?? 'n/a'),
                'checked_at: ' . (string)($link['target_health_checked_at'] ?? 'n/a'),
                'effective_url: ' . (string)($link['target_health_effective_url'] ?? 'n/a'),
                'redirect_state: ' . (string)$link['target_health_redirect_state'],
                'redirect_chain: ' . json_encode($redirectChain, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]);
            ?>
            <tr>
                <td class="column-select" data-label="选择"><input type="checkbox" name="selected[]" value="<?= (int)$link['id'] ?>" form="maintenance-bulk-form" data-row-select aria-label="选择 <?= e((string)$link['slug']) ?>"></td>
                <td data-label="链接"><a href="<?= e(app_path('/link') . '?id=' . (int)$link['id']) ?>"><strong><?= e((string)($link['title'] !== '' ? $link['title'] : $link['slug'])) ?></strong></a><div class="muted"><?= e((string)$link['slug']) ?></div><button class="button-secondary button-small" type="button" data-copy="<?= e($diagnosis) ?>"><svg class="icon" aria-hidden="true"><use href="#icon-copy"/></svg>复制诊断</button></td>
                <td data-label="检查结果"><strong><?= e((string)$link['target_health_state']) ?></strong><div class="muted"><?= e((string)$link['target_health_reason']) ?></div><div class="muted">HTTP <?= e((string)($link['target_health_http_status'] ?? 'n/a')) ?> · <?= e((string)($link['target_health_latency_ms'] ?? 'n/a')) ?> ms · 连续失败 <?= (int)($link['target_health_consecutive_failures'] ?? 0) ?></div><?php if (!empty($link['target_health_checked_at'])): ?><time class="muted" datetime="<?= e((string)$link['target_health_checked_at']) ?>" data-local-time><?= e((string)$link['target_health_checked_at']) ?> UTC</time><?php endif; ?></td>
                <td data-label="有效目标"><?php if (!empty($link['target_health_effective_url'])): ?><a href="<?= e((string)$link['target_health_effective_url']) ?>" target="_blank" rel="noopener"><?= e((string)$link['target_health_effective_url']) ?></a><?php else: ?><span class="muted">未建立连接</span><?php endif; ?></td>
                <td data-label="重定向"><code><?= e((string)$link['target_health_redirect_state']) ?></code><?php if ($redirectChain): ?><details class="redirect-chain"><summary>查看重定向链（<?= count($redirectChain) ?>）</summary><ol><?php foreach ($redirectChain as $hop): ?><li><code><?= e((string)($hop['status'] ?? '')) ?></code><span><?= e((string)($hop['url'] ?? '')) ?></span></li><?php endforeach; ?></ol></details><?php endif; ?></td>
                <td data-label="修复"><details class="repair-workflow"><summary class="button button-secondary button-small">处理异常</summary><div><form method="post" action="<?= e(app_path('/maintenance/repair')) ?>"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int)$link['id'] ?>"><input type="hidden" name="updated_at" value="<?= e((string)$link['updated_at']) ?>"><input type="hidden" name="target_url_hash" value="<?= e((string)$link['target_health_target_url_hash']) ?>"><label>地址<input type="url" name="url" value="<?= e((string)$link['target_url']) ?>" required></label><div class="repair-actions"><button class="button-small" type="submit" name="repair_action" value="target">更新目标并重检</button><button class="button-secondary button-small" type="submit" name="repair_action" value="fallback">设为备用并重检</button></div></form><form method="post" action="<?= e(app_path('/maintenance/repair')) ?>"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int)$link['id'] ?>"><input type="hidden" name="updated_at" value="<?= e((string)$link['updated_at']) ?>"><input type="hidden" name="target_url_hash" value="<?= e((string)$link['target_health_target_url_hash']) ?>"><label>忽略原因<input type="text" name="ignore_reason" maxlength="200" placeholder="可选"></label><div class="repair-actions"><button class="button-secondary button-small" type="submit" name="repair_action" value="ignore">忽略</button><button class="button-danger button-small" type="submit" name="repair_action" value="disable" data-confirm="确认停用这条异常链接？">停用</button></div></form><form method="post" action="<?= e(app_path('/maintenance/recheck')) ?>"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="selected[]" value="<?= (int)$link['id'] ?>"><button class="button-secondary button-small" type="submit"><svg class="icon"><use href="#icon-refresh"/></svg>仅重新检查</button></form><a class="button button-secondary button-small" href="<?= e(app_path('/edit?id=' . (int)$link['id'])) ?>"><svg class="icon"><use href="#icon-pencil"/></svg>完整编辑</a></div></details></td>
            </tr>
        <?php endforeach; ?>
        <?php else: ?>
        <table class="maintenance-table"><thead><tr><th class="column-select"><input type="checkbox" data-select-all aria-label="选择本页全部链接"></th><th>链接</th><th>状态与有效期</th><th>使用次数</th><th>标签</th></tr></thead><tbody>
        <?php foreach ($links as $link): ?><tr><td class="column-select" data-label="选择"><input type="checkbox" name="selected[]" value="<?= (int)$link['id'] ?>" form="maintenance-bulk-form" data-row-select aria-label="选择 <?= e((string)$link['slug']) ?>"></td><td data-label="链接"><a href="<?= e(app_path('/link') . '?id=' . (int)$link['id']) ?>"><strong><?= e((string)($link['title'] !== '' ? $link['title'] : $link['slug'])) ?></strong></a><div class="muted"><?= e((string)$link['slug']) ?></div></td><td data-label="状态与有效期"><span class="status <?= e(link_status_key($link)) ?>"><?= e(link_status_label($link)) ?></span><?php if (!empty($link['expires_at'])): ?><div class="muted">过期：<time datetime="<?= e((string)$link['expires_at']) ?>" data-local-time><?= e((string)$link['expires_at']) ?> UTC</time></div><?php endif; ?></td><td data-label="使用次数"><strong><?= (int)$link['clicks'] ?></strong><?php if ($link['max_clicks'] !== null || (int)$link['is_one_time'] === 1): ?> / <?= (int)$link['is_one_time'] === 1 ? 1 : (int)$link['max_clicks'] ?><?php else: ?><span class="muted"> / 不限</span><?php endif; ?></td><td data-label="标签"><div class="tag-list"><?php foreach (split_stored_tags((string)$link['tags']) as $linkTag): ?><span class="tag-chip"><?= e($linkTag) ?></span><?php endforeach; ?></div></td></tr><?php endforeach; ?>
        <?php endif; ?>
        </tbody></table></div>
        <?php $lastPage = max(1, (int)ceil($totalLinks / $pageSize)); if ($lastPage > 1): ?><div class="pagination"><?php if ($page > 1): ?><a class="button button-secondary button-small" href="<?= e(list_path($search, 'active', $page - 1, 'all', $sort, $tag, false, 0, 'maintenance', $maintenanceCategory)) ?>">上一页</a><?php endif; ?><span class="muted">第 <?= $page ?> / <?= $lastPage ?> 页</span><?php if ($page < $lastPage): ?><a class="button button-secondary button-small" href="<?= e(list_path($search, 'active', $page + 1, 'all', $sort, $tag, false, 0, 'maintenance', $maintenanceCategory)) ?>">下一页</a><?php endif; ?></div><?php endif; ?>
    <?php else: ?><div class="empty"><?php if ($search !== '' || $tag !== ''): ?>没有符合当前筛选条件的链接。请调整搜索词或标签筛选，或<a href="<?= e(list_path('', 'active', 1, 'all', 'created_desc', '', false, 0, 'maintenance', $maintenanceCategory)) ?>">清除筛选</a>。<?php else: ?>当前分类没有需要维护的链接。<?php endif; ?></div><?php endif; ?>
</section>
