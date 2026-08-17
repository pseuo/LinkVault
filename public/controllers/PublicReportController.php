<?php

declare(strict_types=1);

final class PublicReportController
{
    public static function dispatch(string $method, PDO $pdo, array $config, P2Service $service): never
    {
        $result = null;
        $error = null;
        if ($method === 'POST') {
            require_public_csrf();
            if (trim((string)($_POST['website'] ?? '')) !== '') {
                http_response_code(202);
                require dirname(__DIR__, 2) . '/templates/public_report.php';
                exit;
            }
            $clientIp = client_ip($config);
            try {
                $quota = reserve_api_token_request(
                    $pdo,
                    'public-abuse-report:' . hash('sha256', $clientIp),
                    $config,
                    max(1, (int)($config['abuse_report_quota_requests'] ?? 5)),
                    max(60, (int)($config['abuse_report_quota_window_seconds'] ?? 3600))
                );
            } catch (Throwable $exception) {
                log_event($config, 'abuse_report_quota_error', ['error' => limit_text($exception->getMessage(), 300)]);
                header('Retry-After: 60');
                render_error_page(503, '暂时无法提交', '举报入口暂时不可用，请稍后重试。');
            }
            if (!$quota['allowed']) {
                header('Retry-After: ' . $quota['retry_after_seconds']);
                http_response_code(429);
                $error = '提交过于频繁，请稍后再试。';
            } else {
                try {
                    $reporterHash = hash('sha256', gmdate('Y-m-d') . ':' . $clientIp . ':linkvault-abuse-report');
                    $result = $service->submitReport(
                        (string)($_POST['url'] ?? ''),
                        (string)($_POST['reason'] ?? ''),
                        (string)($_POST['details'] ?? ''),
                        (string)($_POST['contact'] ?? ''),
                        $reporterHash
                    );
                    http_response_code(201);
                    log_event($config, 'abuse_report_received', ['public_id' => $result['public_id']]);
                } catch (InvalidArgumentException) {
                    http_response_code(422);
                    $error = '请检查链接、举报类型和说明内容。';
                }
            }
        }
        require dirname(__DIR__, 2) . '/templates/public_report.php';
        exit;
    }
}
