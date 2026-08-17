<?php
$useSubmittedEdit = $editValues !== [] || $editErrors !== [];
$expirationUtc = is_string($editLink['expires_at'] ?? null) ? $editLink['expires_at'] : '';
$startsUtc = is_string($editLink['starts_at'] ?? null) ? $editLink['starts_at'] : '';
$submittedValue = static function (string $name, string $fallback) use ($useSubmittedEdit, $editValues): string {
    return $useSubmittedEdit && is_string($editValues[$name] ?? null)
        ? $editValues[$name]
        : $fallback;
};
$editTargetUrl = $submittedValue('target_url', (string)$editLink['target_url']);
$editSlug = $submittedValue('custom_slug', (string)$editLink['slug']);
$editTitle = $submittedValue('title', (string)$editLink['title']);
$editTags = $submittedValue('tags', format_tags_input(split_stored_tags((string)$editLink['tags'])));
$editStarts = $submittedValue('starts_at', expiration_input_value($startsUtc));
$editExpiration = $submittedValue('expires_at', expiration_input_value($expirationUtc));
$editMaxClicks = $submittedValue('max_clicks', $editLink['max_clicks'] === null ? '' : (string)$editLink['max_clicks']);
$editOneTimeMode = $submittedValue('one_time_mode', (string)($editLink['one_time_mode'] ?? 'immediate'));
$editCampaignName = $submittedValue('campaign_name', (string)($editLink['campaign_name'] ?? ''));
$editCampaignSource = $submittedValue('campaign_source', (string)($editLink['campaign_source'] ?? ''));
$editCampaignMedium = $submittedValue('campaign_medium', (string)($editLink['campaign_medium'] ?? ''));
$editCampaignContent = $submittedValue('campaign_content', (string)($editLink['campaign_content'] ?? ''));
$editFallbackUrl = $submittedValue('fallback_url', (string)($editLink['fallback_url'] ?? ''));
$editInvalidMessage = $submittedValue('invalid_message', (string)($editLink['invalid_message'] ?? ''));
$editOneTime = $useSubmittedEdit
    ? ($editValues['is_one_time'] ?? '') === '1'
    : (int)$editLink['is_one_time'] === 1;
$editFavorite = $useSubmittedEdit
    ? ($editValues['is_favorite'] ?? '') === '1'
    : (int)$editLink['is_favorite'] === 1;
$editRemovePassword = $useSubmittedEdit && ($editValues['remove_access_password'] ?? '') === '1';
$editPasswordResetRequired = (int)($editLink['access_password_reset_required'] ?? 0) === 1;
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <meta name="theme-color" content="#ffffff" data-theme-color>
    <title>编辑 <?= e((string)$editLink['slug']) ?> - 链匣 LinkVault</title>
    <script src="<?= e(asset_path('theme-init.js')) ?>"></script>
    <link rel="icon" href="<?= e(asset_path('icon.svg')) ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(asset_path('app.css')) ?>">
    <script src="<?= e(asset_path('app.js')) ?>" defer></script>
</head>
<body class="dashboard-page edit-page">
<svg class="icon-sprite" aria-hidden="true" xmlns="http://www.w3.org/2000/svg">
    <symbol id="icon-link" viewBox="0 0 24 24"><path d="M9 17H7A5 5 0 0 1 7 7h2"/><path d="M15 7h2a5 5 0 1 1 0 10h-2"/><path d="M8 12h8"/></symbol>
    <symbol id="icon-moon" viewBox="0 0 24 24"><path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/></symbol>
    <symbol id="icon-sun" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.42 1.42M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.42"/></symbol>
    <symbol id="icon-logout" viewBox="0 0 24 24"><path d="M10 17l5-5-5-5M15 12H3M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/></symbol>
    <symbol id="icon-arrow-left" viewBox="0 0 24 24"><path d="m15 18-6-6 6-6"/><path d="M9 12h12"/></symbol>
    <symbol id="icon-pencil" viewBox="0 0 24 24"><path d="M12 20h9M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></symbol>
    <symbol id="icon-save" viewBox="0 0 24 24"><path d="M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Z"/><path d="M17 21v-8H7v8M7 3v5h8"/></symbol>
    <symbol id="icon-check-circle" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="m8 12 2.6 2.6L16.5 9"/></symbol>
    <symbol id="icon-alert-circle" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16.5v.01"/></symbol>
