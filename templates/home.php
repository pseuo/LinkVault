<?php $releaseMetadata = release_metadata($config); ?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <meta name="theme-color" content="#ffffff" data-theme-color>
    <meta name="description" content="链匣 LinkVault，自主管理的短链接服务。你的链接，收放自如。">
    <title>链匣 LinkVault - 你的链接，收放自如。</title>
    <script src="<?= e(asset_path('theme-init.js')) ?>"></script>
    <link rel="icon" href="<?= e(asset_path('icon.svg')) ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(asset_path('app.css')) ?>">
    <script src="<?= e(asset_path('app.js')) ?>" defer></script>
</head>
<body class="home-page">
<svg class="icon-sprite" aria-hidden="true" xmlns="http://www.w3.org/2000/svg">
    <symbol id="icon-link" viewBox="0 0 24 24"><path d="M9 17H7A5 5 0 0 1 7 7h2"/><path d="M15 7h2a5 5 0 1 1 0 10h-2"/><path d="M8 12h8"/></symbol>
    <symbol id="icon-moon" viewBox="0 0 24 24"><path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/></symbol>
    <symbol id="icon-sun" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.42 1.42M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.42"/></symbol>
    <symbol id="icon-lock" viewBox="0 0 24 24"><rect width="18" height="11" x="3" y="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4M12 15v2"/></symbol>
    <symbol id="icon-arrow-right" viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6"/></symbol>
    <symbol id="icon-shield" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/><path d="m9 12 2 2 4-4"/></symbol>
    <symbol id="icon-chart" viewBox="0 0 24 24"><path d="M3 3v18h18M7 16v-3M12 16V8M17 16v-5"/></symbol>
    <symbol id="icon-database" viewBox="0 0 24 24"><ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v6c0 1.7 3.6 3 8 3s8-1.3 8-3V5M4 11v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"/></symbol>
    <symbol id="icon-check-circle" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="m8 12 2.6 2.6L16.5 9"/></symbol>
    <symbol id="icon-alert-circle" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16.5v.01"/></symbol>
    <symbol id="icon-plus" viewBox="0 0 24 24"><path d="M5 12h14M12 5v14"/></symbol>
    <symbol id="icon-search" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></symbol>
    <symbol id="icon-star" viewBox="0 0 24 24"><path d="m12 2.8 2.8 5.7 6.3.9-4.5 4.4 1.1 6.2-5.7-3-5.7 3 1.1-6.2-4.5-4.4 6.3-.9Z"/></symbol>
</svg>
<a class="skip-link" href="#main-content">跳到主要内容</a>
<header class="site-header home-header">
    <div class="header-inner">
        <a class="brand home-brand" href="<?= e(app_path('/')) ?>" aria-label="链匣 LinkVault 首页">
            <img class="home-brand-icon" src="<?= e(asset_path('icon.svg')) ?>" alt="">
            <div><strong>链匣 LinkVault</strong><span>你的链接，收放自如。</span></div>
        </a>
        <div class="header-actions">
            <button class="button-secondary theme-toggle" type="button" data-theme-toggle title="切换深色模式" aria-label="切换深色模式" aria-pressed="false"><svg class="icon moon-icon" aria-hidden="true"><use href="#icon-moon"/></svg><svg class="icon sun-icon" aria-hidden="true"><use href="#icon-sun"/></svg></button>
            <a class="button button-secondary home-header-link" href="<?= e(app_path('/report')) ?>" aria-label="公开举报"><svg class="icon" aria-hidden="true"><use href="#icon-alert-circle"/></svg><span>公开举报</span></a>
            <a class="button button-secondary home-header-link" href="<?= e(app_path('/login')) ?>" aria-label="管理后台"><svg class="icon" aria-hidden="true"><use href="#icon-lock"/></svg><span>管理后台</span></a>
        </div>
    </div>
