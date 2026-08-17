<?php

declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');

$config = require dirname(__DIR__) . '/config.php';
require dirname(__DIR__) . '/lib/operational_status.php';
require dirname(__DIR__) . '/lib/http_endpoint_monitor.php';

$startedAt = hrtime(true);
$probes = [];
$baseUrl = rtrim(trim((string)($config['base_url'] ?? '')), '/');
if (!filter_var($baseUrl, FILTER_VALIDATE_URL)
    || !in_array(strtolower((string)parse_url($baseUrl, PHP_URL_SCHEME)), ['http', 'https'], true)) {
    foreach ([
        ['home', '首页', '/'], ['login', '登录页', '/login'], ['api', 'API', '/api/shorten'],
        ['canary', 'Canary 短链', '/' . trim((string)($config['canary_slug'] ?? ''))],
    ] as [$id, $label, $path]) {
        $probes[] = endpoint_probe_result($id, $label, $path, 'error', null, null, '公开地址配置无效');
    }
    finish_endpoint_checks($config, $startedAt, $probes, ['LINKVAULT_BASE_URL must be a valid HTTP(S) URL.']);
}

$failures = [];
foreach (['/readyz', '/healthz'] as $path) {
    $response = endpoint_response($baseUrl . $path, 'GET');
    $ok = $response['status'] === 200;
    $probes[] = endpoint_probe_result(
        $path === '/readyz' ? 'readiness' : 'health',
        $path === '/readyz' ? '就绪探针' : '运行健康',
        $path,
        $ok ? 'ok' : 'error',
        $response['status'],
        $response['latency_ms'],
        $ok ? '响应符合预期' : '预期 HTTP 200'
    );
    if (!$ok) {
        $failures[] = $path . ' returned HTTP ' . $response['status'];
    }
}
$home = endpoint_response($baseUrl . '/', 'GET');
$homeOk = $home['status'] === 200 && str_contains($home['body'], '<h1 id="home-title">链匣 LinkVault</h1>');
$probes[] = endpoint_probe_result(
    'home', '首页', '/', $homeOk ? 'ok' : 'error', $home['status'], $home['latency_ms'],
    $homeOk ? '页面内容符合预期' : '页面状态或内容校验失败'
);
if (!$homeOk) {
    $failures[] = '/ did not return the expected public homepage';
}
$login = endpoint_response($baseUrl . '/login', 'GET');
$loginOk = $login['status'] === 200 && str_contains($login['body'], 'name="password"');
$probes[] = endpoint_probe_result(
    'login', '登录页', '/login', $loginOk ? 'ok' : 'error', $login['status'], $login['latency_ms'],
    $loginOk ? '登录表单符合预期' : '页面状态或登录表单校验失败'
);
if (!$loginOk) {
    $failures[] = '/login did not return the administrator login form';
}
if (!empty($config['canary_enabled'])) {
    $canarySlug = trim((string)($config['canary_slug'] ?? ''));
    $canaryTarget = trim((string)($config['canary_target_url'] ?? ''));
    if ($canaryTarget === '') {
        $canaryTarget = $baseUrl . '/';
    }
    if (preg_match('/^[A-Za-z0-9_-]{3,64}$/D', $canarySlug) !== 1) {
        $failures[] = 'LINKVAULT_CANARY_SLUG is invalid';
        $probes[] = endpoint_probe_result(
            'canary', 'Canary 短链', '/' . $canarySlug, 'error', null, null, '短码配置无效'
        );
    } else {
        $canary = endpoint_response($baseUrl . '/' . rawurlencode($canarySlug), 'HEAD');
        $canaryOk = $canary['status'] === 302 && $canary['location'] === $canaryTarget;
        $probes[] = endpoint_probe_result(
            'canary', 'Canary 短链', '/' . $canarySlug, $canaryOk ? 'ok' : 'error',
            $canary['status'], $canary['latency_ms'], $canaryOk ? '跳转目标符合预期' : '状态或 Location 校验失败'
        );
        if (!$canaryOk) {
            $failures[] = '/' . $canarySlug . ' canary returned HTTP ' . $canary['status']
                . ' with an unexpected Location';
        }
    }
} else {
    $probes[] = endpoint_probe_result(
        'canary', 'Canary 短链', '/' . trim((string)($config['canary_slug'] ?? '')),
        'unconfigured', null, null, '未启用 LINKVAULT_CANARY_ENABLED'
    );
}