</svg>
<a class="skip-link" href="#main-content">跳到主要内容</a>
<header class="site-header">
    <div class="header-inner">
        <div class="brand"><span class="brand-mark" aria-hidden="true"><svg class="icon icon-lg"><use href="#icon-link"/></svg></span><div><h1>链匣 LinkVault</h1><div class="muted">独立编辑页</div></div></div>
        <div class="header-actions">
            <a class="button button-secondary icon-button" href="<?= e($editReturnPath) ?>" title="<?= $editReturnsToDetail ? '返回链接详情' : '返回链接列表' ?>" aria-label="<?= $editReturnsToDetail ? '返回链接详情' : '返回链接列表' ?>"><svg class="icon" aria-hidden="true"><use href="#icon-arrow-left"/></svg></a>
            <button class="button-secondary theme-toggle" type="button" data-theme-toggle title="切换深色模式" aria-label="切换深色模式" aria-pressed="false"><svg class="icon moon-icon" aria-hidden="true"><use href="#icon-moon"/></svg><svg class="icon sun-icon" aria-hidden="true"><use href="#icon-sun"/></svg></button>
            <form method="post" action="<?= e(app_path('/logout')) ?>"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><button class="button-secondary logout-button" type="submit" aria-label="退出登录" title="退出登录"><svg class="icon" aria-hidden="true"><use href="#icon-logout"/></svg><span>退出</span></button></form>
        </div>
    </div>
