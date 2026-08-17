<?php

declare(strict_types=1);

$config = require dirname(__DIR__) . '/config.php';
require dirname(__DIR__) . '/app/bootstrap.php';
require dirname(__DIR__) . '/app/Container.php';
require dirname(__DIR__) . '/app/Router.php';

ob_start();
$requestId = bin2hex(random_bytes(8));
send_security_headers($config, $requestId);
header('Cache-Control: no-store');

set_exception_handler(static function (Throwable $exception) use ($config): never {
    $databaseUnavailable = is_database_unavailable($exception);
    log_event($config, 'unhandled_exception', [
        'type' => get_class($exception),
        'database_unavailable' => $databaseUnavailable,
        'error' => limit_text($exception->getMessage(), 300),
        'file' => basename($exception->getFile()),
        'line' => $exception->getLine(),
    ]);
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if ($databaseUnavailable) {
        header('Retry-After: 5');
        render_error_page(503, '服务暂时不可用', '数据库当前不可用，请稍后重试。');
    }
    render_error_page(500, '服务暂时不可用', '请求处理失败，请稍后重试。');
});

register_shutdown_function(static function () use ($config): void {
    $error = error_get_last();
    if (!$error || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }
    log_event($config, 'fatal_error', [
        'error' => limit_text((string)$error['message'], 300),
        'file' => basename((string)$error['file']),
        'line' => (int)$error['line'],
    ]);
    if (!headers_sent()) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        render_error_page(500, '服务暂时不可用', '请求处理失败，请稍后重试。');
    }
});

enforce_request_host($config);
$isCustomDomain = current_short_domain() !== null;
$method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
$path = request_path();
$router = require __DIR__ . '/routes.php';
$route = $router->match($method, $path);

if ($route->group === 'operations') {
    require_once dirname(__DIR__) . '/app/SystemStatus.php';
    require_once dirname(__DIR__) . '/app/PrometheusMetrics.php';
    require __DIR__ . '/controllers/OperationsController.php';
    OperationsController::dispatch($method, $path, $config);
}

$confirmationSlug = $route->group === 'public-confirm' ? ($route->parameters['slug'] ?? null) : null;
$isPublicConfirmation = is_string($confirmationSlug) && valid_slug($confirmationSlug);
$unlockSlug = $route->group === 'public-unlock' ? ($route->parameters['slug'] ?? null) : null;
$isPublicUnlock = is_string($unlockSlug) && valid_slug($unlockSlug);

if ($route->hasMiddleware('secure')) {
    require_secure_configuration($config);
}

$isApiRequest = $route->group === 'api';
$isPublicRedirect = $route->group === 'public-redirect';
if ($route->hasMiddleware('database') && !is_file((string)$config['database_path'])) {
    log_event($config, 'database_migration_required', ['reason' => 'database_missing']);
    header('Retry-After: 5');
    render_error_page(503, '数据库尚未初始化', '请先由管理员执行数据库迁移。');
}

if ($isPublicRedirect) {
    require dirname(__DIR__) . '/app/LinkService.php';
    require __DIR__ . '/controllers/PublicRedirectController.php';
    try {
        $pdo = database($config, max(1, (int)($config['redirect_busy_timeout_ms'] ?? 250)));
    } catch (DatabaseMigrationRequired $exception) {
        log_event($config, 'database_migration_required', ['error' => limit_text($exception->getMessage(), 300)]);
        render_error_page(503, '数据库需要迁移', '请先由管理员执行数据库迁移。');
    }
    if ($isCustomDomain && $path === '/') {
        $brand = current_short_domain();
        require dirname(__DIR__) . '/templates/domain_home.php';
        exit;
    }
    $container = new Container();
    $container->set('link', static fn (): LinkService => new LinkService(
        $pdo,
        max(1, (int)($config['target_url_max_length'] ?? 2048)),
        max(1, (int)($config['import_batch_size'] ?? 100)),
        max(1, (int)($config['import_max_records'] ?? 5000)),
        $config
    ));
    $service = $container->get('link');
    if (!$service instanceof LinkService) {
        throw new RuntimeException('Invalid link service.');
    }
    PublicRedirectController::dispatch($method, $path, $config, $service);
}

