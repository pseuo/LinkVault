<!doctype html>
<html lang="zh-CN" data-brand-theme="<?= e((string)$brand['brand_theme']) ?>" data-brand-color="<?= e((string)$brand['brand_color']) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <meta name="theme-color" content="#ffffff" data-theme-color>
    <meta name="description" content="<?= e((string)($brand['brand_tagline'] !== '' ? $brand['brand_tagline'] : $brand['brand_name'] . ' 短链接服务')) ?>">
    <title><?= e((string)$brand['brand_name']) ?></title>
    <script src="<?= e(asset_path('theme-init.js')) ?>"></script>
    <link rel="icon" href="<?= e((string)($brand['favicon_url'] !== '' ? $brand['favicon_url'] : asset_path('icon.svg'))) ?>">
    <link rel="stylesheet" href="<?= e(asset_path('app.css')) ?>">
    <script src="<?= e(asset_path('app.js')) ?>" defer></script>
</head>
<body class="public-page public-brand-page">
<a class="skip-link" href="#main-content">跳到主要内容</a>
<div class="public-page-frame public-brand-frame">
    <header class="public-page-brand">
        <?php if ((string)$brand['logo_url'] !== ''): ?><img class="public-page-brand-logo" src="<?= e((string)$brand['logo_url']) ?>" alt="<?= e((string)$brand['brand_name']) ?> Logo"><?php else: ?><span class="public-brand-mark" aria-hidden="true">↗</span><?php endif; ?>
        <span><strong><?= e((string)$brand['brand_name']) ?></strong><small>短链接服务</small></span>
    </header>
    <main id="main-content" class="public-page-surface public-brand-surface" tabindex="-1">
        <p class="public-page-eyebrow">BRANDED LINK SERVICE</p>
        <h1><?= e((string)$brand['brand_name']) ?></h1>
        <?php if ((string)$brand['brand_tagline'] !== ''): ?><p class="public-brand-tagline"><?= e((string)$brand['brand_tagline']) ?></p><?php endif; ?>
        <div class="public-service-status"><span aria-hidden="true"></span><strong>服务已就绪</strong><small>短链接可安全访问</small></div>
    </main>
    <footer class="public-page-footer"><span>独立域名</span><span>安全跳转</span><span>隐私友好</span></footer>
</div>
</body>
</html>