</header>
<main id="main-content" class="standalone-edit-main" tabindex="-1">
    <section class="standalone-edit-heading">
        <div><p class="muted">链接 ID #<?= (int)$editLink['id'] ?></p><h2>编辑短链接：<?= e((string)$editLink['slug']) ?></h2></div>
        <a class="button button-secondary" href="<?= e($editReturnPath) ?>"><svg class="icon" aria-hidden="true"><use href="#icon-arrow-left"/></svg>取消</a>
    </section>
    <?php if (is_array($editFlash)): ?><?php $editFlashType = (string)($editFlash['type'] ?? 'ok'); ?><div class="flash <?= e($editFlashType) ?>" role="<?= $editFlashType === 'error' ? 'alert' : 'status' ?>"><span class="flash-icon" aria-hidden="true"><svg class="icon"><use href="#icon-<?= $editFlashType === 'error' ? 'alert' : 'check' ?>-circle"/></svg></span><div class="flash-content"><?= e((string)($editFlash['message'] ?? '')) ?></div></div><?php endif; ?>
    <section class="panel standalone-edit-panel" aria-label="短链接编辑表单">
        <form class="edit-form standalone-edit-form" method="post" action="<?= e(app_path('/edit')) ?>" data-preserve-draft="edit-link-<?= (int)$editLink['id'] ?>">
            <div class="standalone-edit-form-heading"><span class="section-icon" aria-hidden="true"><svg class="icon"><use href="#icon-pencil"/></svg></span><div><h3>链接设置</h3><p class="muted">保存前会检查链接是否已被其他页面修改。</p></div></div>
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="id" value="<?= (int)$editLink['id'] ?>">
            <input type="hidden" name="updated_at" value="<?= e((string)$editLink['updated_at']) ?>">
            <input type="hidden" name="standalone_edit" value="1">
            <?php if ($editReturnsToDetail): ?><input type="hidden" name="return_to_detail" value="1"><?php endif; ?>
            <?php foreach ($editReturnParameters as $name => $value): ?><input type="hidden" name="<?= e((string)$name) ?>" value="<?= e((string)$value) ?>"><?php endforeach; ?>
            <label>短码<input type="text" name="custom_slug" value="<?= e($editSlug) ?>" pattern="[A-Za-z0-9_\-]{3,64}" required<?= isset($editErrors['custom_slug']) ? ' aria-invalid="true" aria-describedby="edit-custom-slug-error"' : '' ?>><?php if (isset($editErrors['custom_slug'])): ?><span class="field-error" id="edit-custom-slug-error"><?= e((string)$editErrors['custom_slug']) ?></span><?php endif; ?></label>
            <label class="check-field"><input type="checkbox" name="preserve_old_slug" value="1" checked>修改后保留旧地址跳转</label>
            <?php if ($editAliases): ?><div class="field-note">现有旧地址：<?= e(implode('、', array_column($editAliases, 'alias'))) ?></div><?php endif; ?>
            <label>目标链接<input type="url" name="target_url" value="<?= e($editTargetUrl) ?>" maxlength="<?= (int)($config['target_url_max_length'] ?? 2048) ?>" required<?= isset($editErrors['target_url']) ? ' aria-invalid="true" aria-describedby="edit-target-url-error"' : '' ?>><?php if (isset($editErrors['target_url'])): ?><span class="field-error" id="edit-target-url-error"><?= e((string)$editErrors['target_url']) ?></span><?php endif; ?></label>
            <div class="form-row">
                <label>标题<input type="text" name="title" value="<?= e($editTitle) ?>" maxlength="120"<?= isset($editErrors['title']) ? ' aria-invalid="true" aria-describedby="edit-title-error"' : '' ?>><?php if (isset($editErrors['title'])): ?><span class="field-error" id="edit-title-error"><?= e((string)$editErrors['title']) ?></span><?php endif; ?></label>
                <label>标签<input type="text" name="tags" value="<?= e($editTags) ?>" maxlength="260"<?= isset($editErrors['tags']) ? ' aria-invalid="true" aria-describedby="edit-tags-error"' : '' ?>><?php if (isset($editErrors['tags'])): ?><span class="field-error" id="edit-tags-error"><?= e((string)$editErrors['tags']) ?></span><?php endif; ?></label>
            </div>
            <div class="form-row">
                <label>定时启用<input type="datetime-local" name="starts_at" value="<?= e($editStarts) ?>" data-start-input<?= !$useSubmittedEdit && $startsUtc !== '' ? ' data-utc-value="' . e($startsUtc) . '"' : '' ?><?= isset($editErrors['starts_at']) ? ' aria-invalid="true" aria-describedby="edit-starts-at-error"' : '' ?>><input type="hidden" name="starts_at_offset" value="0" data-start-offset><?php if (isset($editErrors['starts_at'])): ?><span class="field-error" id="edit-starts-at-error"><?= e((string)$editErrors['starts_at']) ?></span><?php endif; ?></label>
                <label>过期时间<input type="datetime-local" name="expires_at" value="<?= e($editExpiration) ?>" data-expiration-input<?= !$useSubmittedEdit && $expirationUtc !== '' ? ' data-utc-value="' . e($expirationUtc) . '"' : '' ?><?= isset($editErrors['expires_at']) ? ' aria-invalid="true" aria-describedby="edit-expires-at-error"' : '' ?>><input type="hidden" name="expires_at_offset" value="0" data-expiration-offset><?php if (isset($editErrors['expires_at'])): ?><span class="field-error" id="edit-expires-at-error"><?= e((string)$editErrors['expires_at']) ?></span><?php endif; ?></label>
            </div>
            <div class="form-row">
                <label>最大点击次数<input type="number" name="max_clicks" value="<?= e($editMaxClicks) ?>" min="1" max="2147483647"<?= isset($editErrors['max_clicks']) ? ' aria-invalid="true" aria-describedby="edit-max-clicks-error"' : '' ?>><?php if (isset($editErrors['max_clicks'])): ?><span class="field-error" id="edit-max-clicks-error"><?= e((string)$editErrors['max_clicks']) ?></span><?php endif; ?></label>
                <label>一次性消费方式<select name="one_time_mode" data-one-time-mode><option value="immediate"<?= $editOneTimeMode === 'immediate' ? ' selected' : '' ?>>首次访问即消费</option><option value="confirm"<?= $editOneTimeMode === 'confirm' ? ' selected' : '' ?>>确认访问后消费</option></select></label>
            </div>
            <fieldset class="edit-campaign-fields"><legend>广告活动归因</legend><div class="form-row"><label>活动名称<input type="text" name="campaign_name" value="<?= e($editCampaignName) ?>" maxlength="100"<?= isset($editErrors['campaign_name']) ? ' aria-invalid="true"' : '' ?>><?php if (isset($editErrors['campaign_name'])): ?><span class="field-error"><?= e((string)$editErrors['campaign_name']) ?></span><?php endif; ?></label><label>来源<input type="text" name="campaign_source" value="<?= e($editCampaignSource) ?>" maxlength="100"<?= isset($editErrors['campaign_source']) ? ' aria-invalid="true"' : '' ?>><?php if (isset($editErrors['campaign_source'])): ?><span class="field-error"><?= e((string)$editErrors['campaign_source']) ?></span><?php endif; ?></label></div><div class="form-row"><label>媒介<input type="text" name="campaign_medium" value="<?= e($editCampaignMedium) ?>" maxlength="100"<?= isset($editErrors['campaign_medium']) ? ' aria-invalid="true"' : '' ?>><?php if (isset($editErrors['campaign_medium'])): ?><span class="field-error"><?= e((string)$editErrors['campaign_medium']) ?></span><?php endif; ?></label><label>内容<input type="text" name="campaign_content" value="<?= e($editCampaignContent) ?>" maxlength="100"<?= isset($editErrors['campaign_content']) ? ' aria-invalid="true"' : '' ?>><?php if (isset($editErrors['campaign_content'])): ?><span class="field-error"><?= e((string)$editErrors['campaign_content']) ?></span><?php endif; ?></label></div></fieldset>
            <fieldset class="edit-access-fields"><legend>访问保护与失效处理</legend><?php if ($editPasswordResetRequired): ?><p class="field-note" role="alert">此链接从受密码保护的导出文件导入。重新设置访问密码后才能启用。</p><?php endif; ?><div class="form-row"><label>设置新访问密码<input type="password" name="access_password" maxlength="1024" autocomplete="new-password" placeholder="<?= $editPasswordResetRequired ? '必须设置新密码' : '留空则保持不变' ?>"<?= $editPasswordResetRequired ? ' required' : '' ?><?= isset($editErrors['access_password']) ? ' aria-invalid="true" aria-describedby="edit-access-password-error"' : '' ?>><?php if (isset($editErrors['access_password'])): ?><span class="field-error" id="edit-access-password-error"><?= e((string)$editErrors['access_password']) ?></span><?php endif; ?></label><label>失效后备用地址<input type="url" name="fallback_url" value="<?= e($editFallbackUrl) ?>" maxlength="<?= (int)($config['target_url_max_length'] ?? 2048) ?>"<?= isset($editErrors['fallback_url']) ? ' aria-invalid="true"' : '' ?>><?php if (isset($editErrors['fallback_url'])): ?><span class="field-error"><?= e((string)$editErrors['fallback_url']) ?></span><?php endif; ?></label></div><label>失效提示<textarea name="invalid_message" maxlength="500" rows="3"<?= isset($editErrors['invalid_message']) ? ' aria-invalid="true"' : '' ?>><?= e($editInvalidMessage) ?></textarea><?php if (isset($editErrors['invalid_message'])): ?><span class="field-error"><?= e((string)$editErrors['invalid_message']) ?></span><?php endif; ?></label><label class="check-field"><input type="checkbox" name="remove_access_password" value="1"<?= $editRemovePassword ? ' checked' : '' ?><?= $editPasswordResetRequired ? ' disabled' : '' ?>>移除现有访问密码<?= $editPasswordResetRequired ? '（须先设置新密码）' : (link_is_password_protected($editLink) ? '' : '（当前未设置）') ?></label></fieldset>
            <div class="check-stack"><label class="check-field"><input type="checkbox" name="is_one_time" value="1"<?= $editOneTime ? ' checked' : '' ?>>一次性链接</label><label class="check-field"><input type="checkbox" name="is_favorite" value="1"<?= $editFavorite ? ' checked' : '' ?>>收藏</label></div>
            <button type="submit"><svg class="icon" aria-hidden="true"><use href="#icon-save"/></svg>保存修改</button>
        </form>
    </section>
</main>
</body>
</html>