</header>
<main id="main-content" tabindex="-1">
    <?php if (is_array($flash)): ?>
        <div class="home-flash-wrap"><div class="flash <?= e((string)$flash['type']) ?>" role="<?= $flash['type'] === 'error' ? 'alert' : 'status' ?>"><span class="flash-icon" aria-hidden="true"><svg class="icon"><use href="#icon-<?= $flash['type'] === 'error' ? 'alert' : 'check' ?>-circle"/></svg></span><div class="flash-content"><?= e((string)$flash['message']) ?></div></div></div>
    <?php endif; ?>
    <section class="home-hero" aria-labelledby="home-title">
        <div class="home-hero-inner">
            <div class="home-hero-copy">
                <div class="home-kicker"><span></span>自托管链接工作台</div>
                <img class="home-hero-mark" src="<?= e(asset_path('icon.svg')) ?>" alt="链匣 LinkVault 标志" width="88" height="88">
                <h1 id="home-title">链匣 LinkVault</h1>
                <p>让每一条链接都有清楚的去向、边界与记录。创建、整理、分析和维护，都在你自己的服务里完成。</p>
                <div class="home-hero-actions">
                    <a class="button home-primary-action" href="<?= e(app_path('/login')) ?>">进入管理后台<svg class="icon" aria-hidden="true"><use href="#icon-arrow-right"/></svg></a>
                    <a class="button home-secondary-action" href="#capabilities">了解核心能力</a>
                </div>
                <div class="home-trust-row" aria-label="服务特性">
                    <span><svg class="icon" aria-hidden="true"><use href="#icon-database"/></svg>数据自持</span>
                    <span><svg class="icon" aria-hidden="true"><use href="#icon-shield"/></svg>访问可控</span>
                    <span><svg class="icon" aria-hidden="true"><use href="#icon-chart"/></svg>状态可查</span>
                </div>
            </div>
            <div class="home-product-preview" aria-hidden="true">
                <div class="home-preview-heading"><span><i aria-hidden="true"><b></b><b></b><b></b></i>链接工作台</span><span class="home-preview-status">服务正常</span></div>
                <div class="home-preview-workspace">
                    <aside class="home-preview-nav" aria-hidden="true">
                        <strong>工作区</strong>
                        <span class="selected"><svg class="icon"><use href="#icon-link"/></svg>链接</span>
                        <span><svg class="icon"><use href="#icon-chart"/></svg>访问分析</span>
                        <span><svg class="icon"><use href="#icon-shield"/></svg>维护</span>
                    </aside>
                    <div class="home-preview-main">
                        <div class="home-preview-toolbar">
                            <div><span>链接</span><strong>全部链接 <b>128</b></strong></div>
                            <span class="home-preview-create"><svg class="icon"><use href="#icon-plus"/></svg>创建短链</span>
                        </div>
                        <div class="home-preview-search"><svg class="icon" aria-hidden="true"><use href="#icon-search"/></svg><span>搜索标题、短码或目标域名</span></div>
                        <div class="home-preview-list">
                            <div class="home-preview-row featured"><svg class="icon home-preview-star" aria-hidden="true"><use href="#icon-star"/></svg><span><strong>季度报告</strong><code><?= e(preg_replace('#^https?://#', '', base_url($config)) ?: 'your.domain') ?>/report</code></span><small>启用中</small><b>2,481<em> 次</em></b></div>
                            <div class="home-preview-row"><span class="home-preview-row-icon"><svg class="icon" aria-hidden="true"><use href="#icon-link"/></svg></span><span><strong>产品文档</strong><code><?= e(preg_replace('#^https?://#', '', base_url($config)) ?: 'your.domain') ?>/docs</code></span><small>启用中</small><b>936<em> 次</em></b></div>
                            <div class="home-preview-row"><span class="home-preview-row-icon"><svg class="icon" aria-hidden="true"><use href="#icon-link"/></svg></span><span><strong>发布公告</strong><code><?= e(preg_replace('#^https?://#', '', base_url($config)) ?: 'your.domain') ?>/release</code></span><small class="scheduled">待启用</small><b>0<em> 次</em></b></div>
                        </div>
                    </div>
                </div>
                <div class="home-preview-capabilities" aria-label="链接管理能力">
                    <span><strong>128</strong> 链接</span>
                    <span><strong>99.9%</strong> 可用</span>
                    <span><strong>3,417</strong> 近 14 日跳转</span>
                </div>
            </div>
        </div>
    </section>

    <section class="home-capabilities" id="capabilities" aria-labelledby="capabilities-title">
        <div class="home-section-inner">
            <div class="home-section-heading"><span>核心能力</span><h2 id="capabilities-title">不只缩短地址，更管理链接的完整生命周期</h2><p>从第一次创建到最后一次访问，关键规则、变化和结果都有迹可循。</p></div>
            <div class="home-feature-grid">
                <article><span class="home-feature-index">01</span><span class="home-feature-icon home-feature-icon-green"><svg class="icon" aria-hidden="true"><use href="#icon-shield"/></svg></span><h3>精细访问规则</h3><p>有效期、定时启用、点击上限、密码保护与一次性访问，按链接独立设置。</p></article>
                <article><span class="home-feature-index">02</span><span class="home-feature-icon home-feature-icon-blue"><svg class="icon" aria-hidden="true"><use href="#icon-chart"/></svg></span><h3>可读的访问数据</h3><p>趋势、来源、设备与活动归因集中呈现，快速识别真正有效的链接。</p></article>
                <article><span class="home-feature-index">03</span><span class="home-feature-icon home-feature-icon-coral"><svg class="icon" aria-hidden="true"><use href="#icon-database"/></svg></span><h3>掌握自己的数据</h3><p>导入导出、操作审计、健康检查与可恢复备份都运行在自己的环境中。</p></article>
            </div>
        </div>
    </section>

    <section class="home-principles" aria-labelledby="principles-title">
        <div class="home-section-inner home-principles-layout">
            <div class="home-section-heading"><span>工作方式</span><h2 id="principles-title">从创建到维护，都在一处完成</h2></div>
            <ol class="home-principle-list">
                <li><svg class="icon" aria-hidden="true"><use href="#icon-check-circle"/></svg><div><strong>快速创建</strong><span>随机短码或自定义短码，按需补充标题与标签。</span></div></li>
                <li><svg class="icon" aria-hidden="true"><use href="#icon-check-circle"/></svg><div><strong>集中整理</strong><span>搜索、筛选、收藏与批量操作保持链接有序。</span></div></li>
                <li><svg class="icon" aria-hidden="true"><use href="#icon-check-circle"/></svg><div><strong>持续维护</strong><span>过期提醒、回收站、审计记录与健康检查随时可查。</span></div></li>
            </ol>
        </div>
    </section>

    <section class="home-final-cta" aria-labelledby="home-cta-title">
        <div class="home-section-inner">
            <div><span>LINKVAULT WORKSPACE</span><h2 id="home-cta-title">回到你的链接工作台</h2><p>管理入口与公开短链分离，日常操作保持清楚、专注。</p></div>
            <a class="button" href="<?= e(app_path('/login')) ?>">管理员登录<svg class="icon" aria-hidden="true"><use href="#icon-arrow-right"/></svg></a>
        </div>
    </section>
</main>
<footer class="home-footer"><div class="home-section-inner"><span>链匣 LinkVault · v<?= e((string)$releaseMetadata['version']) ?> · Schema v<?= (int)$releaseMetadata['schema_version'] ?></span><span class="home-footer-links"><a href="<?= e(app_path('/privacy')) ?>">隐私说明</a><a href="<?= e(app_path('/login')) ?>">管理员登录<svg class="icon" aria-hidden="true"><use href="#icon-arrow-right"/></svg></a></span></div></footer>
</body>
</html>