$apiToken = (string)($config['api_token'] ?? '');
$managedApiEnabled = false;
$databasePath = (string)($config['database_path'] ?? '');
if ($databasePath !== '' && is_file($databasePath) && extension_loaded('pdo_sqlite')) {
    try {
        $pdo = new PDO('sqlite:' . $databasePath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        foreach ($pdo->query(<<<'SQL'
            SELECT scopes, expires_at, rotation_expires_at FROM api_tokens WHERE revoked_at IS NULL
        SQL) as $token) {
            $expiresAt = $token['expires_at'] ?? null;
            $rotationExpiresAt = $token['rotation_expires_at'] ?? null;
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $naturallyActive = !is_string($expiresAt) || $expiresAt === ''
                || new DateTimeImmutable($expiresAt) > $now;
            $rotationActive = !is_string($rotationExpiresAt) || $rotationExpiresAt === ''
                || new DateTimeImmutable($rotationExpiresAt) > $now;
            $scopes = array_values(array_filter(explode(' ', (string)($token['scopes'] ?? ''))));
            if ($naturallyActive && $rotationActive && in_array('links:create', $scopes, true)) {
                $managedApiEnabled = true;
                break;
            }
        }
    } catch (Throwable) {
    }
}
if ($apiToken !== '') {
    if (strlen($apiToken) < 24) {
        $failures[] = 'LINKVAULT_API_TOKEN is configured but invalid';
    }
}
$apiConfigured = strlen($apiToken) >= 24 || $managedApiEnabled;
$probeToken = 'endpoint-probe-invalid-' . bin2hex(random_bytes(8));
$api = endpoint_response($baseUrl . '/api/shorten', 'POST', [
    'Authorization: Bearer ' . $probeToken,
    'Content-Type: application/json',
], '{}');
$apiOk = $apiConfigured && $api['status'] === 401 && ($apiToken === '' || strlen($apiToken) >= 24);
$apiUnconfigured = !$apiConfigured && $apiToken === '' && $api['status'] === 503;
$apiState = $apiOk ? 'ok' : ($apiUnconfigured ? 'unconfigured' : 'error');
$apiDetail = match ($apiState) {
    'ok' => '鉴权拒绝符合预期，API 已配置',
    'unconfigured' => '尚无可用 Token，接口未启用',
    default => $api['status'] === 401 ? '兼容 Token 配置无效' : 'API 状态或鉴权校验失败',
};
$probes[] = endpoint_probe_result(
    'api', 'API', '/api/shorten', $apiState, $api['status'], $api['latency_ms'], $apiDetail
);
if (!$apiOk && !$apiUnconfigured && $apiToken === '') {
    $failures[] = '/api/shorten availability probe returned HTTP ' . $api['status'];
} elseif ($apiConfigured && $api['status'] !== 401) {
    $failures[] = '/api/shorten authentication probe returned HTTP ' . $api['status'];
}

finish_endpoint_checks($config, $startedAt, $probes, $failures);

/** @return array{id: string, label: string, path: string, status: string, http_status: ?int, latency_ms: ?int, detail: string} */
function endpoint_probe_result(
    string $id,
    string $label,
    string $path,
    string $status,
    ?int $httpStatus,
    ?int $latencyMs,
    string $detail
): array {
    return [
        'id' => $id,
        'label' => $label,
        'path' => $path === '' ? '/' : $path,
        'status' => $status,
        'http_status' => $httpStatus,
        'latency_ms' => $latencyMs,
        'detail' => $detail,
    ];
}

/** @param list<array<string, mixed>> $probes @param list<string> $failures */
function finish_endpoint_checks(array $config, int $startedAt, array $probes, array $failures): never
{
    $statusPath = trim((string)($config['synthetic_status_path'] ?? ''));
    try {
        if ($statusPath === '') {
            throw new RuntimeException('Synthetic monitor status path is empty.');
        }
        linkvault_write_json_marker($statusPath, [
            'version' => 1,
            'completed_at' => time(),
            'duration_ms' => max(0, (int)round((hrtime(true) - $startedAt) / 1_000_000)),
            'status' => $failures ? 'failure' : 'success',
            'probes' => $probes,
        ]);
    } catch (Throwable $exception) {
        $failures[] = 'cannot write synthetic monitor status: ' . $exception->getMessage();
    }

    if ($failures) {
        fwrite(STDERR, implode('; ', $failures) . PHP_EOL);
        exit(1);
    }
    fwrite(STDOUT, 'Proxy endpoint checks passed.' . PHP_EOL);
    exit(0);
}
