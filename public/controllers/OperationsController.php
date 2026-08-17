<?php

declare(strict_types=1);

final class OperationsController
{
    public static function dispatch(
        string $method,
        string $path,
        array $config,
    ): void {
        if ($path === '/metrics') {
            $token = (string)($config['metrics_token'] ?? '');
            if (strlen($token) < 24) {
                http_response_code(404);
                exit;
            }
            $authorization = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
            if (!str_starts_with($authorization, 'Bearer ')
                || !hash_equals($token, substr($authorization, 7))) {
                http_response_code(401);
                header('WWW-Authenticate: Bearer realm="LinkVault metrics"');
                header('Cache-Control: no-store');
                exit;
            }
            $pdo = database($config, max(1, (int)($config['health_busy_timeout_ms'] ?? 100)));
            $status = (new SystemStatus($pdo, $config))->collect();
            header('Content-Type: text/plain; version=0.0.4; charset=UTF-8');
            header('Cache-Control: no-store');
            if ($method !== 'HEAD') {
                echo PrometheusMetrics::render($pdo, $status);
            }
            exit;
        }

        if (in_array($path, ['/livez', '/readyz', '/healthz'], true)) {
            if (!in_array($method, ['GET', 'HEAD'], true)) {
                method_not_allowed(['GET', 'HEAD']);
            }
            $checks = $path === '/livez' ? ['process' => true] : readiness_checks($config);
            if ($path === '/healthz') {
                $checks['backup_fresh'] = backup_is_fresh($config);
            }
            $healthy = !in_array(false, $checks, true);
            $release = release_metadata($config);
            http_response_code($healthy ? 200 : 503);
            header('Content-Type: application/json; charset=UTF-8');
            header('Cache-Control: no-store');
            if ($method !== 'HEAD') {
                echo json_encode(
                    ['status' => $healthy ? 'ok' : 'unhealthy', 'checks' => $checks, 'release' => $release],
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
                );
            }
            exit;
        }
    }
}
