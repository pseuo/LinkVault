<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <meta name="theme-color" content="#ffffff" data-theme-color>
    <title><?= e((string)($detailLink['title'] !== '' ? $detailLink['title'] : $detailLink['slug'])) ?> - 链匣 LinkVault</title>
    <script src="<?= e(asset_path('theme-init.js')) ?>"></script>
    <link rel="icon" href="<?= e(asset_path('icon.svg')) ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(asset_path('app.css')) ?>">
    <script src="<?= e(asset_path('qrcode.min.js')) ?>" defer></script>
    <script src="<?= e(asset_path('app.js')) ?>" defer></script>
</head>
<body class="dashboard-page detail-page">
<svg class="icon-sprite" aria-hidden="true" xmlns="http://www.w3.org/2000/svg">
    <symbol id="icon-link" viewBox="0 0 24 24"><path d="M9 17H7A5 5 0 0 1 7 7h2"/><path d="M15 7h2a5 5 0 1 1 0 10h-2"/><path d="M8 12h8"/></symbol>
    <symbol id="icon-moon" viewBox="0 0 24 24"><path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/></symbol>
    <symbol id="icon-sun" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.42 1.42M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.42"/></symbol>
    <symbol id="icon-logout" viewBox="0 0 24 24"><path d="M10 17l5-5-5-5M15 12H3M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/></symbol>
    <symbol id="icon-arrow-left" viewBox="0 0 24 24"><path d="m15 18-6-6 6-6"/><path d="M9 12h12"/></symbol>
    <symbol id="icon-copy" viewBox="0 0 24 24"><rect width="14" height="14" x="8" y="8" rx="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/></symbol>
    <symbol id="icon-share" viewBox="0 0 24 24"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="m8.6 10.5 6.8-4M8.6 13.5l6.8 4"/></symbol>
    <symbol id="icon-pencil" viewBox="0 0 24 24"><path d="M12 20h9M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></symbol>
    <symbol id="icon-chart" viewBox="0 0 24 24"><path d="M3 3v18h18M7 16v-3M12 16V8M17 16v-5"/></symbol>
    <symbol id="icon-clock" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></symbol>
    <symbol id="icon-qr" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><path d="M14 14h3v3h-3zM18 18h3v3h-3zM18 14h3M14 18v3"/></symbol>
    <symbol id="icon-check-circle" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="m8 12 2.6 2.6L16.5 9"/></symbol>
    <symbol id="icon-alert-circle" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16.5v.01"/></symbol>
    <symbol id="icon-lock" viewBox="0 0 24 24"><rect width="18" height="11" x="3" y="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4M12 15v2"/></symbol>
</svg>
<?php $shortUrl = short_url_base($config, $detailLink) . '/' . rawurlencode((string)$detailLink['slug']); ?>
<?php $statusKey = link_status_key($detailLink); ?>
<?php $trendMax = max(1, ...array_map(static fn (array $item): int => (int)$item['clicks'], $linkTrend)); ?>
<?php $editPath = app_path('/edit') . '?' . http_build_query(array_merge(['id' => (int)$detailLink['id'], 'return_to_detail' => '1'], $detailReturnParameters)); ?>
<a class="skip-link" href="#main-content">跳到主要内容</a>
<header class="site-header">
    <div class="header-inner">
        <div class="brand">
            <span class="brand-mark" aria-hidden="true"><svg class="icon icon-lg"><use href="#icon-link"/></svg></span>
            <div><h1>链匣 LinkVault</h1><div class="muted">链接详情</div></div>
        </div>
        <div class="header-actions">
            <a class="button button-secondary icon-button" href="<?= e($returnPath) ?>" title="返回链接列表" aria-label="返回链接列表"><svg class="icon" aria-hidden="true"><use href="#icon-arrow-left"/></svg></a>
            <button class="button-secondary theme-toggle" type="button" data-theme-toggle title="切换深色模式" aria-label="切换深色模式" aria-pressed="false"><svg class="icon moon-icon" aria-hidden="true"><use href="#icon-moon"/></svg><svg class="icon sun-icon" aria-hidden="true"><use href="#icon-sun"/></svg></button>
            <form method="post" action="<?= e(app_path('/logout')) ?>"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><button class="button-secondary logout-button" type="submit" aria-label="退出登录" title="退出登录"><svg class="icon" aria-hidden="true"><use href="#icon-logout"/></svg><span>退出</span></button></form>
        </div>
    </div>
