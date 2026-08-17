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
    <title>验证访问密码 - <?= e($publicBrandName) ?></title>
    <script src="<?= e(asset_path('theme-init.js')) ?>"></script>
    <link rel="icon" href="<?= e($publicBrandFavicon !== '' ? $publicBrandFavicon : asset_path('icon.svg')) ?>">
    <link rel="stylesheet" href="<?= e(asset_path('app.css')) ?>">
    <script src="<?= e(asset_path('app.js')) ?>" defer></script>
</head>
<body class="public-page confirmation-page">
<a class="skip-link" href="#main-content">跳到主要内容</a>
<main id="main-content" class="public-page-frame confirmation-main" tabindex="-1">
    <header class="public-page-brand">
        <div class="public-confirmation-brand">
            <?php if ($publicBrandLogo !== ''): ?><img src="<?= e($publicBrandLogo) ?>" alt="<?= e($publicBrandName) ?> Logo"><?php else: ?><span class="public-brand-mark" aria-hidden="true">↗</span><?php endif; ?>
            <span><strong><?= e($publicBrandName) ?></strong><small>安全访问</small></span>
        </div>
    </header>
    <section class="public-page-surface confirmation-panel password-panel" aria-labelledby="password-title">
        <div class="public-state-heading">
            <div class="confirmation-lock" aria-hidden="true"><svg class="icon" viewBox="0 0 24 24"><rect width="18" height="11" x="3" y="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4M12 15v2"/></svg></div>
            <div><p class="confirmation-label">受保护的短链接</p><h1 id="password-title">请输入访问密码</h1><p class="muted">验证通过后，本次访问可继续。</p></div>
        </div>
        <?php if (is_string($error) && $error !== ''): ?>
            <p class="password-error" id="password-error" role="alert"><?= e($error) ?></p>
        <?php endif; ?>
        <form method="post" action="<?= e(app_path('/' . rawurlencode((string)$link['slug']) . '/unlock')) ?>">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <label for="link-access-password">访问密码
                <input id="link-access-password" type="password" name="password" maxlength="1024" autocomplete="current-password" required autofocus aria-describedby="<?= is_string($error) && $error !== '' ? 'password-error ' : '' ?>password-help"<?= is_string($error) && $error !== '' ? ' aria-invalid="true"' : '' ?>>
            </label>
            <div class="confirmation-actions"><button type="submit">验证并继续</button><button class="button button-secondary" type="button" data-cancel-access>取消访问</button><a class="button button-secondary" href="<?= e(app_path('/')) ?>">返回首页</a></div>
        </form>
        <p class="confirmation-note" id="password-help">访问密码由链接创建者提供，系统不会显示目标地址。</p>
    </section>
    <footer class="public-page-footer"><span>加密验证</span><span>目标保护</span></footer>
</main>
</body>
</html>
