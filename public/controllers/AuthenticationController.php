<?php

declare(strict_types=1);

final class AuthenticationController
{
    public static function dispatch(
        string $method,
        string $path,
        bool $isPublicUnlock,
        ?string $unlockSlug,
        PDO $pdo,
        array $config,
        LinkService $service,
        ?AdminSecurityService $adminSecurityService,
        bool $totpEnabled,
        ApiTokenService $apiTokenService,
    ): void {
        if ($isPublicUnlock) {
            require_public_csrf();
            $link = $service->find((string)$unlockSlug);
            if (!$link || !link_is_available($link)) {
                render_public_link_unavailable($link, $config);
            }
            if (!link_is_password_protected($link)) {
                render_error_page(404, '短链接不存在', '这个短链接不存在、已停用、已过期或已删除。');
            }

            $linkId = (int)$link['id'];
            $clientIdentifierHash = link_unlock_client_identifier($config);
            try {
                $attempt = reserve_link_unlock_attempt($pdo, $linkId, $clientIdentifierHash, $config);
            } catch (Throwable $exception) {
                log_event($config, 'link_unlock_throttle_error', [
                    'link_id' => $linkId,
                    'error' => limit_text($exception->getMessage(), 300),
                ]);
                header('Retry-After: 1');
                render_error_page(503, '验证暂时不可用', '无法安全验证访问密码，请稍后重试。');
            }
            if ($attempt['blocked']) {
                audit_event($pdo, $config, 'public', 'link_password_unlock', 'failure', 'link', (string)$linkId, [
                    'reason' => 'rate_limited',
                    'retry_after_seconds' => $attempt['retry_after_seconds'],
                ]);
                header('Retry-After: ' . $attempt['retry_after_seconds']);
                render_link_password_prompt($link, '尝试次数过多，请稍后重试。', 429);
            }

            $submittedPassword = is_string($_POST['password'] ?? null) ? (string)$_POST['password'] : '';
            $passwordValid = strlen($submittedPassword) <= 1024
                && !str_contains($submittedPassword, "\0")
                && password_verify($submittedPassword, (string)$link['access_password_hash']);
            if (!$passwordValid) {
                audit_event($pdo, $config, 'public', 'link_password_unlock', 'failure', 'link', (string)$linkId, [
                    'reason' => $attempt['retry_after_seconds'] > 0 ? 'rate_limited' : 'invalid_password',
                    'failures' => $attempt['failures'],
                ]);
                if ($attempt['retry_after_seconds'] > 0) {
                    header('Retry-After: ' . $attempt['retry_after_seconds']);
                    render_link_password_prompt($link, '尝试次数过多，请稍后重试。', 429);
                }
                render_link_password_prompt($link, '访问密码不正确。', 401);
            }

            try {
                clear_link_unlock_failures($pdo, $linkId, $clientIdentifierHash);
            } catch (Throwable $exception) {
                log_event($config, 'link_unlock_throttle_error', [
                    'link_id' => $linkId,
                    'error' => limit_text($exception->getMessage(), 300),
                ]);
                header('Retry-After: 1');
                render_error_page(503, '验证暂时不可用', '无法安全完成访问验证，请稍后重试。');
            }
            if (!session_regenerate_id(true)) {
                throw new RuntimeException('Cannot regenerate the public session identifier.');
            }
            set_link_unlock_grant($link, $config);
            audit_event($pdo, $config, 'public', 'link_password_unlock', 'success', 'link', (string)$linkId);
            session_write_close();
            header('Cache-Control: no-store');
            header('Location: ' . app_path('/' . rawurlencode((string)$link['slug'])), true, 303);
            exit;
        }

        if ($adminSecurityService === null) {
            return;
        }

        if ($method === 'POST' && $path === '/login') {
            require_csrf();
            $password = $_POST['password'] ?? '';
            $password = is_string($password) ? $password : '';
            $secondFactor = is_string($_POST['second_factor'] ?? null) ? (string)$_POST['second_factor'] : '';
            $expected = (string)$config['admin_password'];
            $ip = client_ip($config);
            $privacySafeIp = privacy_safe_ip($ip);
            $sessionLockRemaining = session_login_lock_remaining($config);
            if ($sessionLockRemaining > 0) {
                log_event($config, 'login_blocked', [
                    'ip' => $privacySafeIp,
                    'scope' => 'session',
                    'retry_after_seconds' => $sessionLockRemaining,
                ]);
                flash('登录尝试过多，请稍后再试。', 'error');
                redirect_to(app_path('/login'));
            }

            try {
                $ipAttempt = reserve_ip_login_attempt($pdo, $ip, $config);
            } catch (Throwable $exception) {
                log_event($config, 'login_throttle_error', ['ip' => $privacySafeIp, 'error' => limit_text($exception->getMessage(), 300)]);
                flash('登录暂时不可用，请稍后重试。', 'error');
                redirect_to(app_path('/login'));
            }
            if ($ipAttempt['blocked']) {
                log_event($config, 'login_blocked', [
                    'ip' => $privacySafeIp,
                    'scope' => 'ip',
                    'retry_after_seconds' => $ipAttempt['retry_after_seconds'],
                ]);
                flash('登录尝试过多，请稍后再试。', 'error');
                redirect_to(app_path('/login'));
            }

            $sessionAttempt = reserve_session_login_attempt($config);
            $limits = login_limit_config($config);
            $failures = max((int)$ipAttempt['failures'], (int)$sessionAttempt['failures']);
            try {
                prune_login_failures($pdo, $config);
            } catch (Throwable $exception) {
                log_event($config, 'login_throttle_prune_error', ['error' => limit_text($exception->getMessage(), 300)]);
            }

            $secondFactorMethod = 'none';
            $credentialsValid = hash_equals($expected, $password);
            if ($credentialsValid && $totpEnabled) {
                try {
                    $verifiedMethod = $adminSecurityService->verifyLogin($secondFactor);
                    $credentialsValid = $verifiedMethod !== null;
                    $secondFactorMethod = $verifiedMethod ?? 'invalid';
                } catch (Throwable $exception) {
                    $credentialsValid = false;
                    $secondFactorMethod = 'unavailable';
                    log_event($config, 'second_factor_verification_failed', [
                        'error' => limit_text($exception->getMessage(), 300),
                    ]);
                }
            }

            if ($credentialsValid) {
                try {
                    clear_login_failures($pdo, $ip);
                } catch (Throwable $exception) {
                    log_event($config, 'login_throttle_clear_error', ['ip' => $privacySafeIp, 'error' => limit_text($exception->getMessage(), 300)]);
                }
                $returnPath = pending_login_return_path();
                $_SESSION = [];
                session_regenerate_id(true);
                $_SESSION['admin_logged_in'] = true;
                $_SESSION['auth_started_at'] = time();
                $_SESSION['auth_last_activity_at'] = time();
                $_SESSION['auth_second_factor_method'] = $secondFactorMethod;
                audit_event($pdo, $config, 'admin', 'login', 'success', null, null, [
                    'second_factor' => $secondFactorMethod,
                ]);
                flash('已登录。');
                redirect_to($returnPath);
            } else {
                log_event($config, 'login_failed', [
                    'ip' => $privacySafeIp,
                    'failures' => $failures,
                    'locked' => $failures >= $limits['max_attempts'],
                ]);
                flash(
                    $failures >= $limits['max_attempts']
                        ? '登录尝试过多，请稍后再试。'
                        : ($totpEnabled ? '密码、动态口令或恢复码错误。' : '密码错误。'),
                    'error'
                );
            }
            redirect_to(app_path('/login'));
        }

        if ($method === 'POST' && $path === '/logout') {
            require_login();
            require_csrf();
            audit_event($pdo, $config, 'admin', 'logout', 'success');
            destroy_session();
            redirect_to(app_path('/'));
        }

        if ($method === 'POST' && $path === '/security/totp/setup') {
            require_login();
            require_csrf();
            $password = is_string($_POST['password'] ?? null) ? (string)$_POST['password'] : '';
            try {
                if (!hash_equals((string)$config['admin_password'], $password)) {
                    throw new InvalidArgumentException('Invalid administrator password.');
                }
                $secret = $adminSecurityService->generateSecret();
                $_SESSION['totp_setup'] = [
                    'secret' => $secret,
                    'expires_at' => time() + 600,
                ];
                audit_event($pdo, $config, 'admin', 'totp_setup_started', 'success', 'admin_security', '1');
                flash('请用验证器扫描二维码，并输入当前 6 位动态口令完成启用。');
            } catch (Throwable $exception) {
                audit_event($pdo, $config, 'admin', 'totp_setup_started', 'failure', 'admin_security', '1', [
                    'reason' => limit_text($exception->getMessage(), 200),
                ]);
                flash(
                    $adminSecurityService->isAvailable()
                        ? '管理员密码错误，或 TOTP 已经启用。'
                        : 'TOTP 不可用，请先配置 LINKVAULT_SECURITY_KEY 并确认 OpenSSL 可用。',
                    'error'
                );
            }
            redirect_to(list_path('', 'active', 1, 'all', 'created_desc', '', false, 0, 'security'));
        }

        if ($method === 'POST' && $path === '/security/totp/enable') {
            require_login();
            require_csrf();
            $setup = $_SESSION['totp_setup'] ?? null;
            $code = is_string($_POST['code'] ?? null) ? (string)$_POST['code'] : '';
            try {
                if (!is_array($setup) || !is_string($setup['secret'] ?? null)
                    || time() > (int)($setup['expires_at'] ?? 0)) {
                    throw new RuntimeException('TOTP setup expired.');
                }
                $recoveryCodes = $adminSecurityService->enable($setup['secret'], $code);
                unset($_SESSION['totp_setup']);
                audit_event($pdo, $config, 'admin', 'totp_enable', 'success', 'admin_security', '1', [
                    'before' => ['enabled' => false],
                    'after' => ['enabled' => true, 'recovery_codes_remaining' => count($recoveryCodes)],
                ]);
                flash('TOTP 已启用。恢复码只显示这一次，请离线保存。', 'ok', [
                    'recovery_codes' => $recoveryCodes,
                ]);
            } catch (Throwable $exception) {
                audit_event($pdo, $config, 'admin', 'totp_enable', 'failure', 'admin_security', '1', [
                    'reason' => limit_text($exception->getMessage(), 200),
                ]);
                flash('动态口令无效或设置已过期，请重试。', 'error');
            }
            redirect_to(list_path('', 'active', 1, 'all', 'created_desc', '', false, 0, 'security'));
        }

        if ($method === 'POST' && $path === '/security/totp/cancel') {
            require_login();
            require_csrf();
            unset($_SESSION['totp_setup']);
            flash('已取消 TOTP 设置。');
            redirect_to(list_path('', 'active', 1, 'all', 'created_desc', '', false, 0, 'security'));
        }

        if ($method === 'POST' && $path === '/security/totp/disable') {
            require_login();
            require_csrf();
            $password = is_string($_POST['password'] ?? null) ? (string)$_POST['password'] : '';
            $credential = is_string($_POST['second_factor'] ?? null) ? (string)$_POST['second_factor'] : '';
            $disabled = hash_equals((string)$config['admin_password'], $password)
                && $adminSecurityService->disable($credential);
            audit_event($pdo, $config, 'admin', 'totp_disable', $disabled ? 'success' : 'failure', 'admin_security', '1', [
                'before' => ['enabled' => true],
                'after' => ['enabled' => !$disabled],
            ]);
            flash(
                $disabled ? 'TOTP 已停用，原恢复码同时作废。' : '密码、动态口令或恢复码错误。',
                $disabled ? 'ok' : 'error'
            );
            redirect_to(list_path('', 'active', 1, 'all', 'created_desc', '', false, 0, 'security'));
        }

        if ($method === 'POST' && $path === '/security/recovery-codes/regenerate') {
            require_login();
            require_csrf();
            $password = is_string($_POST['password'] ?? null) ? (string)$_POST['password'] : '';
            $credential = is_string($_POST['second_factor'] ?? null) ? (string)$_POST['second_factor'] : '';
            $recoveryCodes = hash_equals((string)$config['admin_password'], $password)
                ? $adminSecurityService->regenerateRecoveryCodes($credential) : null;
            audit_event($pdo, $config, 'admin', 'recovery_codes_regenerate', $recoveryCodes ? 'success' : 'failure', 'admin_security', '1', [
                'count' => is_array($recoveryCodes) ? count($recoveryCodes) : 0,
            ]);
            flash(
                $recoveryCodes ? '恢复码已重置，旧恢复码全部作废。' : '密码、动态口令或恢复码错误。',
                $recoveryCodes ? 'ok' : 'error',
                $recoveryCodes ? ['recovery_codes' => $recoveryCodes] : []
            );
            redirect_to(list_path('', 'active', 1, 'all', 'created_desc', '', false, 0, 'security'));
        }

        if ($method === 'POST' && $path === '/api-tokens/create') {
            require_login();
            require_csrf();
            $name = trim((string)($_POST['name'] ?? ''));
            $scopes = is_array($_POST['scopes'] ?? null) ? $_POST['scopes'] : ['links:create'];
            $quotaRequestsInput = trim((string)($_POST['quota_requests'] ?? ''));
            $quotaWindowInput = trim((string)($_POST['quota_window_seconds'] ?? ''));
            $quotaRequests = $quotaRequestsInput === '' ? null
                : (ctype_digit($quotaRequestsInput) ? (int)$quotaRequestsInput : 0);
            $quotaWindow = $quotaWindowInput === '' ? null
                : (ctype_digit($quotaWindowInput) ? (int)$quotaWindowInput : 0);
            $allowedCidrs = trim((string)($_POST['allowed_cidrs'] ?? ''));
            [$expiresValid, $expiresAt] = normalize_expiration(
                trim((string)($_POST['expires_at'] ?? '')),
                $_POST['expires_at_offset'] ?? null
            );
            try {
                if (!$expiresValid) {
                    throw new InvalidArgumentException('Invalid expiration.');
                }
                $createdToken = $apiTokenService->create(
                    $name,
                    $expiresAt,
                    $scopes,
                    $quotaRequests,
                    $quotaWindow,
                    $allowedCidrs
                );
                audit_event($pdo, $config, 'admin', 'api_token_create', 'success', 'api_token', (string)$createdToken['id'], [
                    'name' => $name,
                    'prefix' => $createdToken['prefix'],
                    'expires_at' => $expiresAt,
                    'scopes' => $createdToken['scopes'],
                    'quota_requests' => $quotaRequests,
                    'quota_window_seconds' => $quotaWindow,
                    'cidr_restricted' => $allowedCidrs !== '',
                ]);
                flash('API Token 已生成。明文只显示这一次，请立即复制。', 'ok', [
                    'api_token' => $createdToken['token'],
                ]);
            } catch (InvalidArgumentException) {
                audit_event($pdo, $config, 'admin', 'api_token_create', 'failure', 'api_token', null, [
                    'name' => limit_text($name, 60),
                ]);
                flash('Token 名称、作用域、配额、CIDR 或失效时间无效。', 'error');
            }
            redirect_to(list_path('', 'active', 1, 'all', 'created_desc', '', false, 0, 'api'));
        }

        if ($method === 'POST' && $path === '/api-tokens/rotate') {
            require_login();
            require_csrf();
            $tokenId = max(0, (int)($_POST['id'] ?? 0));
            [$expiresValid, $expiresAt] = normalize_expiration(
                trim((string)($_POST['expires_at'] ?? '')),
                $_POST['expires_at_offset'] ?? null
            );
            $overlapMinutesInput = trim((string)($_POST['overlap_minutes'] ?? ''));
            $defaultOverlapSeconds = max(60, (int)($config['api_token_rotation_overlap_seconds'] ?? 900));
            $maxOverlapSeconds = max(60, min(86400, (int)($config['api_token_rotation_max_overlap_seconds'] ?? 86400)));
            $overlapSeconds = $overlapMinutesInput === ''
                ? min($defaultOverlapSeconds, $maxOverlapSeconds)
                : (ctype_digit($overlapMinutesInput) ? (int)$overlapMinutesInput * 60 : 0);
            try {
                if ($tokenId <= 0 || !$expiresValid || $overlapSeconds < 60 || $overlapSeconds > $maxOverlapSeconds) {
                    throw new InvalidArgumentException('Invalid rotation parameters.');
                }
                $rotatedToken = $apiTokenService->rotate($tokenId, $expiresAt, $overlapSeconds);
                if ($rotatedToken === null) {
                    audit_event($pdo, $config, 'admin', 'api_token_rotate', 'failure', 'api_token', (string)$tokenId);
                    flash('Token 不存在或已吊销，无法轮换。', 'error');
                } else {
                    audit_event($pdo, $config, 'admin', 'api_token_rotate', 'success', 'api_token', (string)$tokenId, [
                        'replacement_id' => $rotatedToken['id'],
                        'prefix' => $rotatedToken['prefix'],
                        'expires_at' => $expiresAt,
                        'old_token_expires_at' => $rotatedToken['rotation_expires_at'],
                        'overlap_seconds' => $overlapSeconds,
                    ]);
                    flash('Token 已轮换，新旧 Token 将短期并行；新明文只显示这一次。', 'ok', [
                        'api_token' => $rotatedToken['token'],
                    ]);
                }
            } catch (InvalidArgumentException) {
                audit_event($pdo, $config, 'admin', 'api_token_rotate', 'failure', 'api_token', (string)$tokenId);
                flash('失效时间或并行窗口无效。', 'error');
            }
            redirect_to(list_path('', 'active', 1, 'all', 'created_desc', '', false, 0, 'api'));
        }

        if ($method === 'POST' && $path === '/api-tokens/revoke') {
            require_login();
            require_csrf();
            $tokenId = max(0, (int)($_POST['id'] ?? 0));
            $revoked = $tokenId > 0 && $apiTokenService->revoke($tokenId);
            audit_event($pdo, $config, 'admin', 'api_token_revoke', $revoked ? 'success' : 'failure', 'api_token', (string)$tokenId);
            flash($revoked ? 'API Token 已吊销。' : 'Token 不存在或已经吊销。', $revoked ? 'ok' : 'error');
            redirect_to(list_path('', 'active', 1, 'all', 'created_desc', '', false, 0, 'api'));
        }

        if ($method === 'POST' && $path === '/api-tokens/alerts/clear') {
            require_login();
            require_csrf();
            $cleared = $apiTokenService->clearAlerts();
            audit_event($pdo, $config, 'admin', 'api_token_alerts_clear', 'success', 'api_token_alert', null, [
                'cleared' => $cleared,
            ]);
            flash('已确认并清除 ' . $cleared . ' 条 Token 异常告警。');
            redirect_to(list_path('', 'active', 1, 'all', 'created_desc', '', false, 0, 'api'));
        }

        if ($method === 'GET' && $path === '/login') {
            if (is_logged_in()) {
                redirect_to(app_path('/'));
            }
            $flash = flash();
            require dirname(__DIR__, 2) . '/templates/dashboard.php';
            exit;
        }
    }
}