require dirname(__DIR__) . '/app/LinkService.php';
require dirname(__DIR__) . '/app/AnalyticsReportService.php';
require dirname(__DIR__) . '/app/AnalyticsExportJobService.php';
require dirname(__DIR__) . '/app/ApiTokenService.php';
require dirname(__DIR__) . '/app/AdminSecurityService.php';
require_once dirname(__DIR__) . '/app/SystemStatus.php';
require dirname(__DIR__) . '/app/ShortDomainService.php';
require dirname(__DIR__) . '/app/P2Service.php';
require dirname(__DIR__) . '/app/AdminNotificationService.php';
require_once dirname(__DIR__) . '/app/TargetHealthService.php';

require __DIR__ . '/controllers/ApiController.php';
require __DIR__ . '/controllers/AuthenticationController.php';
require __DIR__ . '/controllers/LinkController.php';
require __DIR__ . '/controllers/ImportExportController.php';
require __DIR__ . '/controllers/AnalyticsController.php';
require __DIR__ . '/controllers/DomainController.php';
require __DIR__ . '/controllers/MaintenanceController.php';
require __DIR__ . '/controllers/WorkflowController.php';
require __DIR__ . '/controllers/PublicReportController.php';
require __DIR__ . '/controllers/PrivacyController.php';
require __DIR__ . '/controllers/BrowserExtensionPrivacyController.php';

if ($route->hasMiddleware('session')) {
    configure_session($config);
    if (!session_start()) {
        throw new RuntimeException('Cannot start a secure session.');
    }
    if (!$isPublicConfirmation && !$isPublicUnlock) {
        enforce_session_timeouts($config, true);
    }
    header('Cache-Control: no-store, private');
    if ($route->hasMiddleware('admin-auth')) {
        require_login();
    }
}

try {
    $busyTimeoutMs = $isPublicConfirmation || $isPublicUnlock
        ? max(1, (int)($config['redirect_busy_timeout_ms'] ?? 250))
        : max(1, (int)($config['sqlite_busy_timeout_ms'] ?? 5000));
    $pdo = database($config, $busyTimeoutMs);
} catch (DatabaseMigrationRequired $exception) {
    log_event($config, 'database_migration_required', ['error' => limit_text($exception->getMessage(), 300)]);
    render_error_page(503, '数据库需要迁移', '请先由管理员执行数据库迁移。');
}

$container = new Container();
$container->value('pdo', $pdo);
$container->set('link', static fn (): LinkService => new LinkService(
    $pdo,
    max(1, (int)($config['target_url_max_length'] ?? 2048)),
    max(1, (int)($config['import_batch_size'] ?? 100)),
    max(1, (int)($config['import_max_records'] ?? 5000)),
    $config
));
$container->set('analytics-report', static fn (): AnalyticsReportService => new AnalyticsReportService($pdo, $config));
$container->set('api-token', static fn (): ApiTokenService => new ApiTokenService($pdo));
$container->set('short-domain', static fn (): ShortDomainService => new ShortDomainService($pdo, $config));
$service = $container->get('link');
$analyticsReportService = $container->get('analytics-report');
$apiTokenService = $container->get('api-token');
$shortDomainService = $container->get('short-domain');
if (!$service instanceof LinkService || !$analyticsReportService instanceof AnalyticsReportService
    || !$apiTokenService instanceof ApiTokenService || !$shortDomainService instanceof ShortDomainService) {
    throw new RuntimeException('Invalid application service registration.');
}
$p2Service = new P2Service($pdo, $config, $service);
if ($route->group === 'public-report') {
    PublicReportController::dispatch($method, $pdo, $config, $p2Service);
}
if ($route->group === 'public-privacy') {
    PrivacyController::dispatch($config);
}
if ($route->group === 'browser-extension-privacy') {
    BrowserExtensionPrivacyController::dispatch($config);
}
$container->set(
    'analytics-export',
    static fn (): AnalyticsExportJobService => new AnalyticsExportJobService($pdo, $config, $analyticsReportService)
);
$analyticsExportJobService = $container->get('analytics-export');
if (!$analyticsExportJobService instanceof AnalyticsExportJobService) {
    throw new RuntimeException('Invalid analytics export service.');
}
$adminSecurityService = null;
$totpEnabled = false;
if (!$isApiRequest && !$isPublicConfirmation && !$isPublicUnlock) {
    $adminSecurityService = new AdminSecurityService($pdo, $config);
    $totpEnabled = $adminSecurityService->isEnabled();
}


