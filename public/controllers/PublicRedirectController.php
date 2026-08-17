<?php

declare(strict_types=1);

final class PublicRedirectController
{
    public static function dispatch(
        string $method,
        string $path,
        array $config,
        LinkService $service,
    ): never {
        $slug = rawurldecode(ltrim($path, '/'));
        if (str_contains($slug, '/') || !valid_slug($slug)) {
            render_error_page(404, '短链接不存在', '这个短链接不存在、已停用、已过期或已删除。');
        }
        $link = $service->find($slug);
        if (!$link || !link_is_available($link)) {
            render_public_link_unavailable($link, $config);
        }
        $passwordProtected = link_is_password_protected($link);
        $requiresConfirmation = (int)($link['is_one_time'] ?? 0) === 1
            && (string)($link['one_time_mode'] ?? 'immediate') === 'confirm';
        if ($method === 'HEAD' && $passwordProtected) {
            render_link_password_prompt($link);
        }
        if ($passwordProtected) {
            configure_session($config);
            if (!session_start()) {
                throw new RuntimeException('Cannot start a protected-link session.');
            }
            if (!consume_link_unlock_grant($link, $requiresConfirmation)) {
                render_link_password_prompt($link);
            }
            if (!$requiresConfirmation) {
                session_write_close();
            }
        }
        $isLimitedLink = (int)($link['is_one_time'] ?? 0) === 1
            || ($link['max_clicks'] ?? null) !== null;
        if ($method === 'HEAD' && $isLimitedLink) {
            header('Cache-Control: no-store');
            method_not_allowed(['GET']);
        }
        if ($requiresConfirmation) {
            header('Cache-Control: no-store');
            header('Content-Type: text/html; charset=UTF-8');
            if ($method === 'HEAD') {
                exit;
            }
            if (session_status() !== PHP_SESSION_ACTIVE) {
                configure_session($config);
                if (!session_start()) {
                    throw new RuntimeException('Cannot start a confirmation session.');
                }
            }
            $targetParts = parse_url((string)$link['target_url']);
            $targetHost = is_array($targetParts) ? (string)($targetParts['host'] ?? '') : '';
            $targetScheme = is_array($targetParts) ? strtolower((string)($targetParts['scheme'] ?? '')) : '';
            if ($targetHost === '' || !in_array($targetScheme, ['http', 'https'], true)) {
                render_error_page(404, '短链接不存在', '这个短链接的目标地址无效。');
            }
            $targetDisplayHost = str_contains($targetHost, ':') ? '[' . $targetHost . ']' : $targetHost;
            $targetOrigin = $targetScheme . '://' . $targetDisplayHost
                . (isset($targetParts['port']) ? ':' . (int)$targetParts['port'] : '');
            header("Content-Security-Policy: default-src 'self'; base-uri 'self'; form-action 'self' {$targetOrigin}; frame-ancestors 'none'; object-src 'none'; img-src 'self'; style-src 'self'; script-src 'self'");
            require dirname(__DIR__, 2) . '/templates/confirm_access.php';
            exit;
        }
        if ($method === 'GET' || $isLimitedLink) {
            $redirectWriteStartedAt = microtime(true);
            try {
                if (!$service->recordRedirect(
                    (int)$link['id'],
                    gmdate('c'),
                    max(1, (int)($config['redirect_retry_attempts'] ?? 2))
                )) {
                    render_public_link_unavailable($service->find($slug), $config);
                }
            } catch (Throwable $exception) {
                if ($isLimitedLink) {
                    log_event($config, 'limited_link_click_failed', [
                        'slug' => $slug,
                        'reason' => $exception instanceof PDOException && is_sqlite_busy($exception)
                            ? 'sqlite_busy' : 'write_failed',
                        'wait_ms' => max(0, (int)round((microtime(true) - $redirectWriteStartedAt) * 1000)),
                        'limited' => true,
                        'error' => limit_text($exception->getMessage(), 300),
                    ]);
                    header('Retry-After: 1');
                    render_error_page(503, '短链接暂时不可用', '无法安全确认本次访问，请稍后重试。');
                }
                // Unconstrained click statistics are best-effort; lock contention must not block redirects.
                log_event($config, 'click_update_failed', [
                    'slug' => $slug,
                    'policy' => 'redirect_anyway',
                    'error' => limit_text($exception->getMessage(), 300),
                ]);
            }
        }
        header('Cache-Control: no-store');
        header('Location: ' . $link['target_url'], true, 302);
        exit;
    }
}