</header>
<main id="main-content" tabindex="-1">
    <section class="detail-heading">
        <div>
            <div class="detail-title-row"><h2><?= e((string)($detailLink['title'] !== '' ? $detailLink['title'] : $detailLink['slug'])) ?></h2><span class="status <?= e($statusKey) ?>"><?= e(link_status_label($detailLink)) ?></span><?php if (link_is_password_protected($detailLink)): ?><span class="access-indicator"><svg class="icon" aria-hidden="true"><use href="#icon-lock"/></svg>密码保护</span><?php endif; ?><?php if ((int)$detailLink['is_favorite'] === 1): ?><span class="favorite-label">已收藏</span><?php endif; ?></div>
            <a class="detail-short-url" id="detail-short-url" href="<?= e($shortUrl) ?>" target="_blank" rel="noopener"><?= e($shortUrl) ?></a>
            <div class="tag-list"><?php foreach (split_stored_tags((string)$detailLink['tags']) as $detailTag): ?><span class="tag-chip"><?= e($detailTag) ?></span><?php endforeach; ?></div>
        </div>
        <div class="detail-actions">
            <button class="button-secondary" type="button" data-copy="<?= e($shortUrl) ?>" data-copy-target="detail-short-url"><svg class="icon" aria-hidden="true"><use href="#icon-copy"/></svg>复制</button>
            <button class="button-secondary" type="button" data-share data-share-url="<?= e($shortUrl) ?>" data-share-title="<?= e((string)$detailLink['title']) ?>" data-copy-target="detail-short-url"><svg class="icon" aria-hidden="true"><use href="#icon-share"/></svg>分享</button>
            <?php if (empty($detailLink['deleted_at'])): ?><a class="button button-secondary" href="<?= e($editPath) ?>"><svg class="icon" aria-hidden="true"><use href="#icon-pencil"/></svg>编辑</a><?php endif; ?>
        </div>
    </section>

    <?php if (is_array($detailFlash)): ?><?php $detailFlashType = (string)($detailFlash['type'] ?? 'ok'); ?><div class="flash <?= e($detailFlashType) ?>" role="<?= $detailFlashType === 'error' ? 'alert' : 'status' ?>"><span class="flash-icon" aria-hidden="true"><svg class="icon"><use href="#icon-<?= $detailFlashType === 'error' ? 'alert' : 'check' ?>-circle"/></svg></span><div class="flash-content"><?= e((string)($detailFlash['message'] ?? '')) ?></div></div><?php endif; ?>

    <section class="detail-metrics" aria-label="链接统计">
        <div class="metric"><span>累计跳转</span><strong><?= (int)$detailLink['clicks'] ?></strong><small>次</small></div>
        <div class="metric"><span>所选周期</span><strong><?= array_sum(array_column($linkTrend, 'clicks')) ?></strong><small><?= $trendDays ?> 日</small></div>
        <div class="metric"><span>剩余次数</span><strong><?php if ((int)$detailLink['is_one_time'] === 1): ?><?= max(0, 1 - (int)$detailLink['clicks']) ?><?php elseif ($detailLink['max_clicks'] !== null): ?><?= max(0, (int)$detailLink['max_clicks'] - (int)$detailLink['clicks']) ?><?php else: ?>不限<?php endif; ?></strong></div>
    </section>

    <div class="detail-grid">
        <section class="panel trend-panel">
            <div class="stats-heading"><span class="section-heading"><span class="section-icon" aria-hidden="true"><svg class="icon"><use href="#icon-chart"/></svg></span><h2>访问趋势</h2></span><nav class="range-tabs" aria-label="趋势周期"><?php foreach ([7, 14, 30] as $range): ?><?php $trendQuery = array_merge(['id' => (int)$detailLink['id'], 'days' => $range], $detailReturnParameters); ?><a class="<?= $trendDays === $range ? 'selected' : '' ?>" href="<?= e(app_path('/link') . '?' . http_build_query($trendQuery)) ?>"<?= $trendDays === $range ? ' aria-current="page"' : '' ?>><?= $range ?> 天</a><?php endforeach; ?></nav></div>
            <div class="trend-list"><?php foreach ($linkTrend as $stat): ?><div class="trend-row"><time datetime="<?= e($stat['accessed_on']) ?>"><?= e(substr($stat['accessed_on'], 5)) ?></time><progress max="<?= $trendMax ?>" value="<?= (int)$stat['clicks'] ?>" aria-label="<?= e((string)$stat['accessed_on']) ?>：<?= (int)$stat['clicks'] ?> 次跳转"><?= (int)$stat['clicks'] ?></progress><strong><?= (int)$stat['clicks'] ?></strong></div><?php endforeach; ?></div>
        </section>

        <aside class="panel qr-panel">
            <div class="section-heading"><span class="section-icon" aria-hidden="true"><svg class="icon"><use href="#icon-qr"/></svg></span><h2>二维码</h2></div>
            <div class="qr-code" data-qr-value="<?= e($shortUrl) ?>" data-qr-label="<?= e($shortUrl) ?> 的二维码" role="status" aria-live="polite">正在生成二维码</div>
            <a class="button button-secondary" data-qr-download download="<?= e((string)$detailLink['slug']) ?>-qr.svg" aria-disabled="true" hidden>下载二维码</a>
        </aside>
    </div>

    <div class="detail-grid detail-grid-lower">
        <section class="panel metadata-panel">
            <div class="section-heading"><span class="section-icon" aria-hidden="true"><svg class="icon"><use href="#icon-clock"/></svg></span><h2>时间与限制</h2></div>
            <dl class="metadata-list">
                <div><dt>创建时间</dt><dd><time datetime="<?= e((string)$detailLink['created_at']) ?>" data-local-time><?= e((string)$detailLink['created_at']) ?> UTC</time></dd></div>
                <div><dt>最后访问</dt><dd><?php if (!empty($detailLink['last_accessed_at'])): ?><time datetime="<?= e((string)$detailLink['last_accessed_at']) ?>" data-local-time><?= e((string)$detailLink['last_accessed_at']) ?> UTC</time><?php else: ?>暂无<?php endif; ?></dd></div>
                <div><dt>定时启用</dt><dd><?php if (!empty($detailLink['starts_at'])): ?><time datetime="<?= e((string)$detailLink['starts_at']) ?>" data-local-time><?= e((string)$detailLink['starts_at']) ?> UTC</time><?php else: ?>立即<?php endif; ?></dd></div>
                <div><dt>过期时间</dt><dd><?php if (!empty($detailLink['expires_at'])): ?><time datetime="<?= e((string)$detailLink['expires_at']) ?>" data-local-time><?= e((string)$detailLink['expires_at']) ?> UTC</time><?php else: ?>永不过期<?php endif; ?></dd></div>
                <div><dt>点击上限</dt><dd><?= (int)$detailLink['is_one_time'] === 1 ? '一次性链接' : ($detailLink['max_clicks'] !== null ? (int)$detailLink['max_clicks'] . ' 次' : '不限') ?></dd></div>
                <?php if ((int)$detailLink['is_one_time'] === 1): ?><div><dt>消费方式</dt><dd><?= (string)($detailLink['one_time_mode'] ?? 'immediate') === 'confirm' ? '确认访问后消费' : '首次访问即消费' ?></dd></div><?php endif; ?>
                <div><dt>访问保护</dt><dd><?= (int)($detailLink['access_password_reset_required'] ?? 0) === 1 ? '须重新设置密码后启用' : (link_is_password_protected($detailLink) ? '需要密码' : '未设置') ?></dd></div>
                <div><dt>失效提示</dt><dd><?= (string)($detailLink['invalid_message'] ?? '') !== '' ? e((string)$detailLink['invalid_message']) : '使用默认提示' ?></dd></div>
                <div><dt>备用地址</dt><dd><?php if (!empty($detailLink['fallback_url'])): ?><a href="<?= e((string)$detailLink['fallback_url']) ?>" target="_blank" rel="noopener"><?= e((string)$detailLink['fallback_url']) ?></a><?php else: ?>未设置<?php endif; ?></dd></div>
                <div><dt>目标健康</dt><dd><?php if (is_array($targetHealth)): ?><strong><?= e((string)$targetHealth['state']) ?></strong> · <?= e((string)$targetHealth['reason']) ?><?php if (!empty($targetHealth['checked_at'])): ?><br><time class="muted" datetime="<?= e((string)$targetHealth['checked_at']) ?>" data-local-time><?= e((string)$targetHealth['checked_at']) ?> UTC</time><?php endif; ?><?php if (!empty($targetHealth['effective_url'])): ?><br><a href="<?= e((string)$targetHealth['effective_url']) ?>" target="_blank" rel="noopener"><?= e((string)$targetHealth['effective_url']) ?></a><?php endif; ?><br><span class="muted">重定向 <?= e((string)$targetHealth['redirect_state']) ?> · 连续失败 <?= (int)$targetHealth['consecutive_failures'] ?></span><?php else: ?>尚未检查<?php endif; ?></dd></div>
                <?php if (array_filter([(string)($detailLink['campaign_name'] ?? ''), (string)($detailLink['campaign_source'] ?? ''), (string)($detailLink['campaign_medium'] ?? ''), (string)($detailLink['campaign_content'] ?? '')])): ?><div><dt>广告活动</dt><dd><?= e((string)($detailLink['campaign_name'] !== '' ? $detailLink['campaign_name'] : '未命名活动')) ?> · <?= e((string)($detailLink['campaign_source'] !== '' ? $detailLink['campaign_source'] : '-')) ?> / <?= e((string)($detailLink['campaign_medium'] !== '' ? $detailLink['campaign_medium'] : '-')) ?><?php if ((string)$detailLink['campaign_content'] !== ''): ?> · <?= e((string)$detailLink['campaign_content']) ?><?php endif; ?></dd></div><?php endif; ?>
                <div><dt>目标地址</dt><dd><a href="<?= e((string)$detailLink['target_url']) ?>" target="_blank" rel="noopener"><?= e((string)$detailLink['target_url']) ?></a></dd></div>
                <div><dt>旧短码别名</dt><dd><?php if ($detailAliases): ?><?php foreach ($detailAliases as $alias): ?><code><?= e((string)$alias['alias']) ?></code> <?php endforeach; ?><?php else: ?>暂无<?php endif; ?></dd></div>
            </dl>
        </section>

        <section class="panel history-panel">
            <div class="section-heading"><span class="section-icon" aria-hidden="true"><svg class="icon"><use href="#icon-clock"/></svg></span><h2>状态变更</h2></div>
            <?php if (!$statusHistory): ?><div class="empty">暂无状态记录。</div><?php else: ?><ol class="history-list"><?php foreach ($statusHistory as $history): ?><?php $eventLabel = match ((string)$history['event']) { 'created' => '创建链接', 'imported' => '导入链接', 'import_overwritten' => '导入覆盖', 'enabled' => '启用链接', 'disabled' => '停用链接', 'deleted' => '移入回收站', 'restored' => '从回收站恢复', 'expiration_cleared' => '清除过期时间', 'click_limit_reached' => '点击次数已用尽', 'settings_updated' => '有效期设置变更', default => (string)$history['event'] }; ?><li><span><?= e($eventLabel) ?></span><time datetime="<?= e((string)$history['created_at']) ?>" data-local-time><?= e((string)$history['created_at']) ?> UTC</time></li><?php endforeach; ?></ol><?php endif; ?>
        </section>
    </div>
</main>
<div id="copy-feedback" class="copy-feedback" role="status" aria-live="polite" hidden></div>
</body>
</html>