ApiController::dispatch(
    $method,
    $path,
    $isApiRequest,
    $pdo,
    $config,
    $service,
    $apiTokenService,
    $requestId
);
DomainController::dispatch($method, $path, $pdo, $config, $shortDomainService, $service);
AuthenticationController::dispatch(
    $method,
    $path,
    $isPublicUnlock,
    $unlockSlug,
    $pdo,
    $config,
    $service,
    $adminSecurityService,
    $totpEnabled,
    $apiTokenService
);
LinkController::dispatch(
    $method,
    $path,
    $pdo,
    $config,
    $service,
    $shortDomainService,
    $isPublicConfirmation,
    $confirmationSlug
);
ImportExportController::dispatch($method, $path, $pdo, $config, $service);
AnalyticsController::dispatch(
    $method,
    $path,
    $pdo,
    $config,
    $analyticsReportService,
    $analyticsExportJobService
);
MaintenanceController::dispatch($method, $path, $pdo, $config, $service);
WorkflowController::dispatch($method, $path, $pdo, $config, $service);

if ($method !== 'GET' || $path !== '/') {
    method_not_allowed(['GET']);
}

$pageSize = 100;
$sectionValue = (string)($_GET['section'] ?? 'links');
$section = in_array($sectionValue, ['links', 'analytics', 'marketing', 'workflows', 'trust', 'maintenance', 'webhooks', 'audit', 'notifications', 'status', 'security', 'domains', 'api'], true) ? $sectionValue : 'links';
$maintenanceValue = (string)($_GET['maintenance'] ?? 'expiring');
$maintenanceCategory = in_array($maintenanceValue, ['expiring', 'stale_zero', 'quota', 'invalid', 'target_health'], true)
    ? $maintenanceValue : 'expiring';
$search = limit_text(trim((string)($_GET['q'] ?? '')), 200);
$view = (string)($_GET['view'] ?? '') === 'trash' ? 'trash' : 'active';
$page = max(1, (int)($_GET['page'] ?? 1));
$status = in_array((string)($_GET['status'] ?? 'all'), ['all', 'active', 'inactive', 'scheduled', 'expired', 'exhausted'], true)
    ? (string)($_GET['status'] ?? 'all') : 'all';
