<?php
$publicBrand = current_short_domain();
$publicBrandName = is_array($publicBrand) ? (string)$publicBrand['brand_name'] : '链匣 LinkVault';
$publicBrandColor = is_array($publicBrand) ? (string)$publicBrand['brand_color'] : '';
$publicBrandLogo = is_array($publicBrand) ? (string)$publicBrand['logo_url'] : '';
$publicBrandFavicon = is_array($publicBrand) ? (string)$publicBrand['favicon_url'] : '';
?>
<!doctype html>
<html lang="zh-CN"<?= $publicBrandColor !== '' ? ' data-brand-color="' . e($publicBrandColor) . '"' : '' ?>>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <meta name="theme-color" content="#ffffff" data-theme-color>
    <title>确认访问 - <?= e($publicBrandName) ?></title>
    <script src="<?= e(asset_path('theme-init.js')) ?>"></script>
    <link rel="icon" href="<?= e($publicBrandFavicon !== '' ? $publicBrandFavicon : asset_path('icon.svg')) ?>">
    <link rel="stylesheet" href="<?= e(asset_path('app.css')) ?>">
    <script src="<?= e(asset_path('app.js')) ?>" defer></script>
</head>
<body class="public-page confirmation-page">
<?php $targetPort = isset($targetParts['port']) ? (int)$targetParts['port'] : ($targetScheme === 'https' ? 443 : 80); ?>
<a class="skip-link" href="#main-content">跳到主要内容</a>
<main id="main-content" class="public-page-frame confirmation-main" tabindex="-1">
    <header class="public-page-brand">
        <div class="public-confirmation-brand">
            <?php if ($publicBrandLogo !== ''): ?><img src="<?= e($publicBrandLogo) ?>" alt="<?= e($publicBrandName) ?> Logo"><?php else: ?><span class="public-brand-mark" aria-hidden="true">↗</span><?php endif; ?>
            <span><strong><?= e($publicBrandName) ?></strong><small>安全访问</small></span>
        </div>
    </header>
    <section class="public-page-surface confirmation-panel" aria-labelledby="confirmation-title">
        <div class="public-state-heading">
            <div class="confirmation-mark" aria-hidden="true"><svg class="icon" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/><path d="m9 12 2 2 4-4"/></svg></div>
            <div><p class="confirmation-label">一次性链接</p><h1 id="confirmation-title">确认访问<?= $link['title'] !== '' ? '“' . e((string)$link['title']) . '”' : '' ?></h1><p class="muted">请核对目标站点后继续。</p></div>
        </div>
        <dl class="confirmation-target">
            <div><dt>协议</dt><dd><?= e(strtoupper($targetScheme)) ?></dd></div>
            <div><dt>域名</dt><dd><?= e($targetDisplayHost) ?></dd></div>
            <div><dt>端口</dt><dd><?= $targetPort ?><?= isset($targetParts['port']) ? '' : '（默认）' ?></dd></div>
            <div class="confirmation-target-full"><dt>完整目标地址</dt><dd><code><?= e((string)$link['target_url']) ?></code></dd></div>
            <?php if (array_filter([(string)($link['campaign_name'] ?? ''), (string)($link['campaign_source'] ?? ''), (string)($link['campaign_medium'] ?? ''), (string)($link['campaign_content'] ?? '')])): ?>
                <div class="confirmation-target-full"><dt>活动来源</dt><dd><?= e((string)($link['campaign_name'] !== '' ? $link['campaign_name'] : '未命名活动')) ?><?php if ((string)$link['campaign_source'] !== ''): ?> · <?= e((string)$link['campaign_source']) ?><?php endif; ?><?php if ((string)$link['campaign_medium'] !== ''): ?> / <?= e((string)$link['campaign_medium']) ?><?php endif; ?><?php if ((string)$link['campaign_content'] !== ''): ?> · <?= e((string)$link['campaign_content']) ?><?php endif; ?></dd></div>
            <?php endif; ?>
        </dl>
        <div class="confirmation-actions">
            <form method="post" action="<?= e(app_path('/' . rawurlencode((string)$link['slug']) . '/confirm')) ?>">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <button type="submit">确认并继续</button>
            </form>
            <form method="post" action="<?= e(app_path('/' . rawurlencode((string)$link['slug']) . '/confirm')) ?>"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="cancel" value="1"><button class="button-secondary" type="submit">取消访问</button></form>
        </div>
        <p class="confirmation-note">确认后链接立即失效，无法再次访问。</p>
    </section>
    <footer class="public-page-footer"><span>一次性访问</span><span>请核对目标</span></footer>
</main>
</body>
</html>
