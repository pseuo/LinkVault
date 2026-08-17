<?php

declare(strict_types=1);

final class MaintenanceController
{
    public static function dispatch(string $method, string $path, PDO $pdo, array $config, LinkService $service): void
    {
        if ($method !== 'POST' || !in_array($path, ['/maintenance/recheck', '/maintenance/repair'], true)) {
            return;
        }
        require_login();
        require_csrf();
        if ($path === '/maintenance/repair') {
            $id = positive_integer_id($_POST['id'] ?? null);
            $action = (string)($_POST['repair_action'] ?? '');
            $url = trim((string)($_POST['url'] ?? ''));
            if (in_array($action, ['target', 'fallback'], true)
                && !valid_target_url($url, max(1, (int)($config['target_url_max_length'] ?? 2048)))) {
                flash('请输入有效的 http:// 或 https:// 地址。', 'error');
                redirect_to(list_path('', 'active', 1, 'all', 'created_desc', '', false, 0, 'maintenance', 'target_health'));
            }
            try {
                $changed = $id > 0 && $service->repairTargetHealth(
                    $id,
                    $action,
                    (string)($_POST['updated_at'] ?? ''),
                    (string)($_POST['target_url_hash'] ?? ''),
                    $url === '' ? null : $url,
                    trim((string)($_POST['ignore_reason'] ?? ''))
                );
                $check = ['processed' => 0, 'healthy' => 0, 'issues' => 0];
                if ($changed && $action === 'target') {
                    $check = (new TargetHealthService($pdo, $config))->checkSelected([$id]);
                }
                audit_event($pdo, $config, 'admin', 'target_health_repair', $changed ? 'success' : 'failure', 'link', (string)$id, [
                    'action' => $action,
                    'rechecked' => $check['processed'],
                ]);
                $successMessage = $action === 'fallback'
                    ? '备用地址已更新。目标异常仍保留，便于继续处理。'
                    : '异常处理已应用' . ($check['processed'] ? '，并完成重检。' : '。');
                flash($changed ? $successMessage : '链接或检查结果已变化，请刷新后重试。', $changed ? 'ok' : 'error');
            } catch (InvalidArgumentException) {
                flash('异常处理操作无效。', 'error');
            }
            redirect_to(list_path('', 'active', 1, 'all', 'created_desc', '', false, 0, 'maintenance', 'target_health'));
        }
        try {
            $result = (new TargetHealthService($pdo, $config))->checkSelected((array)($_POST['selected'] ?? []));
            audit_event($pdo, $config, 'admin', 'target_health_manual_check', 'success', 'link', null, $result);
            flash('已重新检查 ' . $result['processed'] . ' 条链接：正常 ' . $result['healthy'] . ' 条，异常 ' . $result['issues'] . ' 条。');
        } catch (InvalidArgumentException $exception) {
            flash('请选择 1 至 50 条启用中的链接。', 'error');
        }
        redirect_to(list_path('', 'active', 1, 'all', 'created_desc', '', false, 0, 'maintenance', 'target_health'));
    }
}
