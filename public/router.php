<?php

declare(strict_types=1);

$path = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
$file = __DIR__ . $path;

$analyticsLogPath = getenv('LINKVAULT_ANALYTICS_LOG_PATH') ?: dirname(__DIR__) . '/data/analytics-access.log';
$analyticsHandle = @fopen($analyticsLogPath, 'ab');
if (is_resource($analyticsHandle)) {
    fclose($analyticsHandle);
}

$method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
$analyticsLoggable = preg_match('#^/[A-Za-z0-9_-]{3,64}$#D', $path) === 1
    && in_array($method, ['GET', 'HEAD'], true);
$analyticsLoggable = $analyticsLoggable || (
    $method === 'POST'
    && preg_match('#^/[A-Za-z0-9_-]{3,64}/confirm$#D', $path) === 1
);
if ($analyticsLoggable) {
    register_shutdown_function(static function () use ($analyticsLogPath, $method, $path): void {
        $referrerDomain = '';
        $referrer = (string)($_SERVER['HTTP_REFERER'] ?? '');
        if ($referrer !== '') {
            $referrerHost = parse_url($referrer, PHP_URL_HOST);
            $referrerDomain = is_string($referrerHost) ? strtolower($referrerHost) : '';
        }
        $record = json_encode([
            'time' => gmdate('c'),
            'method' => $method,
            'uri' => $path,
            'status' => http_response_code(),
            'country' => '',
            'user_agent' => (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
            'referrer_domain' => $referrerDomain,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (is_string($record)) {
            @file_put_contents($analyticsLogPath, $record . PHP_EOL, FILE_APPEND | LOCK_EX);
        }
    });
}

if ($path === '/router.php') {
    require __DIR__ . '/index.php';
    exit;
}

if ($path !== '/' && is_file($file)) {
    return false;
}

require __DIR__ . '/index.php';
