<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <meta name="theme-color" content="#ffffff" data-theme-color>
    <meta name="description" content="举报疑似钓鱼、恶意软件、垃圾信息或欺诈短链接。">
    <title>举报恶意链接 - 链匣 LinkVault</title>
    <script src="<?= e(asset_path('theme-init.js')) ?>"></script>
    <link rel="icon" href="<?= e(asset_path('icon.svg')) ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(asset_path('app.css')) ?>">
    <script src="<?= e(asset_path('app.js')) ?>" defer></script>
</head>
<body class="public-report-page"><a class="skip-link" href="#main-content">跳到主要内容</a>
<header class="public-report-header"><a class="public-report-brand" href="<?= e(app_path('/')) ?>" aria-label="链匣 LinkVault 首页"><img src="<?= e(asset_path('icon.svg')) ?>" alt="" width="40" height="40"><span><strong>链匣 LinkVault</strong><small>安全举报中心</small></span></a><nav class="public-page-actions" aria-label="举报页面导航"><a href="<?= e(app_path('/privacy')) ?>">隐私说明</a><a class="button button-secondary" href="<?= e(app_path('/')) ?>">返回首页</a></nav></header>
<main id="main-content" class="public-report-main" tabindex="-1">
    <section class="public-report-intro" aria-labelledby="report-title"><p class="public-report-eyebrow">SECURITY REPORT</p><h1 id="report-title">举报可疑链接</h1><p>帮助我们处理钓鱼、恶意软件、垃圾信息与欺诈链接。请仅提交你认为存在风险的地址。</p><ul class="public-report-guidance"><li>举报会进入管理员复核队列</li><li>请勿在说明中提交密码、验证码或其他敏感信息</li><li>提交后会显示查询编号，便于后续追踪</li></ul></section>
    <section class="panel public-report-panel" aria-labelledby="report-form-title">
        <?php if (is_array($result)): ?>
            <div class="public-report-result" role="status"><span class="public-report-result-icon" aria-hidden="true">✓</span><div><p class="public-report-eyebrow">已提交</p><h2 id="report-form-title">举报已受理</h2><p>查询编号：<code><?= e((string)$result['public_id']) ?></code></p><p class="muted">管理员会按风险等级复核。若需要补充信息，请保留此编号。</p></div></div><a class="button" href="<?= e(app_path('/')) ?>">返回首页</a>
        <?php else: ?>
            <div class="public-report-form-heading"><p class="public-report-eyebrow">填写举报</p><h2 id="report-form-title">提供可核验的信息</h2><p class="muted">带 * 的字段为必填项。我们只将联系方式用于必要的补充沟通。</p></div>
            <?php if (is_string($error)): ?><div class="flash error" role="alert" tabindex="-1"><?= e($error) ?></div><?php endif; ?>
            <form class="form-grid public-report-form" method="post" action="<?= e(app_path('/report')) ?>" data-preserve-draft="public-report"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><label class="field-wide" for="report-url">待举报链接 <span aria-hidden="true">*</span><input id="report-url" type="url" name="url" maxlength="2048" placeholder="https://example.com/suspicious-page" inputmode="url" autocomplete="url" required aria-describedby="report-url-help"><span class="field-note" id="report-url-help">请粘贴完整网址，包括 https://。</span></label><label for="report-reason">举报类型 <span aria-hidden="true">*</span><select id="report-reason" name="reason" required><option value="phishing">钓鱼</option><option value="malware">恶意软件</option><option value="spam">垃圾信息</option><option value="fraud">欺诈</option><option value="other">其他</option></select></label><label for="report-contact">联系方式，可选<input id="report-contact" type="text" name="contact" maxlength="254" placeholder="邮箱或其他安全联系方式" autocomplete="email"></label><label class="field-wide" for="report-details">补充说明，可选<textarea id="report-details" name="details" maxlength="1000" rows="5" placeholder="例如：链接伪装成什么内容、你观察到的异常行为。"></textarea><span class="field-note">请勿填写密码、验证码、银行卡号或其他敏感信息。</span></label><label class="honeypot" aria-hidden="true">网站<input name="website" tabindex="-1" autocomplete="off"></label><div class="public-report-actions"><button type="submit">提交举报</button><span class="muted">提交即表示信息真实、完整。</span></div></form>
        <?php endif; ?>
    </section>
</main></body></html>
