<?php

declare(strict_types=1);

final class DomainController
{
    public static function dispatch(
        string $method,
        string $path,
        PDO $pdo,
        array $config,
        ShortDomainService $domains,
        LinkService $links
    ): void {
        $currentDomain = current_short_domain();
        if (is_array($currentDomain) && $method === 'GET' && $path === '/') {
            $brand = $currentDomain;
            require dirname(__DIR__, 2) . '/templates/domain_home.php';
            exit;
        }

        if ($method !== 'POST' || !str_starts_with($path, '/domains/')) {
            return;
        }
        require_login();
        require_csrf();
        $id = max(0, (int)($_POST['id'] ?? 0));
        try {
            if ($path === '/domains/create') {
                $id = $domains->create(
                    (string)($_POST['hostname'] ?? ''),
                    (string)($_POST['brand_name'] ?? ''),
                    (string)($_POST['brand_tagline'] ?? ''),
                    (string)($_POST['brand_theme'] ?? 'graphite'),
                    array_key_exists('brand_color', $_POST) ? (string)$_POST['brand_color'] : null,
                    array_key_exists('logo_url', $_POST) ? (string)$_POST['logo_url'] : null,
                    array_key_exists('favicon_url', $_POST) ? (string)$_POST['favicon_url'] : null,
                    array_key_exists('invalid_page_title', $_POST) ? (string)$_POST['invalid_page_title'] : null,
                    array_key_exists('invalid_page_message', $_POST) ? (string)$_POST['invalid_page_message'] : null
                );
                audit_event($pdo, $config, 'admin', 'short_domain_create', 'success', 'short_domain', (string)$id);
                flash('短链域名已添加。请配置 TXT 记录后执行验证。');
            } elseif ($path === '/domains/verify') {
                $verified = $id > 0 && $domains->verify($id);
                audit_event($pdo, $config, 'admin', 'short_domain_verify', $verified ? 'success' : 'failure', 'short_domain', (string)$id);
                flash($verified ? '域名验证成功并已启用。' : '未找到匹配的 TXT 验证记录。', $verified ? 'ok' : 'error');
            } elseif ($path === '/domains/update') {
                $updated = $id > 0 && $domains->updateBrand(
                    $id,
                    (string)($_POST['brand_name'] ?? ''),
                    (string)($_POST['brand_tagline'] ?? ''),
                    (string)($_POST['brand_theme'] ?? 'graphite'),
                    array_key_exists('brand_color', $_POST) ? (string)$_POST['brand_color'] : null,
                    array_key_exists('logo_url', $_POST) ? (string)$_POST['logo_url'] : null,
                    array_key_exists('favicon_url', $_POST) ? (string)$_POST['favicon_url'] : null,
                    array_key_exists('invalid_page_title', $_POST) ? (string)$_POST['invalid_page_title'] : null,
                    array_key_exists('invalid_page_message', $_POST) ? (string)$_POST['invalid_page_message'] : null
                );
                audit_event($pdo, $config, 'admin', 'short_domain_brand_update', $updated ? 'success' : 'failure', 'short_domain', (string)$id);
                flash($updated ? '域名品牌配置已更新。' : '域名不存在。', $updated ? 'ok' : 'error');
            } elseif ($path === '/domains/update-appearance') {
                $updated = $id > 0 && $domains->updateAppearance(
                    $id,
                    (string)($_POST['brand_color'] ?? ''),
                    (string)($_POST['logo_url'] ?? ''),
                    (string)($_POST['favicon_url'] ?? ''),
                    (string)($_POST['invalid_page_title'] ?? ''),
                    (string)($_POST['invalid_page_message'] ?? '')
                );
                audit_event($pdo, $config, 'admin', 'short_domain_appearance_update', $updated ? 'success' : 'failure', 'short_domain', (string)$id);
                flash($updated ? '域名品牌外观与失效页已更新。' : '域名不存在。', $updated ? 'ok' : 'error');
            } elseif ($path === '/domains/toggle') {
                $enabled = (string)($_POST['enabled'] ?? '') === '1';
                $updated = $id > 0 && $domains->setEnabled($id, $enabled);
                audit_event($pdo, $config, 'admin', 'short_domain_toggle', $updated ? 'success' : 'failure', 'short_domain', (string)$id, ['enabled' => $enabled]);
                flash($updated ? ($enabled ? '域名已启用。' : '域名已停用。') : '域名尚未验证或不存在。', $updated ? 'ok' : 'error');
            } elseif ($path === '/domains/delete') {
                $result = $id > 0 ? $domains->deleteUnused($id) : 'not_found';
                audit_event(
                    $pdo,
                    $config,
                    'admin',
                    'short_domain_delete',
                    $result === 'deleted' ? 'success' : 'failure',
                    'short_domain',
                    (string)$id,
                    ['reason' => $result]
                );
                flash(
                    match ($result) {
                        'deleted' => '短链域名已删除。',
                        'in_use' => '该域名仍有链接使用，不能删除。请先迁移或删除这些链接。',
                        default => '域名不存在。',
                    },
                    $result === 'deleted' ? 'ok' : 'error'
                );
            } elseif (in_array($path, [
                '/domains/retire/pause', '/domains/retire/resume',
                '/domains/retire/cancel', '/domains/retire/retry',
            ], true)) {
                $action = basename($path);
                $updated = $id > 0 && $links->controlShortDomainRetirement($id, $action);
                audit_event(
                    $pdo,
                    $config,
                    'admin',
                    'short_domain_retire_' . $action,
                    $updated ? 'success' : 'failure',
                    'short_domain_retirement_job',
                    (string)$id
                );
                $labels = ['pause' => '暂停', 'resume' => '继续', 'cancel' => '取消', 'retry' => '重试'];
                flash(
                    $updated ? '域名退役任务已' . $labels[$action] . '。' : '任务状态已变化，操作未执行。',
                    $updated ? 'ok' : 'error'
                );
            } elseif ($path === '/domains/retire') {
                $destinationInput = trim((string)($_POST['destination_id'] ?? ''));
                $destinationId = $destinationInput === '' ? null : max(0, (int)$destinationInput);
                $result = $id > 0
                    ? $links->retireShortDomain($id, $destinationId)
                    : ['status' => 'not_found', 'migrated' => 0];
                audit_event(
                    $pdo,
                    $config,
                    'admin',
                    'short_domain_retire',
                    $result['status'] === 'queued' ? 'success' : 'failure',
                    'short_domain',
                    (string)$id,
                    [
                        'destination_id' => $destinationId,
                        'migrated' => $result['migrated'],
                        'reason' => $result['status'],
                    ]
                );
                flash(
                    match ($result['status']) {
                        'queued' => '域名退役任务已排队，将在后台分批迁移链接。',
                        'same_domain' => '迁移目标不能是当前域名。',
                        'invalid_destination' => '迁移目标域名不存在、未验证或已停用。',
                        default => '域名不存在。',
                    },
                    $result['status'] === 'queued' ? 'ok' : 'error'
                );
            }
        } catch (PDOException $exception) {
            if (str_contains(strtolower($exception->getMessage()), 'unique')) {
                flash('该短链域名已经存在。', 'error');
            } else {
                throw $exception;
            }
        } catch (InvalidArgumentException) {
            flash('域名或品牌配置无效。', 'error');
        }
        redirect_to(list_path('', 'active', 1, 'all', 'created_desc', '', false, 0, 'domains'));
    }
}
