<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <meta name="theme-color" content="#ffffff" data-theme-color>
    <meta name="description" content="链匣 LinkVault 的隐私说明与数据处理方式。">
    <title>隐私说明 - 链匣 LinkVault</title>
    <script src="<?= e(asset_path('theme-init.js')) ?>"></script>
    <link rel="icon" href="<?= e(asset_path('icon.svg')) ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(asset_path('app.css')) ?>">
    <script src="<?= e(asset_path('app.js')) ?>" defer></script>
</head>
<body class="privacy-page"><a class="skip-link" href="#main-content">跳到主要内容</a>
<header class="public-report-header"><a class="public-report-brand" href="<?= e(app_path('/')) ?>" aria-label="链匣 LinkVault 首页"><img src="<?= e(asset_path('icon.svg')) ?>" alt="" width="40" height="40"><span><strong>链匣 LinkVault</strong><small>隐私说明</small></span></a><a class="button button-secondary" href="<?= e(app_path('/')) ?>">返回首页</a></header>
<main id="main-content" class="privacy-main" tabindex="-1">
    <header class="privacy-intro"><p class="public-report-eyebrow">PRIVACY</p><h1>数据只为链接服务</h1><p>链匣将数据处理范围限制在短链接跳转、服务安全与匿名统计所必需的内容，不出售或用于广告定向。</p><p class="muted">最后更新：<?= e(gmdate('Y-m-d')) ?></p></header>
    <nav class="privacy-nav" aria-label="本页目录"><a href="#collect">收集范围</a><a href="#use">使用方式</a><a href="#retention">保留期限</a><a href="#rights">你的选择</a></nav>
    <section id="collect" class="privacy-section" aria-labelledby="collect-title"><p class="public-report-eyebrow">01 / 收集范围</p><h2 id="collect-title">仅处理必要数据</h2><div class="privacy-grid"><article><h3>短链接配置</h3><p>保存短码、目标地址、标题、标签及你选择的访问规则，用于完成跳转与管理。</p></article><article><h3>匿名访问统计</h3><p>处理访问时间、设备类别、浏览器大类、来源域名和国家/地区等聚合信息。</p></article><article><h3>安全与举报</h3><p>处理公开举报内容及当日来源哈希，用于限流、去重和安全处置。</p></article></div></section>
    <section id="use" class="privacy-section" aria-labelledby="use-title"><p class="public-report-eyebrow">02 / 使用方式</p><h2 id="use-title">用于运行、保护和改进服务</h2><ul class="privacy-list"><li>提供短链接创建、跳转、访问控制和管理功能。</li><li>以聚合数据展示访问趋势，并识别异常或自动化流量。</li><li>防范滥用、处理公开举报、维护服务可靠性与安全审计。</li></ul><aside class="privacy-callout"><strong>我们不收集</strong><span>IP 地址、UV 指纹、Cookie、Authorization 请求头、查询字符串或完整来源 URL。</span></aside></section>
    <section id="retention" class="privacy-section" aria-labelledby="retention-title"><p class="public-report-eyebrow">03 / 保留期限</p><h2 id="retention-title">按数据类型自动清理</h2><dl class="privacy-retention"><div><dt>原始匿名访问日志</dt><dd><?= $rawLogRetentionDays ?> 天</dd></div><div><dt>小时级访问聚合</dt><dd><?= $hourlyRetentionDays ?> 天</dd></div><div><dt>日级匿名访问聚合</dt><dd><?= $aggregateRetentionDays ?> 天</dd></div><div><dt>链接与管理数据</dt><dd>由管理员管理或删除</dd></div></dl><p class="muted">保留任务会定期执行；永久删除链接时，其关联的统计数据会一并删除。</p></section>
    <section id="rights" class="privacy-section" aria-labelledby="rights-title"><p class="public-report-eyebrow">04 / 你的选择</p><h2 id="rights-title">管理自己的数据</h2><p>链接管理员可在管理后台导出、更新或删除自己管理的链接。若你通过公开举报页提交了联系方式，请在后续沟通中提供查询编号，以便我们定位记录。</p><div class="privacy-actions"><a class="button" href="<?= e(app_path('/login')) ?>">进入管理后台</a><a class="button button-secondary" href="<?= e(app_path('/report')) ?>">公开举报</a></div></section>
</main>
</body>
</html>
