<?php
$publicBrand = current_short_domain();
$publicBrandName = is_array($publicBrand) ? (string)$publicBrand['brand_name'] : '链匣 LinkVault';
$publicBrandColor = is_array($publicBrand) ? (string)$publicBrand['brand_color'] : '';
$publicBrandLogo = is_array($publicBrand) ? (string)$publicBrand['logo_url'] : '';
?>
<!doctype html>
<html lang="zh-CN"<?= $publicBrandColor !== '' ? ' data-brand-color="' . e($publicBrandColor) . '"' : '' ?>>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <meta name="theme-color" content="#ffffff" data-theme-color>
    <title><?= $safeStatus ?> - <?= $safeTitle ?> - <?= e($publicBrandName) ?></title>
    <script src="<?= e(asset_path('theme-init.js')) ?>"></script>
    <link rel="icon" href="<?= e(is_array($publicBrand) && (string)$publicBrand['favicon_url'] !== '' ? (string)$publicBrand['favicon_url'] : asset_path('icon.svg')) ?>">
    <link rel="stylesheet" href="<?= e(asset_path('error.css')) ?>">
    <script src="<?= e(asset_path('app.js')) ?>" defer></script>
</head>
<body class="public-error-page">
<a class="skip-link" href="#main-content">跳到主要内容</a>
<main id="main-content" class="public-error-frame" tabindex="-1">
    <header class="error-brand"><?php if ($publicBrandLogo !== ''): ?><img src="<?= e($publicBrandLogo) ?>" alt="<?= e($publicBrandName) ?> Logo"><?php else: ?><span aria-hidden="true">↗</span><?php endif; ?><span><b><?= e($publicBrandName) ?></b><small>访问状态</small></span></header>
    <?php $brandInvalidPage = is_array($publicBrand) && $safeStatus === '404'; ?>
    <section class="error-surface" aria-labelledby="error-title">
        <div class="error-state-heading"><span class="error-state-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16.5v.01"/></svg></span><div><strong>HTTP <?= $safeStatus ?></strong><h1 id="error-title"><?= $brandInvalidPage ? e((string)$publicBrand['invalid_page_title']) : $safeTitle ?></h1></div></div>
        <p><?= $brandInvalidPage ? e((string)$publicBrand['invalid_page_message']) : $safeMessage ?></p>
        <nav aria-label="错误恢复操作">
            <a class="primary-action" href="">重试</a>
            <a href="<?= e(app_path('/')) ?>">返回首页</a>
            <?php if ($publicBrand === null): ?><a href="<?= e(app_path('/login')) ?>">管理员登录</a><?php endif; ?>
        </nav>
    </section>
    <footer class="error-footer"><span>请求编号</span><code><?= $safeRequestId ?></code></footer>
</main>
</body>
</html>