$sort = in_array((string)($_GET['sort'] ?? 'created_desc'), [
    'created_desc', 'created_asc', 'clicks_desc', 'clicks_asc', 'last_accessed_desc', 'title_asc',
], true) ? (string)($_GET['sort'] ?? 'created_desc') : 'created_desc';
$tag = limit_text(trim((string)($_GET['tag'] ?? '')), 24);
$favoritesOnly = (string)($_GET['favorite'] ?? '') === '1';
if ($view === 'trash') {
    $status = 'all';
    $favoritesOnly = false;
}
$links = [];
$totalLinks = 0;
$dailyStats = [];
$allTags = [];
$maintenanceCounts = ['expiring' => 0, 'stale_zero' => 0, 'quota' => 0, 'invalid' => 0, 'target_health' => 0];
$popularLinks = [];
$statusDistribution = [];
$zeroClickLinks = [];
$savedFilters = [];
$auditEvents = [];
$auditTotal = 0;
$auditActions = [];
$auditAction = limit_text(trim((string)($_GET['action'] ?? 'all')), 64);
$systemStatus = [];
$apiTokens = [];
$apiTokenUsage = [];
$apiTokenAlerts = [];
$apiTokenTotal = 0;
$apiTokenPage = max(1, (int)($_GET['token_page'] ?? 1));
$apiTokenPageSize = 25;
$undoableBulkOperations = [];
$totpSetup = null;
$totpProvisioningUri = '';
$tokenRotationDefaultMinutes = max(1, (int)ceil((int)($config['api_token_rotation_overlap_seconds'] ?? 900) / 60));
$tokenRotationMaxMinutes = max(1, (int)floor(min(86400, (int)($config['api_token_rotation_max_overlap_seconds'] ?? 86400)) / 60));
$maintenanceThresholds = linkvault_maintenance_thresholds($config);
$maintenanceExpiringDays = $maintenanceThresholds['expiring_days'];
$maintenanceStaleDays = $maintenanceThresholds['stale_days'];
$maintenanceQuotaPercent = $maintenanceThresholds['quota_percent'];
$maintenanceEvaluatedAt = utc_timestamp();
try {
    $analyticsRequest = $analyticsReportService->normalizeRequest($_GET);
} catch (AnalyticsInvalidDateRange) {
    flash('自定义分析日期无效，请填写有效的开始和结束日期，且开始日期不得晚于结束日期。', 'error');
    redirect_to(app_path('/?section=analytics'));
}
$analyticsDays = (int)$analyticsRequest['days'];
$analyticsLinkId = (int)$analyticsRequest['filters']['link'];
$analyticsFilters = (array)$analyticsRequest['filters'];
$analyticsQueryParameters = $analyticsReportService->queryParameters($analyticsRequest);
$analyticsData = null;
$analyticsStatus = [];
$analyticsLinks = [];
$analyticsFilterOptions = [];
$savedAnalyticsViews = [];
$shortDomains = [];
$domainRetirementJobs = [];
$linkPresets = [];
$webhookDeliveries = [];
$webhookCounts = ['pending' => 0, 'delivered' => 0, 'dead' => 0];
$webhookStatus = in_array((string)($_GET['webhook_status'] ?? 'all'), ['all', 'pending', 'delivered', 'dead'], true)
    ? (string)($_GET['webhook_status'] ?? 'all') : 'all';
