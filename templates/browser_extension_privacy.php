<?php

$language = ($_GET['lang'] ?? '') === 'zh' ? 'zh' : 'en';
$copy = $language === 'zh' ? [
    'meta' => 'Save to LinkVault 浏览器扩展的隐私政策。',
    'page_title' => '浏览器扩展隐私政策 - LinkVault',
    'skip' => '跳到主要内容',
    'home_label' => 'LinkVault 首页',
    'extension_privacy' => '浏览器扩展隐私',
    'back_home' => '返回首页',
    'switch_language' => 'English',
    'eyebrow' => '扩展隐私政策',
    'title' => '你的链接始终由你掌控',
    'intro' => '本政策仅适用于 Save to LinkVault 浏览器扩展，说明扩展会处理哪些数据、何时处理、存储位置及删除方式。',
    'updated' => '最后更新：',
    'nav' => ['处理的数据', '用途与共享', '存储与删除', '权限', '联系我们'],
    'sections' => [
        ['01 / 处理的数据', '仅处理你所请求操作需要的数据'],
        ['02 / 用途与共享', '不出售、不投放广告，也不跟踪'],
        ['03 / 存储与删除', '本地保存，直到你修改或移除'],
        ['04 / 权限', '扩展为什么需要这些权限'],
        ['05 / 联系我们', '问题或请求'],
    ],
    'data' => [
        ['网页与链接', '当你通过弹窗、快捷键或右键菜单选择保存时，扩展会处理当前网页的 URL 和标签页标题。选中文本的操作只会处理你选中内容中提取出的 HTTP(S) URL。'],
        ['LinkVault 设置', '你配置的 LinkVault 服务地址、Bearer Token、默认标签、自动标签设置和自定义标签规则保存在浏览器配置文件本地。'],
        ['链接数据', '扩展会处理你填写的字段：标题、URL、标签、自定义短码、日期、点击上限、一次性模式、收藏状态和活动字段。搜索会处理你输入的查询词和返回的匹配链接。'],
        ['离线队列', '当保存操作无法连接 LinkVault 或收到可重试的服务错误时，提交的链接数据和创建字段会暂存在本地队列中，最多 100 条，直到重试成功或你移除扩展。'],
    ],
    'purpose' => [
        '扩展仅在你触发保存操作后，将链接创建数据发送到你配置的 LinkVault 服务地址。',
        '扩展仅向该 LinkVault 服务发送 Bearer Token，并通过 Authorization 请求头进行身份验证。',
        '搜索或查看已保存链接的状态时，扩展只会将查询词或链接 ID 发送到该配置的服务。',
        '扩展不使用分析 SDK、广告、联盟跟踪、远程代码或第三方数据接收方，也不会出于无关目的出售或转移你的数据。',
        '除非你明确在右键保存操作中选中 URL，否则扩展不会读取网页内容、浏览历史、密码、支付信息、按键记录或页面数据。',
    ],
    'storage' => [
        '扩展设置（包括 Bearer Token）和队列项目保存在浏览器配置文件中扩展的本地存储内。扩展不会同步这些数据，也不会在 LinkVault 中建立单独的扩展数据库。',
        '你可以移除扩展，或通过浏览器的扩展管理界面清除其存储，来删除本地设置和队列项目。在 LinkVault 中删除或轮换 Token 后，该 Token 将无法授权后续请求。成功发送到你配置的 LinkVault 服务的数据受该服务隐私政策约束，并可在服务中管理。',
    ],
    'important' => ['重要安全说明', '扩展本地存储不是加密的密钥存储。请使用权限范围有限、有效期较短的 LinkVault Token，并保护好浏览器配置文件和操作系统账户。'],
    'permissions' => [
        ['activeTab', '仅在你打开扩展或使用扩展命令后读取当前标签页的 URL 和标题。'],
        ['contextMenus', '提供网页、链接或选中 URL 的右键保存操作。'],
        ['storage', '保存你的设置和本地离线队列。'],
        ['alarms', '每五分钟重试队列中的保存操作。'],
        ['可选主机访问权限', '只会为你配置的 LinkVault 服务来源请求，以便扩展调用该服务的 API。'],
    ],
    'contact' => [
        '如对本浏览器扩展隐私政策有疑问，或希望提出隐私请求，请联系 ',
        '如对本浏览器扩展隐私政策有疑问，或希望提出隐私请求，请联系你所配置服务地址的 LinkVault 管理员。',
        'LinkVault 服务隐私说明',
    ],
] : [
    'meta' => 'Privacy policy for the Save to LinkVault browser extension.',
    'page_title' => 'Browser Extension Privacy Policy - LinkVault',
    'skip' => 'Skip to main content',
    'home_label' => 'LinkVault home',
    'extension_privacy' => 'Browser extension privacy',
    'back_home' => 'Back to home',
    'switch_language' => '中文',
    'eyebrow' => 'EXTENSION PRIVACY POLICY',
    'title' => 'Your links stay under your control',
    'intro' => 'This policy applies only to the Save to LinkVault browser extension. It explains what the extension processes, when it does so, where it is stored, and how to delete it.',
    'updated' => 'Last updated:',
    'nav' => ['Data processed', 'Purpose and sharing', 'Storage and deletion', 'Permissions', 'Contact'],
    'sections' => [
        ['01 / DATA PROCESSED', 'Only data needed for your requested action'],
        ['02 / PURPOSE AND SHARING', 'No sale, advertising, or tracking'],
        ['03 / STORAGE AND DELETION', 'Local until you change or remove it'],
        ['04 / PERMISSIONS', 'Why the extension requests them'],
        ['05 / CONTACT', 'Questions or requests'],
    ],
    'data' => [
        ['Pages and links', 'When you choose Save from the popup, shortcut, or context menu, the extension processes the URL and current tab title. A selected-text action processes only an HTTP(S) URL extracted from the text you select.'],
        ['Your LinkVault settings', 'The configured LinkVault service URL, Bearer token, default tags, automatic-tag settings, and custom tag rules are stored locally in the browser profile.'],
        ['Link data', 'The extension processes the fields you enter: title, URL, tags, custom code, dates, click limit, one-time mode, favorite status, and campaign fields. Search processes the query you type and returned matching links.'],
        ['Offline queue', 'If a save cannot reach LinkVault or receives a retryable service error, the submitted link data and creation fields are stored locally in a queue of up to 100 items until a retry succeeds or you remove the extension.'],
    ],
    'purpose' => [
        'The extension sends link creation data to only the LinkVault service URL that you configure, and only after you invoke a save action.',
        'It sends your Bearer token in an Authorization request header solely to authenticate with that configured LinkVault service.',
        'When you search or view a saved link\'s status, it sends the search query or link ID only to that configured service.',
        'It does not use analytics SDKs, advertising, affiliate tracking, remote code, or third-party data recipients. It does not sell or transfer your data for unrelated purposes.',
        'It does not read page contents, browsing history, passwords, payment information, keystrokes, or data from pages unless you explicitly select a URL for the context-menu save action.',
    ],
    'storage' => [
        'Extension settings, including the Bearer token, and queued items are stored in the extension\'s local storage within your browser profile. They are not synced by this extension and are not stored in a separate LinkVault extension database.',
        'You can delete locally stored settings and queued items by removing the extension or clearing its storage through your browser\'s extension controls. Deleting or rotating a token at LinkVault prevents it from authorizing future requests. Data successfully sent to your configured LinkVault service is governed by that service\'s privacy policy and can be managed there.',
    ],
    'important' => ['Important security note', 'Extension local storage is not an encrypted secret store. Use a narrowly scoped, short-lived LinkVault token and protect your browser profile and operating system account.'],
    'permissions' => [
        ['activeTab', 'Reads the current tab\'s URL and title only after you open the extension or use its command.'],
        ['contextMenus', 'Provides explicit right-click save actions for a page, link, or selected URL.'],
        ['storage', 'Stores your settings and the local offline queue.'],
        ['alarms', 'Retries queued saves every five minutes.'],
        ['Optional host access', 'Requested only for the LinkVault service origin you configure, so the extension can call that service\'s API.'],
    ],
    'contact' => [
        'For questions about this browser extension privacy policy or to make a privacy request, contact ',
        'For questions about this browser extension privacy policy or to make a privacy request, contact the LinkVault service administrator for the service URL you configured.',
        'LinkVault service privacy',
    ],
];
$alternateLanguage = $language === 'zh' ? 'en' : 'zh';
$languageUrl = app_path('/browser-extension-privacy?lang=' . $alternateLanguage);
?>
<!doctype html>
<html lang="<?= $language === 'zh' ? 'zh-CN' : 'en' ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <meta name="theme-color" content="#ffffff" data-theme-color>
    <meta name="description" content="<?= e($copy['meta']) ?>">
    <title><?= e($copy['page_title']) ?></title>
    <script src="<?= e(asset_path('theme-init.js')) ?>"></script>
    <link rel="icon" href="<?= e(asset_path('icon.svg')) ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(asset_path('app.css')) ?>">
    <script src="<?= e(asset_path('app.js')) ?>" defer></script>
