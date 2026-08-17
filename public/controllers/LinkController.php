<?php

declare(strict_types=1);

final class LinkController
{
    public static function dispatch(
        string $method,
        string $path,
        PDO $pdo,
        array $config,
        LinkService $service,
        ShortDomainService $shortDomains,
        bool $isPublicConfirmation,
        ?string $confirmationSlug,
    ): void {
        if ($method === 'GET' && $path === '/links/overview') {
            require_login();
            $view = (string)($_GET['view'] ?? '') === 'trash' ? 'trash' : 'active';
            $search = limit_text(trim((string)($_GET['q'] ?? '')), 200);
            $status = in_array((string)($_GET['status'] ?? 'all'), [
                'all', 'active', 'inactive', 'scheduled', 'expired', 'exhausted',
            ], true) ? (string)($_GET['status'] ?? 'all') : 'all';
            $sort = in_array((string)($_GET['sort'] ?? 'created_desc'), [
                'created_desc', 'created_asc', 'clicks_desc', 'clicks_asc', 'last_accessed_desc', 'title_asc',
            ], true) ? (string)($_GET['sort'] ?? 'created_desc') : 'created_desc';
            $tag = limit_text(trim((string)($_GET['tag'] ?? '')), 24);
            $favoritesOnly = (string)($_GET['favorite'] ?? '') === '1';
            if ($view === 'trash') {
                $status = 'all';
                $favoritesOnly = false;
            }

            $cacheKey = hash('sha256', json_encode(
                [$view, $search, $status, $tag, $favoritesOnly],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            ));
            $cached = $_SESSION['link_overview_cache'][$cacheKey] ?? null;
            $cacheHit = is_array($cached)
                && is_int($cached['expires_at'] ?? null)
                && $cached['expires_at'] >= time()
                && is_array($cached['data'] ?? null);
            if ($cacheHit) {
                $overview = $cached['data'];
            } else {
                $overview = $service->dashboardOverview($view, $search, $status, $tag, $favoritesOnly);
                $_SESSION['link_overview_cache'] = [
                    $cacheKey => ['expires_at' => time() + 45, 'data' => $overview],
                ];
            }

            $dailyStats = (array)$overview['daily_stats'];
            $popularLinks = (array)$overview['popular_links'];
            $statusDistribution = (array)$overview['status_distribution'];
            $zeroClickLinks = (array)$overview['zero_click_links'];
            $recentClicksTotal = array_sum(array_map(
                static fn (array $stat): int => (int)($stat['clicks'] ?? 0),
                $dailyStats
            ));
            header('Content-Type: text/html; charset=UTF-8');
            header('Cache-Control: no-store, private');
            header('X-LinkVault-Cache: ' . ($cacheHit ? 'HIT' : 'MISS'));
            require dirname(__DIR__, 2) . '/templates/dashboard_stats.php';
            exit;
        }

        if ($method === 'POST' && $path === '/shorten') {
            require_login();
            require_csrf();
            $createRequestId = strtolower(trim((string)($_POST['create_request_id'] ?? '')));
            $targetUrl = trim((string)($_POST['target_url'] ?? ''));
            $title = trim((string)($_POST['title'] ?? ''));
            $customSlug = trim((string)($_POST['custom_slug'] ?? ''));
            $expirationInput = trim((string)($_POST['expires_at'] ?? ''));
            $startsInput = trim((string)($_POST['starts_at'] ?? ''));
            $tagsInput = trim((string)($_POST['tags'] ?? ''));
            $maxClicksInput = trim((string)($_POST['max_clicks'] ?? ''));
            $campaignName = trim((string)($_POST['campaign_name'] ?? ''));
            $campaignSource = trim((string)($_POST['campaign_source'] ?? ''));
            $campaignMedium = trim((string)($_POST['campaign_medium'] ?? ''));
            $campaignContent = trim((string)($_POST['campaign_content'] ?? ''));
            $accessPassword = is_string($_POST['access_password'] ?? null) ? (string)$_POST['access_password'] : '';
            $accessPasswordRequired = (string)($_POST['access_password_required'] ?? '') === '1';
            $invalidMessage = is_string($_POST['invalid_message'] ?? null) ? trim((string)$_POST['invalid_message']) : '';
            $fallbackInput = is_string($_POST['fallback_url'] ?? null) ? trim((string)$_POST['fallback_url']) : '';
            $fallbackUrl = $fallbackInput === '' ? null : $fallbackInput;
            $shortDomainInput = trim((string)($_POST['short_domain_id'] ?? ''));
            $shortDomainId = $shortDomainInput === '' ? null : (ctype_digit($shortDomainInput) ? (int)$shortDomainInput : 0);
            $shortDomain = $shortDomainId === null ? null : $shortDomains->selectable($shortDomainId);
            $campaign = [
                'campaign_name' => $campaignName,
                'campaign_source' => $campaignSource,
                'campaign_medium' => $campaignMedium,
                'campaign_content' => $campaignContent,
            ];
            $isFavorite = (string)($_POST['is_favorite'] ?? '') === '1';
            $isOneTime = (string)($_POST['is_one_time'] ?? '') === '1';
            $oneTimeModeInput = (string)($_POST['one_time_mode'] ?? 'immediate');
            $oneTimeMode = $isOneTime && $oneTimeModeInput === 'confirm' ? 'confirm' : 'immediate';
            [$expirationValid, $expiresAt] = normalize_expiration($expirationInput, $_POST['expires_at_offset'] ?? null);
            [$startsValid, $startsAt] = normalize_expiration($startsInput, $_POST['starts_at_offset'] ?? null);
            [$tagsValid, $tags] = normalize_tags($tagsInput);
            $maxClicksValid = $maxClicksInput === '' || (ctype_digit($maxClicksInput) && (int)$maxClicksInput >= 1);
            $maxClicks = $maxClicksInput === '' ? null : (int)$maxClicksInput;
            $maxUrlLength = max(1, (int)($config['target_url_max_length'] ?? 2048));
            $formValues = [
                'target_url' => $targetUrl,
                'title' => $title,
                'custom_slug' => $customSlug,
                'expires_at' => $expirationInput,
                'starts_at' => $startsInput,
                'tags' => $tagsInput,
                'max_clicks' => $maxClicksInput,
                'is_favorite' => $isFavorite ? '1' : '',
                'is_one_time' => $isOneTime ? '1' : '',
                'one_time_mode' => $oneTimeMode,
                'campaign_name' => $campaignName,
                'campaign_source' => $campaignSource,
                'campaign_medium' => $campaignMedium,
                'campaign_content' => $campaignContent,
                'invalid_message' => $invalidMessage,
                'fallback_url' => $fallbackInput,
                'access_password_required' => ($accessPasswordRequired || $accessPassword !== '') ? '1' : '',
                'short_domain_id' => $shortDomainId === null ? '' : (string)$shortDomainId,
            ];
            $formErrors = [];
            if (preg_match('/^[a-f0-9]{32}$/', $createRequestId) !== 1) {
                $formErrors['request'] = '创建请求已过期，请重新提交。';
            }
            if ($shortDomainId !== null && $shortDomain === null) {
                $formErrors['short_domain_id'] = '请选择已验证且启用的短链域名。';
            }
            if (!valid_target_url($targetUrl, $maxUrlLength)) {
                $formErrors['target_url'] = '请输入以 http:// 或 https:// 开头的有效网址，且长度不能超过 ' . $maxUrlLength . ' 个字符。';
            }
            if (text_length($title) > 120) {
                $formErrors['title'] = '标题不能超过 120 个字符。';
            }
            if ($customSlug !== '' && !valid_slug($customSlug)) {
                $formErrors['custom_slug'] = '短码须为 3-64 位字母、数字、下划线或横线，且不能使用系统保留名称。';
            }
            if (!$expirationValid) {
                $formErrors['expires_at'] = '过期时间无效，请按标注的时区重新填写。';
            }
            if (!$startsValid) {
                $formErrors['starts_at'] = '启用时间无效，请按标注的时区重新填写。';
            }
            if ($startsAt !== null && $expiresAt !== null && $startsAt >= $expiresAt) {
                $formErrors['starts_at'] = '定时启用必须早于过期时间。';
            }
            if (!$tagsValid) {
                $formErrors['tags'] = '最多设置 10 个标签，每个标签不能超过 24 个字符。';
            }
            if (!$maxClicksValid || $maxClicks !== null && $maxClicks > 2147483647) {
                $formErrors['max_clicks'] = '最大点击次数须为 1 至 2147483647。';
            }
            if (strlen($accessPassword) > 1024 || str_contains($accessPassword, "\0")) {
                $formErrors['access_password'] = '访问密码不能超过 1024 字节，也不能包含空字符。';
            }
            if ($accessPasswordRequired && $accessPassword === '') {
                $formErrors['access_password'] = '请重新输入访问密码。';
            }
            if (!valid_invalid_message($invalidMessage)) {
                $formErrors['invalid_message'] = '失效提示不能超过 500 个字符，也不能包含控制字符。';
            }
            if ($fallbackUrl !== null && !valid_target_url($fallbackUrl, $maxUrlLength)) {
                $formErrors['fallback_url'] = '备用地址必须是有效的 http:// 或 https:// 网址。';
            }
            foreach ($campaign as $campaignField => $campaignValue) {
                if (!valid_campaign_value($campaignValue)) {
                    $formErrors[$campaignField] = '活动字段不能超过 100 个字符，也不能包含控制字符。';
                }
            }
            if (!isset($formErrors['target_url'])
                && !array_intersect_key($formErrors, $campaign)
                && !valid_target_url($targetUrl = apply_campaign_parameters($targetUrl, $campaign), $maxUrlLength)) {
                $formErrors['target_url'] = '添加 UTM 参数后网址超过长度限制，请缩短活动字段或目标地址。';
            }
            if ($formErrors) {
                if ($accessPassword !== '' && !isset($formErrors['access_password'])) {
                    $formErrors['access_password'] = '其他字段修正后，请重新输入访问密码。';
                }
                flash('请修正标出的字段。', 'error', [
                    'form' => 'create',
                    'values' => $formValues,
                    'errors' => $formErrors,
                    'create_request_id' => preg_match('/^[a-f0-9]{32}$/', $createRequestId) === 1
                        ? $createRequestId : bin2hex(random_bytes(16)),
                ]);
                redirect_to(app_path('/'));
            }
            $payloadHash = hash('sha256', json_encode([
                'target_url' => $targetUrl,
                'title' => $title,
                'custom_slug' => $customSlug,
                'expires_at' => $expiresAt,
                'starts_at' => $startsAt,
                'tags' => $tags,
                'max_clicks' => $maxClicks,
                'is_favorite' => $isFavorite,
                'is_one_time' => $isOneTime,
                'one_time_mode' => $oneTimeMode,
                'campaign_name' => $campaignName,
                'campaign_source' => $campaignSource,
                'campaign_medium' => $campaignMedium,
                'campaign_content' => $campaignContent,
                'password_protected' => $accessPassword !== '',
                'invalid_message' => $invalidMessage,
                'fallback_url' => $fallbackUrl,
                'short_domain_id' => $shortDomainId,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $replay = $service->findCreateReplay($createRequestId, $payloadHash);
            if ($replay !== null) {
                $replayState = $service->getAdminLink((int)$replay['id']);
                flash('创建请求已处理，返回原短链接。', 'ok', [
                    'short_url' => short_url_base($config, is_array($replayState) ? $replayState : []) . '/' . rawurlencode($replay['slug']),
                    'detail_url' => app_path('/link') . '?id=' . $replay['id'],
                ]);
                redirect_to(app_path('/'));
            }
            $duplicates = $service->findDuplicates($targetUrl, 5, $shortDomainId);
            $duplicateConfirmationHash = hash('sha256', json_encode([
                'target_url' => $targetUrl,
                'short_domain_id' => $shortDomainId,
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            $duplicateTargetHash = (string)($_POST['duplicate_target_hash'] ?? '');
            $duplicateConfirmed = (string)($_POST['allow_duplicate'] ?? '') === '1'
                && preg_match('/^[a-f0-9]{64}$/D', $duplicateTargetHash) === 1
                && hash_equals($duplicateConfirmationHash, $duplicateTargetHash);
            if ($duplicates && !$duplicateConfirmed) {
                flash('该目标地址已有短链接，可直接复用或继续创建。', 'error', [
                    'form' => 'create',
                    'values' => $formValues,
                    'errors' => $accessPassword !== '' ? ['access_password' => '继续创建前，请重新输入访问密码。'] : [],
                    'duplicates' => $service->findTargetDuplicates($targetUrl, 20, $shortDomainId),
                    'duplicate_target_hash' => $duplicateConfirmationHash,
                    'create_request_id' => $createRequestId,
                ]);
                redirect_to(app_path('/'));
            }
            try {
                $accessPasswordHash = $accessPassword === '' ? null : password_hash($accessPassword, PASSWORD_DEFAULT);
                $slug = $customSlug !== '' ? $customSlug : random_slug($pdo, (int)($config['slug_length'] ?? 6));
                $created = $service->createIdempotent(
                    $createRequestId,
                    $payloadHash,
                    $slug,
                    $targetUrl,
                    $title,
                    $expiresAt,
                    $tags,
                    $isFavorite,
                    $startsAt,
                    $maxClicks,
                    $isOneTime,
                    $oneTimeMode,
                    $campaignName,
                    $campaignSource,
                    $campaignMedium,
                    $campaignContent,
                    $accessPasswordHash,
                    $invalidMessage,
                    $fallbackUrl,
                    $shortDomainId
                );
                $createdState = $service->getAdminLink((int)$created['id']);
                flash($created['replayed'] ? '创建请求已处理，返回原短链接。' : '短链接已生成。', 'ok', [
                    'short_url' => short_url_base($config, is_array($createdState) ? $createdState : []) . '/' . rawurlencode($created['slug']),
                    'detail_url' => app_path('/link') . '?id=' . $created['id'],
                ]);
                audit_event($pdo, $config, 'admin', $created['replayed'] ? 'create_replayed' : 'create', 'success', 'link', (string)$created['id'], [
                    'before' => null,
                    'after' => audit_link_state($createdState),
                ]);
            } catch (Throwable $exception) {
                if (is_database_unavailable($exception)) {
                    throw $exception;
                }
                if ($exception instanceof PDOException && is_slug_unique_violation($exception)) {
                    flash('请修正标出的字段。', 'error', [
                        'form' => 'create',
                        'values' => $formValues,
                        'errors' => ['custom_slug' => '短码已存在，请换一个。'],
                        'create_request_id' => $createRequestId,
                    ]);
                } elseif ($exception instanceof IdempotencyConflict) {
                    flash('创建请求已被其他内容使用，请重新提交。', 'error', [
                        'form' => 'create',
                        'values' => $formValues,
                        'errors' => ['request' => '一次性创建标识已使用。'],
                        'create_request_id' => bin2hex(random_bytes(16)),
                    ]);
                } else {
                    log_event($config, 'link_create_failed', ['error' => limit_text($exception->getMessage(), 300)]);
                    audit_event($pdo, $config, 'admin', 'create', 'failure', 'link', null, [
                        'reason' => limit_text($exception->getMessage(), 200),
                    ]);
                    flash('存储失败，请查看日志。输入内容已保留。', 'error', [
                        'form' => 'create',
                        'values' => $formValues,
                        'errors' => [],
                        'create_request_id' => $createRequestId,
                    ]);
                }
            }
            redirect_to(app_path('/'));
        }

        if ($method === 'GET' && $path === '/edit') {
            require_login();
            $editLink = $service->getAdminLink(positive_integer_id($_GET['id'] ?? null));
            if (!$editLink || !empty($editLink['deleted_at'])) {
                render_error_page(404, '链接不存在', '无法找到可编辑的短链接。');
            }
            $editReturnParameters = array_intersect_key($_GET, array_flip([
                'return_q', 'return_view', 'return_page', 'return_status', 'return_sort',
                'return_tag', 'return_favorite', 'return_scroll', 'return_section', 'return_maintenance',
            ]));
            $editReturnsToDetail = (string)($_GET['return_to_detail'] ?? '') === '1';
            $editDetailPath = app_path('/link') . '?' . http_build_query(array_merge(
                ['id' => (int)$editLink['id']],
                $editReturnParameters
            ));
            $editReturnPath = $editReturnsToDetail
                ? $editDetailPath
                : returned_list_path($editReturnParameters);
            $editFlash = flash();
            $editValues = is_array($editFlash)
                && ($editFlash['form'] ?? null) === 'edit'
                && (int)($editFlash['edit_id'] ?? 0) === (int)$editLink['id']
                && is_array($editFlash['values'] ?? null)
                ? $editFlash['values'] : [];
            $editErrors = is_array($editFlash)
                && ($editFlash['form'] ?? null) === 'edit'
                && (int)($editFlash['edit_id'] ?? 0) === (int)$editLink['id']
                && is_array($editFlash['errors'] ?? null)
                ? $editFlash['errors'] : [];
            $editAliases = $service->aliasesForLink((int)$editLink['id']);
            require dirname(__DIR__, 2) . '/templates/link_edit.php';
            exit;
        }

        if ($method === 'POST' && $path === '/edit') {
            require_login();
            require_csrf();
            $id = positive_integer_id($_POST['id'] ?? null);
            $returnToDetail = (string)($_POST['return_to_detail'] ?? '') === '1';
            $standaloneEdit = (string)($_POST['standalone_edit'] ?? '') === '1' || $returnToDetail;
            $standaloneReturnParameters = array_intersect_key($_POST, array_flip([
                'return_q', 'return_view', 'return_page', 'return_status', 'return_sort',
                'return_tag', 'return_favorite', 'return_scroll', 'return_section', 'return_maintenance',
            ]));
            $standaloneEditPath = app_path('/edit') . '?' . http_build_query(array_merge(
                ['id' => $id],
                $returnToDetail ? ['return_to_detail' => '1'] : [],
                $standaloneReturnParameters
            ));
            $standaloneDetailPath = app_path('/link') . '?' . http_build_query(array_merge(
                ['id' => $id],
                $standaloneReturnParameters
            ));
            $beforeState = $id > 0 ? $service->getAdminLink($id) : null;
            $targetUrl = trim((string)($_POST['target_url'] ?? ''));
            $customSlug = trim((string)($_POST['custom_slug'] ?? ($beforeState['slug'] ?? '')));
            $title = trim((string)($_POST['title'] ?? ''));
            $expirationInput = trim((string)($_POST['expires_at'] ?? ''));
            $startsInput = trim((string)($_POST['starts_at'] ?? ''));
            $tagsInput = trim((string)($_POST['tags'] ?? ''));
            $maxClicksInput = trim((string)($_POST['max_clicks'] ?? ''));
            $campaignName = trim((string)($_POST['campaign_name'] ?? ''));
            $campaignSource = trim((string)($_POST['campaign_source'] ?? ''));
            $campaignMedium = trim((string)($_POST['campaign_medium'] ?? ''));
            $campaignContent = trim((string)($_POST['campaign_content'] ?? ''));
            $accessPassword = is_string($_POST['access_password'] ?? null) ? (string)$_POST['access_password'] : '';
            $removeAccessPassword = (string)($_POST['remove_access_password'] ?? '') === '1';
            $invalidMessage = is_string($_POST['invalid_message'] ?? null) ? trim((string)$_POST['invalid_message']) : '';
            $fallbackInput = is_string($_POST['fallback_url'] ?? null) ? trim((string)$_POST['fallback_url']) : '';
            $fallbackUrl = $fallbackInput === '' ? null : $fallbackInput;
            $campaign = [
                'campaign_name' => $campaignName,
                'campaign_source' => $campaignSource,
                'campaign_medium' => $campaignMedium,
                'campaign_content' => $campaignContent,
            ];
            $isFavorite = (string)($_POST['is_favorite'] ?? '') === '1';
            $isOneTime = (string)($_POST['is_one_time'] ?? '') === '1';
            $oneTimeModeInput = (string)($_POST['one_time_mode'] ?? 'immediate');
            $oneTimeMode = $isOneTime && $oneTimeModeInput === 'confirm' ? 'confirm' : 'immediate';
            $expectedUpdatedAt = $_POST['updated_at'] ?? null;
            [$expirationValid, $expiresAt] = normalize_expiration($expirationInput, $_POST['expires_at_offset'] ?? null);
            [$startsValid, $startsAt] = normalize_expiration($startsInput, $_POST['starts_at_offset'] ?? null);
            [$tagsValid, $tags] = normalize_tags($tagsInput);
            $maxClicksValid = $maxClicksInput === '' || (ctype_digit($maxClicksInput) && (int)$maxClicksInput >= 1);
            $maxClicks = $maxClicksInput === '' ? null : (int)$maxClicksInput;
            $formValues = [
                'custom_slug' => $customSlug,
                'target_url' => $targetUrl,
                'title' => $title,
                'expires_at' => $expirationInput,
                'starts_at' => $startsInput,
                'tags' => $tagsInput,
                'max_clicks' => $maxClicksInput,
                'is_favorite' => $isFavorite ? '1' : '',
                'is_one_time' => $isOneTime ? '1' : '',
                'one_time_mode' => $oneTimeMode,
                'campaign_name' => $campaignName,
                'campaign_source' => $campaignSource,
                'campaign_medium' => $campaignMedium,
                'campaign_content' => $campaignContent,
                'remove_access_password' => $removeAccessPassword ? '1' : '',
                'invalid_message' => $invalidMessage,
                'fallback_url' => $fallbackInput,
            ];
            $formErrors = [];
            if (!valid_slug($customSlug)) {
                $formErrors['custom_slug'] = '短码须为 3-64 位字母、数字、下划线或横线，且不能使用系统保留名称。';
            }
            if (!valid_target_url($targetUrl, max(1, (int)($config['target_url_max_length'] ?? 2048)))) {
                $formErrors['target_url'] = '请输入以 http:// 或 https:// 开头的有效网址。';
            }
            if (text_length($title) > 120) {
                $formErrors['title'] = '标题不能超过 120 个字符。';
            }
            if (!$expirationValid) {
                $formErrors['expires_at'] = '过期时间无效，请按标注的时区重新填写。';
            }
            if (!$startsValid) {
                $formErrors['starts_at'] = '启用时间无效，请按标注的时区重新填写。';
            }
            if ($startsAt !== null && $expiresAt !== null && $startsAt >= $expiresAt) {
                $formErrors['starts_at'] = '定时启用必须早于过期时间。';
            }
            if (!$tagsValid) {
                $formErrors['tags'] = '最多设置 10 个标签，每个标签不能超过 24 个字符。';
            }
            if (!$maxClicksValid || $maxClicks !== null && $maxClicks > 2147483647) {
                $formErrors['max_clicks'] = '最大点击次数须为 1 至 2147483647。';
            }
            if (strlen($accessPassword) > 1024 || str_contains($accessPassword, "\0")) {
                $formErrors['access_password'] = '访问密码不能超过 1024 字节，也不能包含空字符。';
            }
            if ($removeAccessPassword && $accessPassword !== '') {
                $formErrors['access_password'] = '设置新密码和移除密码不能同时选择。';
            }
            if ((int)($beforeState['access_password_reset_required'] ?? 0) === 1 && $accessPassword === '') {
                $formErrors['access_password'] = '该链接来自受密码保护的导出，必须重新设置访问密码。';
            }
            if (!valid_invalid_message($invalidMessage)) {
                $formErrors['invalid_message'] = '失效提示不能超过 500 个字符，也不能包含控制字符。';
            }
            if ($fallbackUrl !== null && !valid_target_url(
                $fallbackUrl,
                max(1, (int)($config['target_url_max_length'] ?? 2048))
            )) {
                $formErrors['fallback_url'] = '备用地址必须是有效的 http:// 或 https:// 网址。';
            }
            foreach ($campaign as $campaignField => $campaignValue) {
                if (!valid_campaign_value($campaignValue)) {
                    $formErrors[$campaignField] = '活动字段不能超过 100 个字符，也不能包含控制字符。';
                }
            }
            $hadCampaign = is_array($beforeState) && array_filter([
                (string)($beforeState['campaign_name'] ?? ''),
                (string)($beforeState['campaign_source'] ?? ''),
                (string)($beforeState['campaign_medium'] ?? ''),
                (string)($beforeState['campaign_content'] ?? ''),
            ]) !== [];
            if (!isset($formErrors['target_url'])
                && !array_intersect_key($formErrors, $campaign)
                && !valid_target_url(
                    $targetUrl = apply_campaign_parameters($targetUrl, $campaign, $hadCampaign),
                    max(1, (int)($config['target_url_max_length'] ?? 2048))
                )) {
                $formErrors['target_url'] = '添加 UTM 参数后网址超过长度限制，请缩短活动字段或目标地址。';
            }
            if ($id <= 0 || $formErrors || !is_string($expectedUpdatedAt) || $expectedUpdatedAt === '') {
                if ($accessPassword !== '' && !isset($formErrors['access_password'])) {
                    $formErrors['access_password'] = '其他字段修正后，请重新输入访问密码。';
                }
                flash(
                    $id <= 0
                        ? '无法识别要编辑的链接。'
                    : ($formErrors ? '请修正标出的字段。' : '编辑请求已过期，请刷新页面后重试。'),
                    'error',
                    [
                        'form' => 'edit',
                        'edit_id' => $id,
                        'values' => $formValues,
                        'errors' => $formErrors,
                    ]
                );
                redirect_to($standaloneEdit ? $standaloneEditPath : posted_list_path());
            }
            try {
                $accessPasswordHash = $accessPassword === '' ? null : password_hash($accessPassword, PASSWORD_DEFAULT);
                $updated = $service->update(
                    $id,
                    $targetUrl,
                    $title,
                    $expiresAt,
                    $expectedUpdatedAt,
                    $tags,
                    $isFavorite,
                    $startsAt,
                    $maxClicks,
                    $isOneTime,
                    $oneTimeMode,
                    $campaignName,
                    $campaignSource,
                    $campaignMedium,
                    $campaignContent,
                    $accessPasswordHash,
                    $removeAccessPassword,
                    $invalidMessage,
                    $fallbackUrl,
                    $customSlug,
                    (string)($_POST['preserve_old_slug'] ?? '') === '1'
                );
                if ($updated) {
                    flash('短链接已更新。');
                } else {
                    flash('链接不存在、已在回收站，或已被其他标签页修改。请核对后重新提交。', 'error', [
                        'form' => 'edit',
                        'edit_id' => $id,
                        'values' => $formValues,
                        'errors' => $accessPassword !== ''
                            ? ['access_password' => '链接状态已变化，请重新输入访问密码。'] : [],
                    ]);
                }
                audit_event($pdo, $config, 'admin', 'edit', $updated ? 'success' : 'failure', 'link', (string)$id, [
                    'before' => audit_link_state($beforeState),
                    'after' => audit_link_state($updated ? $service->getAdminLink($id) : $beforeState),
                ]);
            } catch (InvalidArgumentException $exception) {
                flash('请修正标出的字段。', 'error', [
                    'form' => 'edit',
                    'edit_id' => $id,
                    'values' => $formValues,
                    'errors' => ['custom_slug' => '短码已存在，请换一个。'],
                ]);
                redirect_to($standaloneEdit ? $standaloneEditPath : posted_list_path());
            } catch (Throwable $exception) {
                log_event($config, 'link_update_failed', ['id' => $id, 'error' => limit_text($exception->getMessage(), 300)]);
                audit_event($pdo, $config, 'admin', 'edit', 'failure', 'link', (string)$id, [
                    'reason' => limit_text($exception->getMessage(), 200),
                ]);
                flash('存储失败，请查看日志。输入内容已保留。', 'error', [
                    'form' => 'edit',
                    'edit_id' => $id,
                    'values' => $formValues,
                    'errors' => [],
                ]);
                redirect_to($standaloneEdit ? $standaloneEditPath : posted_list_path());
            }
            redirect_to($standaloneEdit
                ? ($updated
                    ? ($returnToDetail ? $standaloneDetailPath : returned_list_path($standaloneReturnParameters))
                    : $standaloneEditPath)
                : posted_list_path());
        }

        if ($method === 'POST' && $path === '/toggle') {
            require_login();
            require_csrf();
            $desiredStateValue = $_POST['desired_state'] ?? null;
            $expectedUpdatedAt = $_POST['updated_at'] ?? null;
            if (!is_string($desiredStateValue) || !in_array($desiredStateValue, ['0', '1'], true)
                || !is_string($expectedUpdatedAt) || $expectedUpdatedAt === '') {
                flash('启停请求已过期，请刷新页面后重试。', 'error');
                redirect_to(posted_list_path());
            }
            $id = (int)($_POST['id'] ?? 0);
            $beforeState = $service->getAdminLink($id);
            if ($desiredStateValue === '1'
                && (int)($beforeState['access_password_reset_required'] ?? 0) === 1) {
                flash('该链接必须重新设置访问密码后才能启用。', 'error');
                redirect_to(posted_list_path());
            }
            $updated = $service->toggle(
                $id,
                $desiredStateValue === '1',
                $expectedUpdatedAt
            );
            audit_event($pdo, $config, 'admin', 'status_change', $updated ? 'success' : 'failure', 'link', (string)$id, [
                'before' => audit_link_state($beforeState),
                'after' => audit_link_state($updated ? $service->getAdminLink($id) : $beforeState),
            ]);
            flash(
                $updated ? '链接状态已更新。' : '链接不存在、已在回收站，或已被其他标签页修改。',
                $updated ? 'ok' : 'error'
            );
            redirect_to(posted_list_path());
        }

        if ($method === 'POST' && $path === '/favorite') {
            require_login();
            require_csrf();
            $desiredValue = (string)($_POST['desired_state'] ?? '');
            if (!in_array($desiredValue, ['0', '1'], true)) {
                flash('收藏请求无效。', 'error');
                redirect_to(posted_list_path());
            }
            $id = (int)($_POST['id'] ?? 0);
            $beforeState = $service->getAdminLink($id);
            $updated = $service->setFavorite($id, $desiredValue === '1');
            audit_event($pdo, $config, 'admin', 'favorite_change', $updated ? 'success' : 'failure', 'link', (string)$id, [
                'before' => audit_link_state($beforeState),
                'after' => audit_link_state($updated ? $service->getAdminLink($id) : $beforeState),
            ]);
            flash($updated ? '收藏状态已更新。' : '链接不存在或已在回收站。', $updated ? 'ok' : 'error');
            redirect_to(posted_list_path());
        }

        if ($method === 'POST' && $path === '/bulk/preview') {
            require_login();
            require_csrf();
            $selected = $_POST['selected'] ?? [];
            $action = is_string($_POST['bulk_action'] ?? null) ? $_POST['bulk_action'] : '';
            if (!is_array($selected) || !$selected) {
                json_response(422, ['error' => '请先选择至少一条链接。']);
            }
            try {
                [$bulkTagsValid, $bulkTags] = normalize_tags(trim((string)($_POST['bulk_tags'] ?? '')));
                if (!$bulkTagsValid) {
                    throw new InvalidArgumentException('Invalid bulk tags.');
                }
                $preview = $service->previewBulkOperation(
                    $selected,
                    $action,
                    (int)($_POST['bulk_days'] ?? 0),
                    $bulkTags
                );
                audit_event($pdo, $config, 'admin', 'bulk_preview', 'success', 'bulk_operation', (string)$preview['operation_id'], [
                    'action' => $action,
                    'selected' => $preview['selected'],
                    'would_change' => $preview['would_change'],
                    'link_ids' => $preview['selected_ids'],
                ]);
                json_response(200, $preview);
            } catch (InvalidArgumentException $exception) {
                audit_event($pdo, $config, 'admin', 'bulk_preview', 'failure', 'bulk_operation', null, [
                    'action' => limit_text($action, 40),
                    'reason' => limit_text($exception->getMessage(), 200),
                ]);
                json_response(422, ['error' => '批量操作参数无效，或标签数量超过限制。']);
            }
        }

        if ($method === 'POST' && $path === '/bulk') {
            require_login();
            require_csrf();
            $operationId = trim((string)($_POST['operation_id'] ?? ''));
            $action = is_string($_POST['bulk_action'] ?? null) ? $_POST['bulk_action'] : '';
            try {
                if ($operationId === '') {
                    throw new InvalidArgumentException('Bulk preview is required.');
                }
                $result = $service->applyBulkOperation(
                    $operationId,
                    (string)($_POST['confirm_purge'] ?? '') === '1'
                );
                $changed = (int)($result['changed'] ?? 0);
                $action = (string)($result['action'] ?? $action);
                audit_event($pdo, $config, 'admin', 'bulk_apply', ($result['status'] ?? null) === 'applied' ? 'success' : 'failure', 'bulk_operation', $operationId, [
                    'action' => limit_text($action, 40),
                    'status' => (string)($result['status'] ?? 'invalid'),
                    'changed' => $changed,
                    'link_ids' => (array)($result['link_ids'] ?? []),
                ]);
            } catch (InvalidArgumentException $exception) {
                audit_event($pdo, $config, 'admin', 'bulk_apply', 'failure', 'bulk_operation', $operationId ?: null, [
                    'reason' => limit_text($exception->getMessage(), 200),
                ]);
                flash('批量操作无效、已过期或已经处理。', 'error');
                redirect_to(posted_list_path());
            }
            if (($result['status'] ?? null) === 'conflicted') {
                flash('预览后链接已发生变化，整批操作未执行。', 'error');
            } elseif (($result['status'] ?? null) === 'expired') {
                flash('批量操作预览已过期，请重新预览。', 'error');
            } elseif (!empty($result['reversible'])) {
                flash($action === 'delete' ? "已删除 {$changed} 条，可撤销。" : "已处理 {$changed} 条，可在 24 小时内撤销。", 'ok', [
                    'undo_operation_id' => $operationId,
                ]);
            } else {
                flash($changed > 0 ? "已处理 {$changed} 条链接。" : '没有可处理的链接。', $changed > 0 ? 'ok' : 'error');
            }
            redirect_to(posted_list_path());
        }

        if ($method === 'POST' && $path === '/bulk/undo') {
            require_login();
            require_csrf();
            $operationId = trim((string)($_POST['operation_id'] ?? ''));
            try {
                $result = $service->undoBulkOperation($operationId);
                $changed = (int)($result['changed'] ?? 0);
                $success = ($result['status'] ?? null) === 'undone';
                audit_event($pdo, $config, 'admin', 'bulk_undo', $success ? 'success' : 'failure', 'bulk_operation', $operationId, [
                    'status' => (string)($result['status'] ?? 'invalid'),
                    'changed' => $changed,
                    'link_ids' => (array)($result['link_ids'] ?? []),
                ]);
                flash(
                    $success ? "已撤销 {$changed} 条链接的批量操作。" : '链接已在操作后发生变化，未执行撤销。',
                    $success ? 'ok' : 'error'
                );
            } catch (InvalidArgumentException $exception) {
                audit_event($pdo, $config, 'admin', 'bulk_undo', 'failure', 'bulk_operation', $operationId ?: null, [
                    'reason' => limit_text($exception->getMessage(), 200),
                ]);
                flash('该批量操作不可撤销、已经撤销或撤销期限已过。', 'error');
            }
            redirect_to(posted_list_path());
        }

        if ($method === 'POST' && $path === '/clear-expiration') {
            require_login();
            require_csrf();
            $id = (int)($_POST['id'] ?? 0);
            $beforeState = $service->getAdminLink($id);
            $cleared = $service->clearExpiredAt($id);
            audit_event($pdo, $config, 'admin', 'clear_expiration', $cleared ? 'success' : 'failure', 'link', (string)$id, [
                'before' => audit_link_state($beforeState),
                'after' => audit_link_state($cleared ? $service->getAdminLink($id) : $beforeState),
            ]);
            flash(
                $cleared ? '过期时间已清除。' : '链接不存在、未过期或已在回收站。',
                $cleared ? 'ok' : 'error'
            );
            redirect_to(posted_list_path());
        }

        if ($method === 'POST' && $path === '/delete') {
            require_login();
            require_csrf();
            $id = (int)($_POST['id'] ?? 0);
            $beforeState = $service->getAdminLink($id);
            $deleted = $service->softDelete($id);
            audit_event($pdo, $config, 'admin', 'delete', $deleted ? 'success' : 'failure', 'link', (string)$id, [
                'before' => audit_link_state($beforeState),
                'after' => audit_link_state($deleted ? $service->getAdminLink($id) : $beforeState),
            ]);
            flash(
                $deleted ? '删除成功，可撤销。' : '链接不存在。',
                $deleted ? 'ok' : 'error',
                $deleted ? ['undo_ids' => [$id]] : []
            );
            redirect_to(posted_list_path());
        }

        if ($method === 'POST' && $path === '/restore') {
            require_login();
            require_csrf();
            $id = (int)($_POST['id'] ?? 0);
            $beforeState = $service->getAdminLink($id);
            $restored = $service->restore($id);
            audit_event($pdo, $config, 'admin', 'restore', $restored ? 'success' : 'failure', 'link', (string)$id, [
                'before' => audit_link_state($beforeState),
                'after' => audit_link_state($restored ? $service->getAdminLink($id) : $beforeState),
            ]);
            flash($restored ? '短链接已恢复。' : '链接不存在。', $restored ? 'ok' : 'error');
            redirect_to(posted_list_path());
        }

        if ($method === 'POST' && $path === '/purge') {
            require_login();
            require_csrf();
            $id = (int)($_POST['id'] ?? 0);
            $confirmationToken = $_POST['confirmation_token'] ?? null;
            if (!is_string($confirmationToken) || $confirmationToken === ''
                || !hash_equals(purge_confirmation_token($id), $confirmationToken)) {
                flash('请确认永久删除后再提交。', 'error');
                redirect_to(posted_list_path());
            }
            $beforeState = $service->getAdminLink($id);
            $purged = $service->purge($id);
            audit_event($pdo, $config, 'admin', 'purge', $purged ? 'success' : 'failure', 'link', (string)$id, [
                'before' => audit_link_state($beforeState),
                'after' => audit_link_state($purged ? null : $beforeState),
            ]);
            flash($purged ? '短链接已永久删除。' : '链接不存在或已恢复。', $purged ? 'ok' : 'error');
            redirect_to(posted_list_path());
        }

        if ($method === 'POST' && $path === '/filters/save') {
            require_login();
            require_csrf();
            $name = trim((string)($_POST['name'] ?? ''));
            $filterView = (string)($_POST['view'] ?? '') === 'trash' ? 'trash' : 'active';
            $filterSearch = limit_text(trim((string)($_POST['q'] ?? '')), 200);
            $filterStatus = in_array((string)($_POST['status'] ?? 'all'), ['all', 'active', 'inactive', 'scheduled', 'expired', 'exhausted'], true)
                ? (string)$_POST['status'] : 'all';
            $filterSort = in_array((string)($_POST['sort'] ?? 'created_desc'), [
                'created_desc', 'created_asc', 'clicks_desc', 'clicks_asc', 'last_accessed_desc', 'title_asc',
            ], true) ? (string)$_POST['sort'] : 'created_desc';
            $filterTag = limit_text(trim((string)($_POST['tag'] ?? '')), 24);
            $filterFavorites = (string)($_POST['favorite'] ?? '') === '1';
            if ($filterView === 'trash') {
                $filterStatus = 'all';
                $filterFavorites = false;
            }
            if ($name === '' || text_length($name) > 60 || preg_match('/[\x00-\x1F\x7F]/u', $name)) {
                flash('筛选名称须为 1 至 60 个字符。', 'error');
                redirect_to(posted_list_path());
            }
            $filterId = $service->saveFilter(
                $name,
                $filterView,
                $filterSearch,
                $filterStatus,
                $filterSort,
                $filterTag,
                $filterFavorites
            );
            audit_event($pdo, $config, 'admin', 'save_filter', 'success', 'saved_filter', (string)$filterId, [
                'name' => $name,
                'filter' => [
                    'view' => $filterView,
                    'search' => $filterSearch,
                    'status' => $filterStatus,
                    'sort' => $filterSort,
                    'tag' => $filterTag,
                    'favorites_only' => $filterFavorites,
                ],
            ]);
            flash('常用筛选已保存；同名筛选会直接更新。');
            redirect_to(posted_list_path());
        }

        if ($method === 'POST' && $path === '/filters/delete') {
            require_login();
            require_csrf();
            $filterId = max(0, (int)($_POST['id'] ?? 0));
            $deleted = $service->deleteSavedFilter($filterId);
            audit_event($pdo, $config, 'admin', 'delete_filter', $deleted ? 'success' : 'failure', 'saved_filter', (string)$filterId);
            flash($deleted ? '常用筛选已删除。' : '常用筛选不存在。', $deleted ? 'ok' : 'error');
            redirect_to(posted_list_path());
        }

        if ($method === 'POST' && $path === '/filters/rename') {
            require_login();
            require_csrf();
            $filterId = max(0, (int)($_POST['id'] ?? 0));
            $name = trim((string)($_POST['name'] ?? ''));
            if ($filterId <= 0 || $name === '' || text_length($name) > 60 || preg_match('/[\x00-\x1F\x7F]/u', $name)) {
                flash('筛选名称须为 1 至 60 个字符。', 'error');
                redirect_to(posted_list_path());
            }
            $renamed = $service->renameSavedFilter($filterId, $name);
            audit_event($pdo, $config, 'admin', 'rename_filter', $renamed ? 'success' : 'failure', 'saved_filter', (string)$filterId, [
                'name' => $name,
            ]);
            flash($renamed ? '常用筛选已重命名。' : '常用筛选不存在，或名称已被使用。', $renamed ? 'ok' : 'error');
            redirect_to(posted_list_path());
        }

        if ($method === 'GET' && $path === '/link') {
            require_login();
            $detailLink = $service->getAdminLink(positive_integer_id($_GET['id'] ?? null));
            if (!$detailLink) {
                render_error_page(404, '链接不存在', '无法找到这条短链接。');
            }
            $requestedTrendDays = (int)($_GET['days'] ?? 14);
            $trendDays = in_array($requestedTrendDays, [7, 14, 30], true) ? $requestedTrendDays : 14;
            $linkTrend = $service->trend((int)$detailLink['id'], $trendDays);
            $statusHistory = $service->statusHistory((int)$detailLink['id']);
            $targetHealth = $service->targetHealthForLink((int)$detailLink['id']);
            $detailAliases = $service->aliasesForLink((int)$detailLink['id']);
            $detailFlash = flash();
            $baseUrl = base_url($config);
            $returnPath = returned_list_path($_GET);
            $detailReturnParameters = array_intersect_key($_GET, array_flip([
                'return_q', 'return_view', 'return_page', 'return_status', 'return_sort',
                'return_tag', 'return_favorite', 'return_scroll', 'return_section', 'return_maintenance',
            ]));
            require dirname(__DIR__, 2) . '/templates/link_detail.php';
            exit;
        }

        if ($isPublicConfirmation) {
            require_public_csrf();
            if ((string)($_POST['cancel'] ?? '') === '1') {
                unset($_SESSION['link_unlock_confirmation']);
                session_write_close();
                redirect_to(app_path('/'));
            }
            $link = $service->find((string)$confirmationSlug);
            if (!$link || !link_is_available($link)
                || (int)($link['is_one_time'] ?? 0) !== 1
                || (string)($link['one_time_mode'] ?? 'immediate') !== 'confirm') {
                render_error_page(404, '短链接不存在', '这个短链接不存在、已停用、已过期或已经使用。');
            }
            if (link_is_password_protected($link) && !consume_link_confirmation_grant($link)) {
                render_error_page(403, '访问授权已过期', '请重新打开短链接并验证访问密码。');
            }
            $redirectWriteStartedAt = microtime(true);
            try {
                if (!$service->recordRedirect(
                    (int)$link['id'],
                    gmdate('c'),
                    max(1, (int)($config['redirect_retry_attempts'] ?? 2))
                )) {
                    render_error_page(404, '短链接不存在', '这个短链接不存在、已停用、已过期或已经使用。');
                }
            } catch (Throwable $exception) {
                log_event($config, 'confirmed_link_click_failed', [
                    'slug' => $confirmationSlug,
                    'reason' => $exception instanceof PDOException && is_sqlite_busy($exception)
                        ? 'sqlite_busy' : 'write_failed',
                    'wait_ms' => max(0, (int)round((microtime(true) - $redirectWriteStartedAt) * 1000)),
                    'limited' => true,
                    'error' => limit_text($exception->getMessage(), 300),
                ]);
                header('Retry-After: 1');
                render_error_page(503, '短链接暂时不可用', '无法安全确认本次访问，请稍后重试。');
            }
            session_write_close();
            header('Cache-Control: no-store');
            header_remove('Content-Security-Policy');
            header('Location: ' . $link['target_url'], true, 303);
            exit;
        }

        if ($method === 'GET' && $path === '/' && !is_logged_in()) {
            $flash = flash();
            require dirname(__DIR__, 2) . '/templates/home.php';
            exit;
        }

    }
}