$tagRules = [];
$duplicateGroups = [];
$funnelReports = [];
$abuseReports = [];
$blacklistEntries = [];
$riskScans = [];
$adminNotifications = ['items' => [], 'unread' => 0];
if (is_logged_in()) {
    $notificationService = new AdminNotificationService($pdo);
    $notificationService->sync();
    if ($section === 'notifications') {
        $adminNotifications = $notificationService->inbox();
    }
    if (in_array($section, ['links', 'maintenance'], true)) {
        $allTags = $service->allTags($section === 'links' ? $view : 'active');
    }
    if ($section === 'links') {
        $shortDomains = $shortDomainService->all();
        $undoableBulkOperations = $service->undoableBulkOperations();
        $savedFilters = $service->savedFilters();
        $linkPresets = $service->linkPresets();
    } elseif ($section === 'domains') {
        $shortDomains = $shortDomainService->all();
        $domainRetirementJobs = $service->shortDomainRetirementJobs();
    }
    if ($section === 'analytics') {
        $analyticsStatus = linkvault_analytics_status($config);
        $analyticsData = $analyticsReportService->dashboard($analyticsRequest);
        $analyticsFilterOptions = $analyticsReportService->filterOptions($analyticsLinkId);
        $analyticsLinks = (array)$analyticsFilterOptions['links'];
        $savedAnalyticsViews = $analyticsReportService->savedViews();
    } elseif ($section === 'maintenance') {
        $result = $service->listForMaintenance(
            $maintenanceCategory,
            $search,
            $page,
            $pageSize,
            $sort,
            $tag,
            $maintenanceExpiringDays,
            $maintenanceStaleDays,
            $maintenanceQuotaPercent,
            $maintenanceEvaluatedAt
        );
        $links = $result['links'];
        $totalLinks = $result['total'];
        $page = $result['page'];
        $maintenanceCounts = $service->maintenanceCounts(
            $maintenanceExpiringDays,
            $maintenanceStaleDays,
            $maintenanceQuotaPercent,
            $maintenanceEvaluatedAt
        );
    } elseif ($section === 'webhooks') {
        $webhookService = new LifecycleWebhookService($pdo, $config);
        $webhookDeliveries = $webhookService->deliveries($webhookStatus);
        $webhookCounts = $webhookService->deliveryCounts();
    } elseif ($section === 'audit') {
        $auditActions = $service->auditActions();
        if ($auditAction !== 'all' && !in_array($auditAction, $auditActions, true)) {
            $auditAction = 'all';
        }
        $result = $service->listAuditEvents($page, 50, $auditAction);
        $auditEvents = $result['events'];
        $auditTotal = $result['total'];
        $page = $result['page'];
    } elseif ($section === 'workflows') {
        $tagRules = $p2Service->tagRules();
        $duplicateGroups = $p2Service->duplicateGroups();
    } elseif ($section === 'marketing') {
        $funnelReports = $p2Service->funnelReport();
    } elseif ($section === 'trust') {
        $reportStatus = in_array((string)($_GET['report_status'] ?? 'open'), ['open', 'reviewing', 'resolved', 'rejected'], true)
            ? (string)($_GET['report_status'] ?? 'open') : 'open';
        $abuseReports = $p2Service->reports($reportStatus);
        $blacklistEntries = $p2Service->blacklist();
        $riskScans = $p2Service->riskScans();
    } elseif (in_array($section, ['status', 'security', 'domains', 'api'], true)) {
        $systemStatus = (new SystemStatus($pdo, $config))->collect();
        if ($section === 'api') {
            $tokenResult = $apiTokenService->listTokens($apiTokenPage, $apiTokenPageSize);
            $apiTokens = $tokenResult['tokens'];
            $apiTokenTotal = $tokenResult['total'];
            $apiTokenPage = $tokenResult['page'];
            $apiTokenUsage = $apiTokenService->recentUsage();
            $apiTokenAlerts = $apiTokenService->alerts();
        }
        if ($section === 'security') {
            $pendingSetup = $_SESSION['totp_setup'] ?? null;
            if (is_array($pendingSetup) && is_string($pendingSetup['secret'] ?? null)
                && time() <= (int)($pendingSetup['expires_at'] ?? 0)) {
                $totpSetup = $pendingSetup;
                $account = (string)(parse_url(base_url($config), PHP_URL_HOST) ?: 'admin');
                $totpProvisioningUri = $adminSecurityService->provisioningUri($pendingSetup['secret'], $account);
            } elseif ($pendingSetup !== null) {
                unset($_SESSION['totp_setup']);
            }
        }
    } else {
        $result = $service->listForAdmin($view, $search, $page, $pageSize, $status, $sort, $tag, $favoritesOnly);
        $links = $result['links'];
        $totalLinks = $result['total'];
        $page = $result['page'];
    }
}
$filterParts = [];
if ($search !== '') {
    $filterParts[] = '搜索“' . $search . '”';
}
if ($status !== 'all') {
    $filterParts[] = '状态“' . match ($status) {
        'active' => '启用中',
        'inactive' => '已停用',
        'scheduled' => '待启用',
        'expired' => '已过期',
        'exhausted' => '次数用尽',
    } . '”';
}
if ($tag !== '') {
    $filterParts[] = '标签“' . $tag . '”';
}
if ($favoritesOnly) {
    $filterParts[] = '仅收藏';
}
$hasFilters = $filterParts !== [];
$filterDescription = $hasFilters ? '当前条件：' . implode('、', $filterParts) : '';
$flash = flash();
$prefillUrl = limit_text(trim((string)($_GET['url'] ?? '')), max(1, (int)($config['target_url_max_length'] ?? 2048)));
$prefillTitle = limit_text(trim((string)($_GET['title'] ?? '')), 120);
$createValues = [
    'target_url' => valid_target_url($prefillUrl, max(1, (int)($config['target_url_max_length'] ?? 2048))) ? $prefillUrl : '',
    'title' => $prefillTitle,
    'custom_slug' => '',
    'expires_at' => '',
    'starts_at' => '',
    'tags' => '',
    'max_clicks' => '',
    'is_favorite' => '',
    'is_one_time' => '',
    'one_time_mode' => 'immediate',
    'campaign_name' => '',
    'campaign_source' => '',
    'campaign_medium' => '',
    'campaign_content' => '',
    'invalid_message' => '',
    'fallback_url' => '',
    'access_password_required' => '',
    'short_domain_id' => '',
];
$createErrors = [];
$duplicateLinks = [];
$duplicateTargetHash = '';
$createRequestId = bin2hex(random_bytes(16));
$failedEditId = 0;
$failedEditValues = [];
$failedEditErrors = [];
if (is_array($flash) && ($flash['form'] ?? null) === 'create') {
    if (is_array($flash['values'] ?? null)) {
        foreach ($createValues as $field => $default) {
            $createValues[$field] = is_string($flash['values'][$field] ?? null)
                ? $flash['values'][$field]
                : $default;
        }
    }
    $createErrors = is_array($flash['errors'] ?? null) ? $flash['errors'] : [];
    $duplicateLinks = is_array($flash['duplicates'] ?? null) ? $flash['duplicates'] : [];
    $duplicateTargetHash = is_string($flash['duplicate_target_hash'] ?? null)
        && preg_match('/^[a-f0-9]{64}$/D', $flash['duplicate_target_hash']) === 1
        ? $flash['duplicate_target_hash'] : '';
    if (is_string($flash['create_request_id'] ?? null)
        && preg_match('/^[a-f0-9]{32}$/', $flash['create_request_id']) === 1) {
        $createRequestId = $flash['create_request_id'];
    }
} elseif (is_array($flash) && ($flash['form'] ?? null) === 'edit') {
    $failedEditId = max(0, (int)($flash['edit_id'] ?? 0));
    $failedEditValues = is_array($flash['values'] ?? null) ? $flash['values'] : [];
    $failedEditErrors = is_array($flash['errors'] ?? null) ? $flash['errors'] : [];
}
$requestedEditId = $failedEditId > 0 ? $failedEditId : max(0, (int)($_GET['edit'] ?? 0));
$baseUrl = base_url($config);
$apiTokenEnabled = strlen((string)($config['api_token'] ?? '')) >= 24 || $apiTokenService->hasActiveToken('links:create');
$importPreview = $_SESSION['import_preview'] ?? null;
if (is_array($importPreview) && time() > (int)($importPreview['expires_at'] ?? 0)) {
    unset($_SESSION['import_preview']);
    $importPreview = null;
}
$bookmarkletTarget = json_encode(
    rtrim($baseUrl, '/') . '/?url=',
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
);
$bookmarklet = 'javascript:location.assign(' . $bookmarkletTarget
    . "+encodeURIComponent(location.href)+'&title='+encodeURIComponent(document.title))";
$overviewQuery = http_build_query([
    'view' => $view,
    'q' => $search,
    'status' => $status,
    'tag' => $tag,
    'favorite' => $favoritesOnly ? '1' : '',
], '', '&', PHP_QUERY_RFC3986);
$overviewUrl = app_path('/links/overview') . '?' . $overviewQuery;
$currentExportQuery = http_build_query([
    'scope' => 'current',
    'q' => $search,
    'view' => $view,
    'status' => $status,
    'sort' => $sort,
    'tag' => $tag,
    'favorite' => $favoritesOnly ? '1' : '',
]);

require dirname(__DIR__) . '/templates/dashboard.php';