</head>
<body class="privacy-page"><a class="skip-link" href="#main-content"><?= e($copy['skip']) ?></a>
<header class="public-report-header"><a class="public-report-brand" href="<?= e(app_path('/')) ?>" aria-label="<?= e($copy['home_label']) ?>"><img src="<?= e(asset_path('icon.svg')) ?>" alt="" width="40" height="40"><span><strong>LinkVault</strong><small><?= e($copy['extension_privacy']) ?></small></span></a><div class="public-page-actions"><a href="<?= e($languageUrl) ?>" lang="<?= e($alternateLanguage) ?>"><?= e($copy['switch_language']) ?></a><a class="button button-secondary" href="<?= e(app_path('/')) ?>"><?= e($copy['back_home']) ?></a></div></header>
<main id="main-content" class="privacy-main" tabindex="-1">
    <header class="privacy-intro"><p class="public-report-eyebrow"><?= e($copy['eyebrow']) ?></p><h1><?= e($copy['title']) ?></h1><p><?= e($copy['intro']) ?></p><p class="muted"><?= e($copy['updated']) ?> <?= e(gmdate('Y-m-d')) ?></p></header>
    <nav class="privacy-nav" aria-label="<?= e($copy['eyebrow']) ?>"><a href="#data"><?= e($copy['nav'][0]) ?></a><a href="#purpose"><?= e($copy['nav'][1]) ?></a><a href="#storage"><?= e($copy['nav'][2]) ?></a><a href="#permissions"><?= e($copy['nav'][3]) ?></a><a href="#contact"><?= e($copy['nav'][4]) ?></a></nav>

    <section id="data" class="privacy-section" aria-labelledby="data-title"><p class="public-report-eyebrow"><?= e($copy['sections'][0][0]) ?></p><h2 id="data-title"><?= e($copy['sections'][0][1]) ?></h2><div class="privacy-grid"><?php foreach ($copy['data'] as [$title, $description]): ?><article><h3><?= e($title) ?></h3><p><?= e($description) ?></p></article><?php endforeach; ?></div></section>
    <section id="purpose" class="privacy-section" aria-labelledby="purpose-title"><p class="public-report-eyebrow"><?= e($copy['sections'][1][0]) ?></p><h2 id="purpose-title"><?= e($copy['sections'][1][1]) ?></h2><ul class="privacy-list"><?php foreach ($copy['purpose'] as $item): ?><li><?= e($item) ?></li><?php endforeach; ?></ul></section>
    <section id="storage" class="privacy-section" aria-labelledby="storage-title"><p class="public-report-eyebrow"><?= e($copy['sections'][2][0]) ?></p><h2 id="storage-title"><?= e($copy['sections'][2][1]) ?></h2><p><?= e($copy['storage'][0]) ?></p><p><?= e($copy['storage'][1]) ?></p><aside class="privacy-callout"><strong><?= e($copy['important'][0]) ?></strong><span><?= e($copy['important'][1]) ?></span></aside></section>
    <section id="permissions" class="privacy-section" aria-labelledby="permissions-title"><p class="public-report-eyebrow"><?= e($copy['sections'][3][0]) ?></p><h2 id="permissions-title"><?= e($copy['sections'][3][1]) ?></h2><dl class="privacy-retention privacy-permission-list"><?php foreach ($copy['permissions'] as [$permission, $description]): ?><div><dt><code><?= e($permission) ?></code></dt><dd><?= e($description) ?></dd></div><?php endforeach; ?></dl></section>
    <section id="contact" class="privacy-section" aria-labelledby="contact-title"><p class="public-report-eyebrow"><?= e($copy['sections'][4][0]) ?></p><h2 id="contact-title"><?= e($copy['sections'][4][1]) ?></h2><?php if ($contact !== ''): ?><p><?= e($copy['contact'][0]) ?><a href="mailto:<?= e($contact) ?>"><?= e($contact) ?></a>.</p><?php else: ?><p><?= e($copy['contact'][1]) ?></p><?php endif; ?><div class="privacy-actions"><a class="button button-secondary" href="<?= e(app_path('/privacy')) ?>"><?= e($copy['contact'][2]) ?></a></div></section>
</main>
</body>
</html>
