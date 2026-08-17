<?php

declare(strict_types=1);

if (!isset($config) || !is_array($config)) {
    $config = require dirname(__DIR__) . '/config.php';
}
require dirname(__DIR__) . '/lib/database_schema.php';
require_once dirname(__DIR__) . '/lib/maintenance_policy.php';
require_once dirname(__DIR__) . '/lib/release_metadata.php';
require_once dirname(__DIR__) . '/lib/operational_status.php';

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');

function is_trusted_proxy(array $config): bool
{
    $remoteAddress = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $trustedProxies = $config['trusted_proxies'] ?? [];

    return $remoteAddress !== '' && is_array($trustedProxies)
        && in_array($remoteAddress, $trustedProxies, true);
}

function is_https_request(array $config): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    if (!is_trusted_proxy($config)) {
        return false;
    }

    $protocols = array_map(
        static fn (string $value): string => strtolower(trim($value)),
        explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))
    );

    return $protocols
        && !array_filter($protocols, static fn (string $value): bool => !in_array($value, ['http', 'https'], true))
        && end($protocols) === 'https';
}

function send_security_headers(array $config, string $requestId): void
{
    header('X-Request-ID: ' . $requestId);
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
    header("Content-Security-Policy: default-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'; object-src 'none'; img-src 'self' https:; style-src 'self'; script-src 'self'");
    if (is_https_request($config)) {
        try {
            $configured = configured_base_url($config);
        } catch (Throwable) {
            $configured = null;
        }
        $requested = request_authority((string)($_SERVER['HTTP_HOST'] ?? ''));
        $canonicalRequest = is_array($configured) && is_array($requested)
            && $configured['host'] === $requested['host'];
        header('Strict-Transport-Security: max-age=31536000' . ($canonicalRequest ? '; includeSubDomains' : ''));
    }
}

function is_strong_admin_password(string $password): bool
{
    if (strlen($password) < 12 || strlen($password) > 1024) {
        return false;
    }

    $classes = 0;
    $classes += preg_match('/[a-z]/', $password) === 1 ? 1 : 0;
    $classes += preg_match('/[A-Z]/', $password) === 1 ? 1 : 0;
    $classes += preg_match('/[0-9]/', $password) === 1 ? 1 : 0;
    $classes += preg_match('/[^A-Za-z0-9]/', $password) === 1 ? 1 : 0;

    return $classes >= 3;
}

function require_secure_configuration(array $config): void
{
    $password = $config['admin_password'] ?? null;
    if (is_string($password) && is_strong_admin_password($password)) {
        return;
    }

    log_event($config, 'insecure_configuration');
    audit_event(null, $config, 'system', 'configuration_error', 'failure', 'configuration', 'admin_password', [
        'reason' => 'weak_or_missing_admin_password',
    ]);
    render_error_page(503, '服务尚未完成配置', '请配置强管理密码后再启动服务。');
}

function configure_session(array $config): void
{
    $absoluteTimeout = max(300, (int)($config['session_absolute_timeout'] ?? 28800));
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.gc_maxlifetime', (string)$absoluteTimeout);
    session_name('linkvault_session');
    session_set_cookie_params([
        'lifetime' => $absoluteTimeout,
        'path' => '/',
        'secure' => is_https_request($config),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csv_safe_cell(string $value): string
{
    return preg_match('/^[\x00-\x20\x7F=+\-@\p{Z}＝＋－＠]/u', $value) === 1
        ? "'" . $value
        : $value;
}

function render_error_page(int $status, string $title, string $message): never
{
    global $requestId;

    if (str_starts_with(request_path(), '/api/')) {
        $code = match ($status) {
            405 => 'method_not_allowed',
            421 => 'misdirected_request',
            503 => 'service_unavailable',
            default => 'internal_error',
        };
        api_error($status, $code);
    }

    http_response_code($status);
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
        exit;
    }

    $safeStatus = e((string)$status);
    $safeTitle = e($title);
    $safeMessage = e($message);
    $safeRequestId = e((string)$requestId);
    require dirname(__DIR__) . '/templates/error.php';
    exit;
}

function json_response(int $status, array $payload): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    echo json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    );
    exit;
}

function json_response_raw(int $status, string $payload): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    echo $payload;
    exit;
}

function api_error(int $status, string $code, ?string $message = null): never
{
    global $requestId;

    $messages = [
        'api_token_not_configured' => 'The API token is not configured.',
        'invalid_token' => 'The bearer token is invalid.',
        'insufficient_scope' => 'The bearer token does not grant the required scope.',
        'source_not_allowed' => 'The client address is not allowed for this bearer token.',
        'invalid_json' => 'The request body must be valid JSON.',
        'invalid_parameters' => 'One or more request parameters are invalid.',
        'invalid_idempotency_key' => 'The Idempotency-Key header is invalid.',
        'idempotency_conflict' => 'This Idempotency-Key was already used with a different payload.',
        'signature_required' => 'A valid timestamped HMAC signature is required.',
        'unsupported_media_type' => 'The request Content-Type must be application/json.',
        'request_too_large' => 'The request body is too large.',
        'rate_limited' => 'Too many API requests. Retry after the indicated delay.',
        'slug_exists' => 'The requested short code already exists.',
        'not_found' => 'The requested API resource was not found.',
        'precondition_required' => 'This operation requires an If-Match header from the latest resource response.',
        'precondition_failed' => 'The resource changed after it was read. Fetch it again before retrying.',
        'link_requires_password_reset' => 'This link must have its access password reset in the management interface before it can be edited.',
        'method_not_allowed' => 'This endpoint does not accept the requested method.',
        'misdirected_request' => 'The request host does not match this service.',
        'service_unavailable' => 'The service is temporarily unavailable.',
        'internal_error' => 'The request could not be completed.',
    ];
    json_response($status, [
        'error' => [
            'code' => $code,
            'message' => $message ?? ($messages[$code] ?? $messages['internal_error']),
            'request_id' => (string)($requestId ?? ''),
        ],
    ]);
}

function method_not_allowed(array $allowedMethods): never
{
    header('Allow: ' . implode(', ', $allowedMethods));
    render_error_page(405, '请求方法不受支持', '此地址不接受当前请求方法。');
}

function limit_text(string $value, int $length): string
{
    return function_exists('mb_substr') ? mb_substr($value, 0, $length, 'UTF-8') : substr($value, 0, $length);
}

function utc_timestamp(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
}

function text_length(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function purge_confirmation_token(int $id): string
{
    return hash_hmac('sha256', 'purge:' . $id, csrf_token());
}

function require_csrf(): void
{
    $sessionToken = $_SESSION['csrf_token'] ?? null;
    $requestToken = $_POST['csrf'] ?? null;
    if (!is_string($sessionToken) || $sessionToken === ''
        || !is_string($requestToken) || $requestToken === ''
        || !hash_equals($sessionToken, $requestToken)) {
        reset_authenticated_session('会话已过期，请重新登录。', true);
        redirect_to(app_path('/login'));
    }
}

function require_public_csrf(): void
{
    $sessionToken = $_SESSION['csrf_token'] ?? null;
    $requestToken = $_POST['csrf'] ?? null;
    if (!is_string($sessionToken) || $sessionToken === ''
        || !is_string($requestToken) || $requestToken === ''
        || !hash_equals($sessionToken, $requestToken)) {
        render_error_page(400, '确认已过期', '请重新打开短链接后再确认访问。');
    }
}

function is_logged_in(): bool
{
    return !empty($_SESSION['admin_logged_in']);
}

function require_login(): void
{
    if (is_logged_in()) {
        return;
    }
    if (empty($_SESSION['flash'])) {
        flash('请先登录。', 'error');
    }
    remember_login_return_path();
    redirect_to(app_path('/login'));
}

function reset_authenticated_session(string $message, bool $rememberReturn = false): void
{
    $returnPath = $rememberReturn ? login_return_path_for_request() : null;
    $existingReturn = $_SESSION['login_return'] ?? null;
    $_SESSION = [];
    session_regenerate_id(true);
    if (is_string($returnPath)) {
        $_SESSION['login_return'] = ['path' => $returnPath, 'expires_at' => time() + 900];
    } elseif ($rememberReturn && is_array($existingReturn)
        && is_string($existingReturn['path'] ?? null)
        && is_int($existingReturn['expires_at'] ?? null)
        && $existingReturn['expires_at'] >= time()) {
        $_SESSION['login_return'] = $existingReturn;
    }
    flash($message, 'error');
}

function positive_integer_id(mixed $value): int
{
    if (is_int($value)) {
        return $value > 0 ? $value : 0;
    }
    if (!is_string($value) || preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
        return 0;
    }
    $parsed = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return is_int($parsed) ? $parsed : 0;
}

/** @return array<string, scalar> */
function scalar_query_parameters(array $parameters): array
{
    $result = [];
    foreach ($parameters as $name => $value) {
        if (is_string($name) && is_scalar($value)) {
            $result[$name] = $value;
        }
    }
    return $result;
}

function login_return_path_for_request(): ?string
{
    $method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $path = request_path();
    if ($method === 'GET') {
        $route = fixed_routes()[$path] ?? null;
        if (!is_array($route) || $route['scope'] !== 'admin' || !in_array('GET', $route['methods'], true)
            || $path === '/login') {
            return null;
        }
        $query = http_build_query(scalar_query_parameters($_GET), '', '&', PHP_QUERY_RFC3986);
        $returnPath = app_path($path) . ($query === '' ? '' : '?' . $query);
        return strlen($returnPath) <= 4096 ? $returnPath : app_path('/');
    }
    if ($method !== 'POST' || $path === '/login' || $path === '/logout') {
        return null;
    }
    if ($path === '/edit') {
        $id = positive_integer_id($_POST['id'] ?? null);
        if ($id <= 0) {
            return app_path('/');
        }
        $return = array_intersect_key($_POST, array_flip([
            'return_q', 'return_view', 'return_page', 'return_status', 'return_sort',
            'return_tag', 'return_favorite', 'return_scroll', 'return_section', 'return_maintenance',
        ]));
        return app_path('/edit') . '?' . http_build_query(array_merge(['id' => $id], scalar_query_parameters($return)));
    }
    if (str_starts_with($path, '/api-tokens/') || str_starts_with($path, '/security/')) {
        return list_path('', 'active', 1, 'all', 'created_desc', '', false, 0, 'status');
    }
    if (str_starts_with($path, '/analytics-views/')) {
        return list_path('', 'active', 1, 'all', 'created_desc', '', false, 0, 'analytics');
    }
    if (array_key_exists('return_q', $_POST) || array_key_exists('return_section', $_POST)) {
        return returned_list_path($_POST);
    }
    return app_path('/');
}

function remember_login_return_path(): void
{
    $returnPath = login_return_path_for_request();
    if (is_string($returnPath)) {
        $_SESSION['login_return'] = ['path' => $returnPath, 'expires_at' => time() + 900];
    }
}

function pending_login_return_path(): string
{
    $return = $_SESSION['login_return'] ?? null;
    if (!is_array($return) || !is_string($return['path'] ?? null)
        || !is_int($return['expires_at'] ?? null) || $return['expires_at'] < time()) {
        unset($_SESSION['login_return']);
        return app_path('/');
    }
    return $return['path'];
}

function destroy_session(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'],
        ]);
    }
    session_destroy();
}

function enforce_session_timeouts(array $config, bool $touchActivity): void
{
    if (!is_logged_in()) {
        return;
    }

    $now = time();
    $startedAt = $_SESSION['auth_started_at'] ?? null;
    $lastActivityAt = $_SESSION['auth_last_activity_at'] ?? null;
    $idleTimeout = max(60, (int)($config['session_idle_timeout'] ?? 1800));
    $absoluteTimeout = max($idleTimeout, (int)($config['session_absolute_timeout'] ?? 28800));
    if (!is_int($startedAt) || !is_int($lastActivityAt)
        || $now - $lastActivityAt >= $idleTimeout
        || $now - $startedAt >= $absoluteTimeout) {
        reset_authenticated_session('登录会话已过期，请重新登录。', true);
        redirect_to(app_path('/login'));
    }
    if ($touchActivity) {
        $_SESSION['auth_last_activity_at'] = $now;
    }
}

function flash(?string $message = null, string $type = 'ok', array $context = []): ?array
{
    if ($message !== null) {
        $_SESSION['flash'] = array_merge($context, ['message' => $message, 'type' => $type]);
        return null;
    }
    $value = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return is_array($value) ? $value : null;
}

function redirect_to(string $path): never
{
    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
        json_response(200, ['redirect' => $path]);
    }
    header('Location: ' . $path, true, 303);
    exit;
}

function is_sqlite_busy(PDOException $exception): bool
{
    $driverCode = isset($exception->errorInfo[1]) ? (int)$exception->errorInfo[1] : 0;
    $message = strtolower($exception->getMessage());
    return in_array($driverCode, [5, 6], true)
        || str_contains($message, 'database is locked')
        || str_contains($message, 'database is busy');
}

function is_database_unavailable(Throwable $exception): bool
{
    for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
        if (!$current instanceof PDOException) {
            continue;
        }

        $driverCode = isset($current->errorInfo[1]) ? (int)$current->errorInfo[1] : 0;
        $primaryCode = $driverCode & 0xff;
        if (in_array($primaryCode, [5, 6, 7, 8, 10, 11, 13, 14, 15, 22, 26], true)) {
            return true;
        }

        $message = strtolower($current->getMessage());
        foreach ([
            'database is locked', 'database is busy', 'attempt to write a readonly database',
            'disk i/o error', 'database disk image is malformed', 'database or disk is full',
            'unable to open database file', 'file is not a database',
        ] as $indicator) {
            if (str_contains($message, $indicator)) {
                return true;
            }
        }
    }

    return false;
}

function is_slug_unique_violation(PDOException $exception): bool
{
    $sqlState = (string)($exception->errorInfo[0] ?? $exception->getCode());
    $driverCode = isset($exception->errorInfo[1]) ? (int)$exception->errorInfo[1] : 0;
    $message = strtolower($exception->getMessage());
    return ($sqlState === '23000' || in_array($driverCode, [19, 2067], true))
        && ((str_contains($message, 'unique constraint failed')
            && (str_contains($message, 'links.slug') || str_contains($message, 'link_aliases.alias')))
            || str_contains($message, 'short code is already in use'));
}

function with_sqlite_retry(callable $operation, int $maxAttempts = 3): mixed
{
    $maxAttempts = max(1, $maxAttempts);
    for ($attempt = 1; ; $attempt++) {
        try {
            return $operation();
        } catch (PDOException $exception) {
            if ($attempt >= $maxAttempts || !is_sqlite_busy($exception)) {
                throw $exception;
            }
            usleep(50000 * (2 ** ($attempt - 1)));
        }
    }
}

final class DatabaseMigrationRequired extends RuntimeException
{
}

function record_database_operation(
    array $config,
    string $sql,
    int $startedAt,
    ?PDOException $exception = null
): void {
    $durationMs = max(0, (int)round((hrtime(true) - $startedAt) / 1_000_000));
    $slowQueryMs = max(0, (int)($config['sqlite_slow_query_ms'] ?? 250));
    $locked = $exception instanceof PDOException && is_sqlite_busy($exception);
    if (!$locked && ($slowQueryMs === 0 || $durationMs < $slowQueryMs)) {
        return;
    }

    $normalized = strtolower(trim((string)preg_replace('/\s+/', ' ', $sql)));
    preg_match('/^([a-z]+)/', $normalized, $operationMatch);
    preg_match('/\b(?:from|into|update|join)\s+["`\[]?([a-z_][a-z0-9_]*)/i', $normalized, $tableMatch);
    log_event($config, $locked ? 'sqlite_lock_wait' : 'sqlite_slow_query', [
        'duration_ms' => $durationMs,
        'operation' => strtoupper((string)($operationMatch[1] ?? 'unknown')),
        'table' => (string)($tableMatch[1] ?? ''),
        'query_hash' => hash('sha256', $normalized),
        'failed' => $exception instanceof PDOException,
    ]);
}

final class LinkVaultPDOStatement extends PDOStatement
{
    protected function __construct(private readonly array $monitorConfig)
    {
    }

    #[Override]
    public function execute(?array $params = null): bool
    {
        $startedAt = hrtime(true);
        try {
            $result = parent::execute($params);
            record_database_operation($this->monitorConfig, $this->queryString, $startedAt);
            return $result;
        } catch (PDOException $exception) {
            record_database_operation($this->monitorConfig, $this->queryString, $startedAt, $exception);
            throw $exception;
        }
    }
}

final class LinkVaultPDO extends PDO
{
    public function __construct(string $databasePath, int $timeoutSeconds, private readonly array $monitorConfig)
    {
        parent::__construct('sqlite:' . $databasePath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => $timeoutSeconds,
            PDO::ATTR_STATEMENT_CLASS => [LinkVaultPDOStatement::class, [$monitorConfig]],
        ]);
    }

    #[Override]
    public function exec(string $statement): int|false
    {
        $startedAt = hrtime(true);
        try {
            $result = parent::exec($statement);
            record_database_operation($this->monitorConfig, $statement, $startedAt);
            return $result;
        } catch (PDOException $exception) {
            record_database_operation($this->monitorConfig, $statement, $startedAt, $exception);
            throw $exception;
        }
    }

    #[Override]
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $startedAt = hrtime(true);
        try {
            $result = $fetchMode === null
                ? parent::query($query)
                : parent::query($query, $fetchMode, ...$fetchModeArgs);
            record_database_operation($this->monitorConfig, $query, $startedAt);
            return $result;
        } catch (PDOException $exception) {
            record_database_operation($this->monitorConfig, $query, $startedAt, $exception);
            throw $exception;
        }
    }
}

function database(array $config, int $busyTimeoutMs = 5000, bool $validateSchema = false): PDO
{
    $busyTimeoutMs = max(0, min(60000, $busyTimeoutMs));
    $pdo = new LinkVaultPDO(
        (string)$config['database_path'],
        (int)floor($busyTimeoutMs / 1000),
        $config
    );
    $pdo->exec('PRAGMA busy_timeout = ' . $busyTimeoutMs);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA synchronous = NORMAL');
    $cacheSizeKib = max(1024, min(1048576, (int)($config['sqlite_cache_size_kib'] ?? 32768)));
    $pdo->exec('PRAGMA cache_size = -' . $cacheSizeKib);
    $schemaVersion = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
    if ($schemaVersion !== LINKVAULT_SCHEMA_VERSION) {
        throw new DatabaseMigrationRequired(
            "Database schema version {$schemaVersion}; expected " . LINKVAULT_SCHEMA_VERSION . '.'
        );
    }
    if ($validateSchema) {
        $schemaProblems = linkvault_schema_problems($pdo);
        if ($schemaProblems) {
            throw new DatabaseMigrationRequired('Database schema validation failed: ' . implode('; ', $schemaProblems));
        }
    }
    return $pdo;
}

/** @return array<string, bool> */
function readiness_checks(array $config, bool $deep = false): array
{
    $checks = [
        'configuration' => is_strong_admin_password((string)($config['admin_password'] ?? '')),
        'database_read' => false,
        'database_write' => false,
        'disk_space' => false,
    ];
    $databasePath = (string)($config['database_path'] ?? '');
    $databaseDirectory = $databasePath === '' ? '' : dirname($databasePath);
    if ($databaseDirectory !== '' && is_dir($databaseDirectory)) {
        $freeBytes = @disk_free_space($databaseDirectory);
        $checks['disk_space'] = $freeBytes !== false
            && $freeBytes >= max(1, (int)($config['health_min_free_bytes'] ?? 128 * 1024 * 1024));
    }
    if (!$checks['configuration'] || $databasePath === '' || !is_file($databasePath)) {
        return $checks;
    }

    $healthPdo = null;
    $writeTransactionStarted = false;
    try {
        $healthPdo = database(
            $config,
            max(1, min(1000, (int)($config['health_busy_timeout_ms'] ?? 100))),
            $deep
        );
        $checks['database_read'] = (int)$healthPdo->query('SELECT 1')->fetchColumn() === 1;
        if ($deep) {
            $healthPdo->exec('BEGIN IMMEDIATE');
            $writeTransactionStarted = true;
            $statement = $healthPdo->prepare(<<<'SQL'
                INSERT INTO healthcheck_probe (id, checked_at) VALUES (1, :checked_at)
                ON CONFLICT(id) DO UPDATE SET checked_at = excluded.checked_at
            SQL);
            $statement->execute(['checked_at' => utc_timestamp()]);
            $healthPdo->exec('ROLLBACK');
            $writeTransactionStarted = false;
            $checks['database_write'] = true;
        } else {
            $checks['database_write'] = is_writable($databasePath) && is_writable($databaseDirectory);
        }
    } catch (Throwable) {
        if ($writeTransactionStarted && $healthPdo instanceof PDO) {
            try {
                $healthPdo->exec('ROLLBACK');
            } catch (Throwable) {
            }
        }
    }

    return $checks;
}

function backup_is_fresh(array $config): bool
{
    return linkvault_backup_health_status($config)['healthy'];
}

/** @return array{allowed: bool, retry_after_seconds: int} */
function reserve_api_token_request(
    PDO $pdo,
    string $tokenIdentifier,
    array $config,
    ?int $maximumOverride = null,
    ?int $windowOverride = null
): array
{
    $maximum = max(1, $maximumOverride ?? (int)($config['api_rate_limit_requests'] ?? 60));
    $window = max(1, $windowOverride ?? (int)($config['api_rate_limit_window_seconds'] ?? 60));
    $identifier = hash('sha256', 'api-token:' . $tokenIdentifier);

    return with_sqlite_retry(function () use ($pdo, $identifier, $maximum, $window): array {
        $now = time();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $lookup = $pdo->prepare(
                'SELECT request_count, window_started_at FROM api_rate_limits WHERE identifier = :identifier'
            );
            $lookup->execute(['identifier' => $identifier]);
            $current = $lookup->fetch();
            $windowStartedAt = is_array($current) ? (int)$current['window_started_at'] : $now;
            $requestCount = is_array($current) ? (int)$current['request_count'] : 0;
            if ($windowStartedAt > $now || $windowStartedAt <= $now - $window) {
                $windowStartedAt = $now;
                $requestCount = 0;
            }

            if ($requestCount >= $maximum) {
                $pdo->exec('COMMIT');
                return [
                    'allowed' => false,
                    'retry_after_seconds' => max(1, $windowStartedAt + $window - $now),
                ];
            }

            $store = $pdo->prepare(<<<'SQL'
                INSERT INTO api_rate_limits (identifier, request_count, window_started_at, updated_at)
                VALUES (:identifier, :request_count, :window_started_at, :updated_at)
                ON CONFLICT(identifier) DO UPDATE SET
                    request_count = excluded.request_count,
                    window_started_at = excluded.window_started_at,
                    updated_at = excluded.updated_at
            SQL);
            $store->execute([
                'identifier' => $identifier,
                'request_count' => $requestCount + 1,
                'window_started_at' => $windowStartedAt,
                'updated_at' => $now,
            ]);
            if (random_int(1, 100) === 1) {
                $prune = $pdo->prepare('DELETE FROM api_rate_limits WHERE updated_at < :cutoff');
                $prune->execute(['cutoff' => $now - $window * 2]);
            }
            $pdo->exec('COMMIT');
            return ['allowed' => true, 'retry_after_seconds' => 0];
        } catch (Throwable $exception) {
            try {
                $pdo->exec('ROLLBACK');
            } catch (Throwable) {
            }
            throw $exception;
        }
    });
}

function log_event(array $config, string $event, array $context = []): void
{
    global $requestId;
    $entry = array_merge([
        'time' => gmdate('c'),
        'event' => $event,
        'request_id' => $requestId ?? null,
        'release' => release_metadata($config),
    ], $context);
    $line = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $logPath = (string)($config['application_log_path'] ?? '');
    if ($line === false || $logPath === '') {
        @error_log('LinkVault event: ' . $event);
        return;
    }
    $logDir = dirname($logPath);
    if ((!is_dir($logDir) && !@mkdir($logDir, 0775, true) && !is_dir($logDir))
        || !@error_log($line . PHP_EOL, 3, $logPath)) {
        @error_log($line);
    }
}

function audit_event(
    ?PDO $pdo,
    array $config,
    string $actorType,
    string $action,
    string $outcome,
    ?string $entityType = null,
    ?string $entityId = null,
    array $details = []
): void {
    global $requestId;

    try {
        if (!$pdo instanceof PDO) {
            $databasePath = (string)($config['database_path'] ?? '');
            if ($databasePath === '' || !is_file($databasePath)) {
                return;
            }
            $pdo = new PDO('sqlite:' . $databasePath, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => 1,
            ]);
            $pdo->exec('PRAGMA busy_timeout = 1000');
        }
        $table = $pdo->query(
            "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'audit_events'"
        )->fetchColumn();
        if (!$table) {
            return;
        }
        $detailsJson = json_encode(
            $details,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
        $statement = $pdo->prepare(<<<'SQL'
            INSERT INTO audit_events (
                created_at, actor_type, action, outcome, entity_type, entity_id, request_id, details_json
            ) VALUES (
                :created_at, :actor_type, :action, :outcome, :entity_type, :entity_id, :request_id, :details_json
            )
        SQL);
        $statement->execute([
            'created_at' => utc_timestamp(),
            'actor_type' => limit_text($actorType, 32),
            'action' => limit_text($action, 64),
            'outcome' => in_array($outcome, ['success', 'failure'], true) ? $outcome : 'failure',
            'entity_type' => $entityType === null ? null : limit_text($entityType, 32),
            'entity_id' => $entityId === null ? null : limit_text($entityId, 128),
            'request_id' => isset($requestId) ? limit_text((string)$requestId, 64) : null,
            'details_json' => is_string($detailsJson) ? $detailsJson : '{}',
        ]);

    } catch (Throwable $exception) {
        log_event($config, 'audit_write_failed', [
            'action' => limit_text($action, 64),
            'error' => limit_text($exception->getMessage(), 300),
        ]);
    }
}

function client_ip(array $config): string
{
    $remoteAddress = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    if (!is_trusted_proxy($config)) {
        return limit_text($remoteAddress, 64);
    }

    $forwardedFor = array_map('trim', explode(',', (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')));
    $chain = array_merge($forwardedFor, [$remoteAddress]);
    $trustedProxies = (array)($config['trusted_proxies'] ?? []);
    for ($index = count($chain) - 1; $index >= 0; $index--) {
        $candidate = $chain[$index];
        if (!filter_var($candidate, FILTER_VALIDATE_IP)) {
            return limit_text($remoteAddress, 64);
        }
        if (!in_array($candidate, $trustedProxies, true)) {
            return limit_text($candidate, 64);
        }
    }
    return limit_text($remoteAddress, 64);
}

function privacy_safe_ip(string $ip): string
{
    $packed = @inet_pton($ip);
    if ($packed === false) {
        return 'unknown';
    }

    if (strlen($packed) === 4) {
        return sprintf('%d.%d.%d.0/24', ord($packed[0]), ord($packed[1]), ord($packed[2]));
    }

    $prefix = substr($packed, 0, 8) . str_repeat("\0", 8);
    return inet_ntop($prefix) . '/64';
}

function login_limit_config(array $config): array
{
    return [
        'max_attempts' => max(2, (int)($config['login_max_attempts'] ?? 5)),
        'window' => max(60, (int)($config['login_attempt_window'] ?? 900)),
        'lock_duration' => max(60, (int)($config['login_lock_duration'] ?? 900)),
    ];
}

function session_login_lock_remaining(array $config): int
{
    $attempt = $_SESSION['login_throttle'] ?? null;
    if (!is_array($attempt)) {
        return 0;
    }
    $now = time();
    $limits = login_limit_config($config);
    if ((int)($attempt['last_failed_at'] ?? 0) <= $now - $limits['window']
        && (int)($attempt['locked_until'] ?? 0) <= $now) {
        unset($_SESSION['login_throttle']);
        return 0;
    }
    return max(0, (int)($attempt['locked_until'] ?? 0) - $now);
}

function reserve_session_login_attempt(array $config): array
{
    $limits = login_limit_config($config);
    $now = time();
    $attempt = $_SESSION['login_throttle'] ?? [];
    if (!is_array($attempt) || (int)($attempt['window_started_at'] ?? 0) <= $now - $limits['window']) {
        $failures = 1;
        $windowStartedAt = $now;
    } else {
        $failures = (int)($attempt['failures'] ?? 0) + 1;
        $windowStartedAt = (int)$attempt['window_started_at'];
    }
    $lockedUntil = (int)($attempt['locked_until'] ?? 0);
    if ($failures >= $limits['max_attempts']) {
        $lockedUntil = max($lockedUntil, $now + $limits['lock_duration']);
    }
    $_SESSION['login_throttle'] = [
        'failures' => $failures,
        'window_started_at' => $windowStartedAt,
        'last_failed_at' => $now,
        'locked_until' => $lockedUntil,
    ];
    return ['blocked' => false, 'failures' => $failures, 'retry_after_seconds' => max(0, $lockedUntil - $now)];
}

function reserve_ip_login_attempt(PDO $pdo, string $ip, array $config): array
{
    $limits = login_limit_config($config);
    return with_sqlite_retry(function () use ($pdo, $ip, $limits): array {
        $now = time();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $stmt = $pdo->prepare('SELECT failures, window_started_at, locked_until FROM login_attempts WHERE identifier = :identifier');
            $stmt->execute(['identifier' => $ip]);
            $attempt = $stmt->fetch();
            if ($attempt && (int)$attempt['locked_until'] > $now) {
                $pdo->exec('COMMIT');
                return ['blocked' => true, 'failures' => (int)$attempt['failures'], 'retry_after_seconds' => (int)$attempt['locked_until'] - $now];
            }
            if (!$attempt || (int)$attempt['window_started_at'] <= $now - $limits['window']) {
                $failures = 1;
                $windowStartedAt = $now;
                $lockedUntil = 0;
            } else {
                $failures = (int)$attempt['failures'] + 1;
                $windowStartedAt = (int)$attempt['window_started_at'];
                $lockedUntil = (int)$attempt['locked_until'];
            }
            if ($failures >= $limits['max_attempts']) {
                $lockedUntil = max($lockedUntil, $now + $limits['lock_duration']);
            }
            $stmt = $pdo->prepare(<<<'SQL'
                INSERT INTO login_attempts (identifier, failures, window_started_at, last_failed_at, locked_until)
                VALUES (:identifier, :failures, :window_started_at, :last_failed_at, :locked_until)
                ON CONFLICT(identifier) DO UPDATE SET
                    failures = excluded.failures,
                    window_started_at = excluded.window_started_at,
                    last_failed_at = excluded.last_failed_at,
                    locked_until = excluded.locked_until
            SQL);
            $stmt->execute([
                'identifier' => $ip,
                'failures' => $failures,
                'window_started_at' => $windowStartedAt,
                'last_failed_at' => $now,
                'locked_until' => $lockedUntil,
            ]);
            $pdo->exec('COMMIT');
            return ['blocked' => false, 'failures' => $failures, 'retry_after_seconds' => max(0, $lockedUntil - $now)];
        } catch (Throwable $exception) {
            try {
                $pdo->exec('ROLLBACK');
            } catch (Throwable) {
            }
            throw $exception;
        }
    });
}

function clear_login_failures(PDO $pdo, string $ip): void
{
    unset($_SESSION['login_throttle']);
    with_sqlite_retry(function () use ($pdo, $ip): void {
        $stmt = $pdo->prepare('DELETE FROM login_attempts WHERE identifier = :identifier');
        $stmt->execute(['identifier' => $ip]);
    });
}

function link_unlock_limit_config(array $config): array
{
    return [
        'max_attempts' => max(2, (int)($config['link_unlock_max_attempts'] ?? 5)),
        'window' => max(60, (int)($config['link_unlock_attempt_window'] ?? 900)),
        'lock_duration' => max(60, (int)($config['link_unlock_lock_duration'] ?? 900)),
    ];
}

function link_unlock_client_identifier(array $config): string
{
    return hash('sha256', 'link-unlock:' . client_ip($config));
}

function link_unlock_lock_remaining(PDO $pdo, int $linkId, string $clientIdentifierHash): int
{
    return (int)with_sqlite_retry(function () use ($pdo, $linkId, $clientIdentifierHash): int {
        $statement = $pdo->prepare(<<<'SQL'
            SELECT locked_until FROM link_password_attempts
            WHERE link_id = :link_id AND client_identifier_hash = :client_identifier_hash
        SQL);
        $statement->execute([
            'link_id' => $linkId,
            'client_identifier_hash' => $clientIdentifierHash,
        ]);
        return max(0, (int)$statement->fetchColumn() - time());
    });
}

/** @return array{blocked: bool, failures: int, retry_after_seconds: int} */
function reserve_link_unlock_attempt(
    PDO $pdo,
    int $linkId,
    string $clientIdentifierHash,
    array $config
): array {
    $limits = link_unlock_limit_config($config);
    return with_sqlite_retry(function () use ($pdo, $linkId, $clientIdentifierHash, $limits): array {
        $now = time();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $lookup = $pdo->prepare(<<<'SQL'
                SELECT failures, window_started_at, locked_until
                FROM link_password_attempts
                WHERE link_id = :link_id AND client_identifier_hash = :client_identifier_hash
            SQL);
            $lookup->execute([
                'link_id' => $linkId,
                'client_identifier_hash' => $clientIdentifierHash,
            ]);
            $attempt = $lookup->fetch();
            if ($attempt && (int)$attempt['locked_until'] > $now) {
                $pdo->exec('COMMIT');
                return [
                    'blocked' => true,
                    'failures' => (int)$attempt['failures'],
                    'retry_after_seconds' => (int)$attempt['locked_until'] - $now,
                ];
            }

            if (!$attempt || (int)$attempt['window_started_at'] > $now
                || (int)$attempt['window_started_at'] <= $now - $limits['window']) {
                $failures = 1;
                $windowStartedAt = $now;
                $lockedUntil = 0;
            } else {
                $failures = (int)$attempt['failures'] + 1;
                $windowStartedAt = (int)$attempt['window_started_at'];
                $lockedUntil = (int)$attempt['locked_until'];
            }
            if ($failures >= $limits['max_attempts']) {
                $lockedUntil = max($lockedUntil, $now + $limits['lock_duration']);
            }

            $store = $pdo->prepare(<<<'SQL'
                INSERT INTO link_password_attempts (
                    link_id, client_identifier_hash, failures, window_started_at,
                    last_failed_at, locked_until
                ) VALUES (
                    :link_id, :client_identifier_hash, :failures, :window_started_at,
                    :last_failed_at, :locked_until
                )
                ON CONFLICT(link_id, client_identifier_hash) DO UPDATE SET
                    failures = excluded.failures,
                    window_started_at = excluded.window_started_at,
                    last_failed_at = excluded.last_failed_at,
                    locked_until = excluded.locked_until
            SQL);
            $store->execute([
                'link_id' => $linkId,
                'client_identifier_hash' => $clientIdentifierHash,
                'failures' => $failures,
                'window_started_at' => $windowStartedAt,
                'last_failed_at' => $now,
                'locked_until' => $lockedUntil,
            ]);

            $prune = $pdo->prepare(<<<'SQL'
                DELETE FROM link_password_attempts
                WHERE last_failed_at < :cutoff AND locked_until <= :now
            SQL);
            $prune->execute([
                'cutoff' => $now - max($limits['window'], $limits['lock_duration']) * 2,
                'now' => $now,
            ]);
            $pdo->exec('COMMIT');
            return [
                'blocked' => $lockedUntil > $now,
                'failures' => $failures,
                'retry_after_seconds' => max(0, $lockedUntil - $now),
            ];
        } catch (Throwable $exception) {
            try {
                $pdo->exec('ROLLBACK');
            } catch (Throwable) {
            }
            throw $exception;
        }
    });
}

function clear_link_unlock_failures(PDO $pdo, int $linkId, string $clientIdentifierHash): void
{
    with_sqlite_retry(function () use ($pdo, $linkId, $clientIdentifierHash): void {
        $statement = $pdo->prepare(<<<'SQL'
            DELETE FROM link_password_attempts
            WHERE link_id = :link_id AND client_identifier_hash = :client_identifier_hash
        SQL);
        $statement->execute([
            'link_id' => $linkId,
            'client_identifier_hash' => $clientIdentifierHash,
        ]);
    });
}

function set_link_unlock_grant(array $link, array $config): void
{
    $_SESSION['link_unlock_pending'] = [
        'link_id' => (int)$link['id'],
        'updated_at' => (string)$link['updated_at'],
        'expires_at' => time() + max(30, min(600, (int)($config['link_unlock_grant_ttl'] ?? 120))),
    ];
    unset($_SESSION['link_unlock_confirmation']);
}

function consume_link_unlock_grant(array $link, bool $forConfirmation): bool
{
    $grant = $_SESSION['link_unlock_pending'] ?? null;
    unset($_SESSION['link_unlock_pending']);
    if (!is_array($grant)
        || (int)($grant['link_id'] ?? 0) !== (int)$link['id']
        || (int)($grant['expires_at'] ?? 0) < time()
        || !is_string($grant['updated_at'] ?? null)
        || !hash_equals((string)$link['updated_at'], $grant['updated_at'])) {
        return false;
    }
    if ($forConfirmation) {
        $_SESSION['link_unlock_confirmation'] = $grant;
    }
    return true;
}

function consume_link_confirmation_grant(array $link): bool
{
    $grant = $_SESSION['link_unlock_confirmation'] ?? null;
    unset($_SESSION['link_unlock_confirmation']);
    return is_array($grant)
        && (int)($grant['link_id'] ?? 0) === (int)$link['id']
        && (int)($grant['expires_at'] ?? 0) >= time()
        && is_string($grant['updated_at'] ?? null)
        && hash_equals((string)$link['updated_at'], $grant['updated_at']);
}

function prune_login_failures(PDO $pdo, array $config): void
{
    $limits = login_limit_config($config);
    $now = time();
    $cutoff = $now - max($limits['window'], $limits['lock_duration']);
    with_sqlite_retry(function () use ($pdo, $cutoff, $now): void {
        $stmt = $pdo->prepare('DELETE FROM login_attempts WHERE last_failed_at < :cutoff AND locked_until <= :now');
        $stmt->execute(['cutoff' => $cutoff, 'now' => $now]);
    });
}

function request_path(): string
{
    $path = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
    $scriptDir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
    if ($scriptDir !== '' && $scriptDir !== '/' && ($path === $scriptDir || str_starts_with($path, $scriptDir . '/'))) {
        $path = substr($path, strlen($scriptDir)) ?: '/';
    }
    return $path === '/index.php' ? '/' : '/' . ltrim($path, '/');
}

function base_url(array $config): string
{
    $configured = configured_base_url($config);
    if ($configured !== null) {
        return $configured['url'];
    }

    $scheme = is_https_request($config) ? 'https' : 'http';
    $authority = request_authority((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($authority === null) {
        throw new RuntimeException('Cannot build a base URL from an invalid Host header.');
    }
    $scriptDir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
    return $scheme . '://' . $authority['authority'] . (($scriptDir === '' || $scriptDir === '/') ? '' : $scriptDir);
}

function request_authority(string $authority): ?array
{
    $authority = trim($authority);
    if ($authority === '' || strlen($authority) > 255 || preg_match('/[\x00-\x20\x7f,\/@\\\\]/', $authority)) {
        return null;
    }

    $host = '';
    $port = null;
    if (preg_match('/^\[([0-9A-Fa-f:.]+)\](?::(\d{1,5}))?$/', $authority, $matches)) {
        if (!filter_var($matches[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return null;
        }
        $host = strtolower($matches[1]);
        $port = isset($matches[2]) ? (int)$matches[2] : null;
    } elseif (preg_match('/^([A-Za-z0-9.-]+)(?::(\d{1,5}))?$/', $authority, $matches)) {
        $host = strtolower(rtrim($matches[1], '.'));
        if ($host === '' || (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            && !filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME))) {
            return null;
        }
        $port = isset($matches[2]) ? (int)$matches[2] : null;
    } else {
        return null;
    }

    if ($port !== null && ($port < 1 || $port > 65535)) {
        return null;
    }

    $displayHost = str_contains($host, ':') ? "[{$host}]" : $host;
    return [
        'host' => $host,
        'port' => $port,
        'authority' => $displayHost . ($port === null ? '' : ':' . $port),
    ];
}

function is_loopback_address(string $address): bool
{
    $packed = @inet_pton($address);
    if (!is_string($packed)) {
        return false;
    }
    if (strlen($packed) === 4) {
        return ord($packed[0]) === 127;
    }

    return $packed === str_repeat("\0", 15) . "\1"
        || (substr($packed, 0, 12) === str_repeat("\0", 10) . "\xff\xff"
            && ord($packed[12]) === 127);
}

function configured_base_url(array $config): ?array
{
    $url = rtrim(trim((string)($config['base_url'] ?? '')), '/');
    if ($url === '') {
        return null;
    }
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        throw new InvalidArgumentException('LINKVAULT_BASE_URL must be a valid absolute URL.');
    }

    $parts = parse_url($url);
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    $host = rtrim((string)($parts['host'] ?? ''), '.');
    if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
        $host = substr($host, 1, -1);
    }
    $host = strtolower($host);
    if (!in_array($scheme, ['http', 'https'], true) || $host === ''
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
        throw new InvalidArgumentException('LINKVAULT_BASE_URL must contain only an HTTP(S) origin and optional path.');
    }
    if (!filter_var($host, FILTER_VALIDATE_IP)
        && !filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
        throw new InvalidArgumentException('LINKVAULT_BASE_URL contains an invalid host.');
    }

    $port = isset($parts['port']) ? (int)$parts['port'] : null;
    $defaultPort = $scheme === 'https' ? 443 : 80;
    $displayHost = str_contains($host, ':') ? "[{$host}]" : $host;
    $path = rtrim((string)($parts['path'] ?? ''), '/');

    return [
        'url' => $scheme . '://' . $displayHost . ($port === null ? '' : ':' . $port) . $path,
        'scheme' => $scheme,
        'host' => $host,
        'effective_port' => $port ?? $defaultPort,
        'requires_explicit_port' => $port !== null && $port !== $defaultPort,
    ];
}

function enforce_request_host(array $config): void
{
    try {
        $configured = configured_base_url($config);
    } catch (InvalidArgumentException $exception) {
        log_event($config, 'invalid_base_url_configuration', [
            'error' => limit_text($exception->getMessage(), 300),
        ]);
        audit_event(null, $config, 'system', 'configuration_error', 'failure', 'configuration', 'base_url', [
            'reason' => limit_text($exception->getMessage(), 200),
        ]);
        render_error_page(503, '服务域名配置无效', '请由管理员检查服务域名配置。');
    }

    $requested = request_authority((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($requested === null) {
        log_event($config, 'host_rejected', ['reason' => 'invalid']);
        render_error_page(421, '请求域名无效', '此请求未发送到已配置的服务域名。');
    }

    if ($configured === null) {
        $remoteAddress = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        if (in_array($requested['host'], ['127.0.0.1', '::1', 'localhost'], true)
            && is_loopback_address($remoteAddress)) {
            return;
        }
        log_event($config, 'base_url_required', [
            'host' => limit_text($requested['host'], 200),
            'remote_address' => limit_text($remoteAddress, 64),
        ]);
        render_error_page(503, '服务域名尚未配置', '非本机访问必须配置固定的服务域名。');
    }

    if ($configured['scheme'] === 'https' && !is_https_request($config)) {
        log_event($config, 'scheme_rejected', ['expected' => 'https', 'actual' => 'http']);
        render_error_page(421, '请求协议不匹配', '此服务仅接受 HTTPS 请求。');
    }

    $portMismatch = ($requested['port'] !== null && $requested['port'] !== $configured['effective_port'])
        || ($configured['requires_explicit_port'] && $requested['port'] === null);
    if ($requested['host'] !== $configured['host'] || $portMismatch) {
        $customPortValid = $requested['port'] === null || $requested['port'] === 443;
        $customDomain = $customPortValid && is_https_request($config)
            ? lookup_short_domain_for_request($config, $requested['host'])
            : null;
        if (is_array($customDomain) && custom_domain_path_allowed(request_path())) {
            $GLOBALS['linkvault_short_domain'] = $customDomain;
            return;
        }
        log_event($config, 'host_rejected', ['host' => limit_text($requested['authority'], 200)]);
        render_error_page(421, '请求域名不匹配', '此请求未发送到已配置的服务域名。');
    }
}

function lookup_short_domain_for_request(array $config, string $hostname): ?array
{
    $databasePath = (string)($config['database_path'] ?? '');
    if ($databasePath === '' || !is_file($databasePath) || !extension_loaded('pdo_sqlite')) {
        return null;
    }
    try {
        $pdo = new PDO('sqlite:' . $databasePath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 1,
        ]);
        $table = $pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'short_domains'")->fetchColumn();
        if (!$table) {
            return null;
        }
        $statement = $pdo->prepare(<<<'SQL'
            SELECT id, hostname, brand_name, brand_tagline, brand_theme, brand_color,
                   logo_url, favicon_url, invalid_page_title, invalid_page_message
            FROM short_domains
            WHERE hostname = :hostname AND verified_at IS NOT NULL AND is_enabled = 1
        SQL);
        $statement->execute(['hostname' => strtolower(rtrim($hostname, '.'))]);
        $domain = $statement->fetch();
        return $domain ?: null;
    } catch (Throwable) {
        return null;
    }
}

function custom_domain_path_allowed(string $path): bool
{
    return $path === '/' || $path === '/favicon.ico' || str_starts_with($path, '/assets/')
        || preg_match('#^/[A-Za-z0-9_-]{3,64}(?:/(?:unlock|confirm))?$#', $path) === 1;
}

function current_short_domain(): ?array
{
    $domain = $GLOBALS['linkvault_short_domain'] ?? null;
    return is_array($domain) ? $domain : null;
}

function current_short_domain_id(): ?int
{
    $domain = current_short_domain();
    return $domain === null ? null : (int)$domain['id'];
}

function short_url_base(array $config, array $link): string
{
    $hostname = trim((string)($link['short_domain_hostname'] ?? ''));
    return $hostname === '' ? base_url($config) : 'https://' . $hostname;
}

function app_path(string $path = '/'): string
{
    $scriptDir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
    $prefix = ($scriptDir === '' || $scriptDir === '/') ? '' : $scriptDir;
    return $prefix . '/' . ltrim($path, '/');
}

function asset_path(string $path): string
{
    static $manifest = null;

    if ($manifest === null) {
        $manifestPath = dirname(__DIR__) . '/public/assets/manifest.json';
        $decoded = is_file($manifestPath) ? json_decode((string)file_get_contents($manifestPath), true) : [];
        $manifest = is_array($decoded) ? $decoded : [];
    }

    $logicalPath = '/assets/' . ltrim($path, '/');
    $fingerprintedPath = $manifest[$logicalPath] ?? $logicalPath;
    if (!is_string($fingerprintedPath)
        || preg_match('#^/assets/(?:fonts/)?[A-Za-z0-9_.-]+\.[0-9a-f]{12}\.(?:css|js|woff2|svg)$#D', $fingerprintedPath) !== 1) {
        $fingerprintedPath = $logicalPath;
    }

    return app_path($fingerprintedPath);
}

function valid_target_url(string $url, int $maxLength = 2048): bool
{
    if ($url === '' || strlen($url) > max(1, $maxLength) || !filter_var($url, FILTER_VALIDATE_URL)) {
        return false;
    }
    return in_array(strtolower((string)parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
}

function valid_invalid_message(string $message): bool
{
    return text_length($message) <= 500
        && preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $message) !== 1;
}

function valid_campaign_value(string $value): bool
{
    return text_length($value) <= 100 && preg_match('/[\x00-\x1F\x7F]/u', $value) !== 1;
}

/** Existing UTM values are removed only when clearing a campaign-managed link. */
function apply_campaign_parameters(string $url, array $campaign, bool $removeExisting = false): string
{
    $mapping = [
        'campaign_name' => 'utm_campaign',
        'campaign_source' => 'utm_source',
        'campaign_medium' => 'utm_medium',
        'campaign_content' => 'utm_content',
    ];
    $hasValues = false;
    foreach ($mapping as $field => $_parameter) {
        if ((string)($campaign[$field] ?? '') !== '') {
            $hasValues = true;
            break;
        }
    }
    if (!$hasValues && !$removeExisting) {
        return $url;
    }

    if (!is_array(parse_url($url))) {
        return $url;
    }

    $fragmentPosition = strpos($url, '#');
    $fragment = $fragmentPosition === false ? '' : substr($url, $fragmentPosition);
    $withoutFragment = $fragmentPosition === false ? $url : substr($url, 0, $fragmentPosition);
    $queryPosition = strpos($withoutFragment, '?');
    $base = $queryPosition === false ? $withoutFragment : substr($withoutFragment, 0, $queryPosition);
    $rawQuery = $queryPosition === false ? '' : substr($withoutFragment, $queryPosition + 1);
    $parts = $rawQuery === '' ? [] : explode('&', $rawQuery);
    $values = [];
    foreach ($mapping as $field => $parameter) {
        $values[$parameter] = trim((string)($campaign[$field] ?? ''));
    }

    $emitted = [];
    $resultParts = [];
    foreach ($parts as $part) {
        $separator = strpos($part, '=');
        $rawName = $separator === false ? $part : substr($part, 0, $separator);
        $name = rawurldecode(str_replace('+', ' ', $rawName));
        if (!array_key_exists($name, $values)) {
            $resultParts[] = $part;
            continue;
        }
        if ($values[$name] === '' && !$removeExisting) {
            $resultParts[] = $part;
            continue;
        }
        if ($values[$name] !== '' && !isset($emitted[$name])) {
            $resultParts[] = rawurlencode($name) . '=' . rawurlencode($values[$name]);
            $emitted[$name] = true;
        }
    }
    foreach ($values as $name => $value) {
        if ($value !== '' && !isset($emitted[$name])) {
            $resultParts[] = rawurlencode($name) . '=' . rawurlencode($value);
        }
    }

    return $base . ($resultParts === [] ? '' : '?' . implode('&', $resultParts)) . $fragment;
}

/** @return array<string, array{methods: list<string>, scope: string}> */
function fixed_routes(): array
{
    return [
        '/' => ['methods' => ['GET'], 'scope' => 'admin'],
        '/login' => ['methods' => ['GET', 'POST'], 'scope' => 'admin'],
        '/logout' => ['methods' => ['POST'], 'scope' => 'admin'],
        '/shorten' => ['methods' => ['POST'], 'scope' => 'admin'],
        '/edit' => ['methods' => ['GET', 'POST'], 'scope' => 'admin'],
        '/toggle' => ['methods' => ['POST'], 'scope' => 'admin'],
        '/favorite' => ['methods' => ['POST'], 'scope' => 'admin'],
        '/bulk/preview' => ['methods' => ['POST'], 'scope' => 'admin'],
        '/bulk/undo' => ['methods' => ['POST'], 'scope' => 'admin'],
        '/bulk' => ['methods' => ['POST'], 'scope' => 'admin'],
        '/maintenance/recheck' => ['methods' => ['POST'], 'scope' => 'admin'],
        '/maintenance/repair' => ['methods' => ['POST'], 'scope' => 'admin'],
        '/presets/save' => ['methods' => ['POST'], 'scope' => 'admin'],
        '/presets/delete' => ['methods' => ['POST'], 'scope' => 'admin'],
        '/webhooks/replay' => ['methods' => ['POST'], 'scope' => 'admin'],
        '/notifications/read' => ['methods' => ['POST'], 'scope' => 'admin'],
        '/notifications/dismiss' => ['methods' => ['POST'], 'scope' => 'admin'],
        '/workflows/tag-rules/save' => ['methods' => ['POST'], 'scope' => 'admin'],
        '/workflows/tag-rules/delete' => ['methods' => ['POST'], 'scope' => 'admin'],
        '/workflows/tag-rules/apply' => ['methods' => ['POST'], 'scope' => 'admin'],
        '/workflows/duplicates/merge' => ['methods' => ['POST'], 'scope' => 'admin'],
        '/marketing/funnels/save' => ['methods' => ['POST'], 'scope' => 'admin'],
        '/trust/blacklist/save' => ['methods' => ['POST'], 'scope' => 'admin'],
        '/trust/scan' => ['methods' => ['POST'], 'scope' => 'admin'],
        '/trust/reports/action' => ['methods' => ['POST'], 'scope' => 'admin'],
        '/privacy' => ['methods' => ['GET'], 'scope' => 'public'],
        '/browser-extension-privacy' => ['methods' => ['GET'], 'scope' => 'public'],
        '/report' => ['methods' => ['GET', 'POST'], 'scope' => 'public'],
        '/clear-expiration' => ['methods' => ['POST'], 'scope' => 'admin'],
        '/delete' => ['methods' => ['POST'], 'scope' => 'admin'],
        '/restore' => ['methods' => ['POST'], 'scope' => 'admin'],
        '/purge' => ['methods' => ['POST'], 'scope' => 'admin'],
        '/import' => ['methods' => ['POST'], 'scope' => 'admin'],
        '/import-confirm' => ['methods' => ['POST'], 'scope' => 'admin'],
        '/import-cancel' => ['methods' => ['POST'], 'scope' => 'admin'],
        '/filters/save' => ['methods' => ['POST'], 'scope' => 'admin'],
        '/filters/rename' => ['methods' => ['POST'], 'scope' => 'admin'],
        '/filters/delete' => ['methods' => ['POST'], 'scope' => 'admin'],
        '/analytics-views/save' => ['methods' => ['POST'], 'scope' => 'admin'],
        '/analytics-views/rename' => ['methods' => ['POST'], 'scope' => 'admin'],
        '/analytics-views/delete' => ['methods' => ['POST'], 'scope' => 'admin'],
        '/analytics-exports' => ['methods' => ['POST'], 'scope' => 'admin'],
        '/analytics-export-status' => ['methods' => ['GET'], 'scope' => 'admin'],
        '/analytics-export-download' => ['methods' => ['GET'], 'scope' => 'admin'],
        '/api-tokens/create' => ['methods' => ['POST'], 'scope' => 'admin'],
        '/api-tokens/rotate' => ['methods' => ['POST'], 'scope' => 'admin'],
        '/api-tokens/revoke' => ['methods' => ['POST'], 'scope' => 'admin'],
        '/api-tokens/alerts/clear' => ['methods' => ['POST'], 'scope' => 'admin'],
        '/domains/create' => ['methods' => ['POST'], 'scope' => 'admin'],
        '/domains/verify' => ['methods' => ['POST'], 'scope' => 'admin'],
        '/domains/update' => ['methods' => ['POST'], 'scope' => 'admin'],
        '/domains/update-appearance' => ['methods' => ['POST'], 'scope' => 'admin'],
        '/domains/toggle' => ['methods' => ['POST'], 'scope' => 'admin'],
        '/domains/retire' => ['methods' => ['POST'], 'scope' => 'admin'],
        '/domains/retire/pause' => ['methods' => ['POST'], 'scope' => 'admin'],
        '/domains/retire/resume' => ['methods' => ['POST'], 'scope' => 'admin'],
        '/domains/retire/cancel' => ['methods' => ['POST'], 'scope' => 'admin'],
        '/domains/retire/retry' => ['methods' => ['POST'], 'scope' => 'admin'],
        '/domains/delete' => ['methods' => ['POST'], 'scope' => 'admin'],
        '/security/totp/setup' => ['methods' => ['POST'], 'scope' => 'admin'],
        '/security/totp/enable' => ['methods' => ['POST'], 'scope' => 'admin'],
        '/security/totp/cancel' => ['methods' => ['POST'], 'scope' => 'admin'],
        '/security/totp/disable' => ['methods' => ['POST'], 'scope' => 'admin'],
        '/security/recovery-codes/regenerate' => ['methods' => ['POST'], 'scope' => 'admin'],
        '/link' => ['methods' => ['GET'], 'scope' => 'admin'],
        '/links/overview' => ['methods' => ['GET'], 'scope' => 'admin'],
        '/export-links' => ['methods' => ['GET', 'POST'], 'scope' => 'admin'],
        '/export-snapshot' => ['methods' => ['GET'], 'scope' => 'admin'],
        '/export-analytics' => ['methods' => ['GET'], 'scope' => 'admin'],
        '/import-report' => ['methods' => ['GET'], 'scope' => 'admin'],
        '/api/shorten' => ['methods' => ['POST'], 'scope' => 'api'],
        '/api/links' => ['methods' => ['GET'], 'scope' => 'api'],
        '/api/conversions' => ['methods' => ['POST'], 'scope' => 'api'],
        '/livez' => ['methods' => ['GET', 'HEAD'], 'scope' => 'health'],
        '/readyz' => ['methods' => ['GET', 'HEAD'], 'scope' => 'health'],
        '/healthz' => ['methods' => ['GET', 'HEAD'], 'scope' => 'health'],
        '/assets' => ['methods' => ['GET', 'HEAD'], 'scope' => 'static'],
        '/favicon.ico' => ['methods' => ['GET', 'HEAD'], 'scope' => 'static'],
        '/index.php' => ['methods' => ['GET'], 'scope' => 'entry'],
        '/router.php' => ['methods' => ['GET'], 'scope' => 'denied'],
    ];
}

/** @return list<string> */
function reserved_slugs(): array
{
    static $reserved = null;
    if (is_array($reserved)) {
        return $reserved;
    }

    $slugs = [];
    foreach (array_keys(fixed_routes()) as $path) {
        $firstSegment = explode('/', ltrim($path, '/'), 2)[0];
        if ($firstSegment !== '') {
            $slugs[strtolower($firstSegment)] = true;
        }
    }
    $root = dirname(__DIR__);
    foreach ([$root, $root . DIRECTORY_SEPARATOR . 'public'] as $directory) {
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..'
                && is_dir($directory . DIRECTORY_SEPARATOR . $entry)
                && preg_match('/^[A-Za-z0-9_-]{3,64}$/', $entry) === 1) {
                $slugs[strtolower($entry)] = true;
            }
        }
    }
    $reserved = array_keys($slugs);
    return $reserved;
}

function valid_slug(string $slug): bool
{
    if (!preg_match('/^[A-Za-z0-9_-]{3,64}$/', $slug)) {
        return false;
    }
    return !in_array(strtolower($slug), reserved_slugs(), true);
}

function normalize_expiration(string $value, mixed $timezoneOffset = null): array
{
    $value = trim($value);
    if ($value === '') {
        return [true, null];
    }
    if (strlen($value) > 40) {
        return [false, null];
    }
    $utc = new DateTimeZone('UTC');
    if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?(?:Z|[+-]\d{2}:\d{2})$/', $value)) {
        try {
            $date = new DateTimeImmutable($value);
            $errors = DateTimeImmutable::getLastErrors();
            if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
                return [false, null];
            }
            return [true, $date->setTimezone($utc)->format('Y-m-d\TH:i:s\Z')];
        } catch (Throwable) {
            return [false, null];
        }
    }
    if (!is_string($timezoneOffset) && !is_int($timezoneOffset)) {
        return [false, null];
    }
    $offsetText = (string)$timezoneOffset;
    if (!preg_match('/^-?\d{1,4}$/', $offsetText)) {
        return [false, null];
    }
    $offsetMinutes = (int)$offsetText;
    if ($offsetMinutes < -840 || $offsetMinutes > 840) {
        return [false, null];
    }
    foreach (['!Y-m-d\TH:i', '!Y-m-d\TH:i:s'] as $format) {
        $date = DateTimeImmutable::createFromFormat($format, $value, $utc);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
            try {
                return [true, $date->modify(sprintf('%+d minutes', $offsetMinutes))->format('Y-m-d\TH:i:s\Z')];
            } catch (Throwable) {
                return [false, null];
            }
        }
    }
    return [false, null];
}

function link_is_expired(array $link): bool
{
    $expiresAt = $link['expires_at'] ?? null;
    if (!is_string($expiresAt) || $expiresAt === '') {
        return false;
    }
    try {
        return new DateTimeImmutable($expiresAt) <= new DateTimeImmutable('now', new DateTimeZone('UTC'));
    } catch (Throwable) {
        return true;
    }
}

function link_is_scheduled(array $link): bool
{
    $startsAt = $link['starts_at'] ?? null;
    if (!is_string($startsAt) || $startsAt === '') {
        return false;
    }
    try {
        return new DateTimeImmutable($startsAt) > new DateTimeImmutable('now', new DateTimeZone('UTC'));
    } catch (Throwable) {
        return true;
    }
}

function link_is_exhausted(array $link): bool
{
    $clicks = max(0, (int)($link['clicks'] ?? 0));
    if ((int)($link['is_one_time'] ?? 0) === 1 && $clicks >= 1) {
        return true;
    }
    $maxClicks = $link['max_clicks'] ?? null;
    return $maxClicks !== null && $maxClicks !== '' && $clicks >= (int)$maxClicks;
}

function link_status_key(array $link): string
{
    if (!empty($link['deleted_at'])) {
        return 'deleted';
    }
    if ((int)($link['is_active'] ?? 1) !== 1
        || (int)($link['access_password_reset_required'] ?? 0) === 1) {
        return 'inactive';
    }
    if (($link['short_domain_id'] ?? null) !== null
        && array_key_exists('short_domain_is_enabled', $link)
        && ((int)$link['short_domain_is_enabled'] !== 1
            || empty($link['short_domain_verified_at']))) {
        return 'domain_inactive';
    }
    if (link_is_scheduled($link)) {
        return 'scheduled';
    }
    if (link_is_expired($link)) {
        return 'expired';
    }
    if (link_is_exhausted($link)) {
        return 'exhausted';
    }
    return 'active';
}

function link_status_label(array $link): string
{
    return match (link_status_key($link)) {
        'deleted' => '已删除',
        'inactive' => '已停用',
        'domain_inactive' => '域名已停用',
        'scheduled' => '待启用',
        'expired' => '已过期',
        'exhausted' => '次数已用尽',
        default => '启用中',
    };
}

function link_is_available(array $link): bool
{
    return link_status_key($link) === 'active';
}

function link_is_password_protected(array $link): bool
{
    return (is_string($link['access_password_hash'] ?? null)
        && $link['access_password_hash'] !== '')
        || (int)($link['access_password_reset_required'] ?? 0) === 1;
}

function render_public_link_unavailable(?array $link, array $config): never
{
    $status = $link === null ? 'missing' : link_status_key($link);
    if ($link !== null && in_array($status, ['inactive', 'expired', 'exhausted'], true)) {
        $fallbackUrl = is_string($link['fallback_url'] ?? null) ? trim($link['fallback_url']) : '';
        if ($fallbackUrl !== '' && valid_target_url(
            $fallbackUrl,
            max(1, (int)($config['target_url_max_length'] ?? 2048))
        )) {
            header('Cache-Control: no-store');
            header('Location: ' . $fallbackUrl, true, 302);
            exit;
        }
        $invalidMessage = is_string($link['invalid_message'] ?? null) ? $link['invalid_message'] : '';
        if (trim($invalidMessage) !== '') {
            render_error_page(404, '短链接不可用', $invalidMessage);
        }
    }
    render_error_page(404, '短链接不存在', '这个短链接不存在、已停用、已过期或已删除。');
}

function render_link_password_prompt(array $link, ?string $error = null, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store, private');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
        exit;
    }
    require dirname(__DIR__) . '/templates/link_password.php';
    exit;
}

function audit_link_state(?array $link): ?array
{
    if ($link === null) {
        return null;
    }
    $state = [];
    foreach ([
        'slug', 'target_url', 'title', 'is_active', 'expires_at', 'starts_at', 'max_clicks',
        'is_one_time', 'one_time_mode', 'is_favorite', 'deleted_at', 'tags',
        'campaign_name', 'campaign_source', 'campaign_medium', 'campaign_content',
        'invalid_message', 'fallback_url',
    ] as $field) {
        $state[$field] = $field === 'tags'
            ? split_stored_tags((string)($link[$field] ?? ''))
            : ($link[$field] ?? null);
    }
    $state['password_protected'] = link_is_password_protected($link)
        || (int)($link['access_password_reset_required'] ?? 0) === 1;
    $state['password_reset_required'] = (int)($link['access_password_reset_required'] ?? 0) === 1;
    return $state;
}

function normalize_tags(string $value): array
{
    if (trim($value) === '') {
        return [true, []];
    }
    $characters = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);
    if (!is_array($characters)) {
        return [false, []];
    }
    $parts = [];
    $part = '';
    $quoted = false;
    for ($index = 0, $count = count($characters); $index < $count; $index++) {
        $character = $characters[$index];
        if ($character === '"') {
            if ($quoted && ($characters[$index + 1] ?? null) === '"') {
                $part .= '"';
                $index++;
            } elseif ($quoted) {
                $quoted = false;
            } elseif (trim($part) === '') {
                $part = '';
                $quoted = true;
            } else {
                $part .= $character;
            }
            continue;
        }
        if (!$quoted && ($character === ',' || $character === '，')) {
            $parts[] = $part;
            $part = '';
            continue;
        }
        $part .= $character;
    }
    if ($quoted) {
        return [false, []];
    }
    $parts[] = $part;
    $tags = [];
    foreach ($parts as $part) {
        $tag = trim($part);
        if ($tag === '') {
            continue;
        }
        if (text_length($tag) > 24 || preg_match('/[\x00-\x1F\x7F]/u', $tag)) {
            return [false, []];
        }
        $tags[$tag] = true;
        if (count($tags) > 10) {
            return [false, []];
        }
    }
    return [true, array_keys($tags)];
}

function split_stored_tags(string $value): array
{
    return $value === '' ? [] : explode("\x1F", $value);
}

function format_tags_input(array $tags): string
{
    return implode(', ', array_map(static function (mixed $tag): string {
        $tag = (string)$tag;
        return preg_match('/[,，"]/u', $tag) === 1 ? '"' . str_replace('"', '""', $tag) . '"' : $tag;
    }, $tags));
}

function normalize_tag_list(array $values): array
{
    if (!array_is_list($values) || count($values) > 10) {
        return [false, []];
    }
    $tags = [];
    foreach ($values as $value) {
        if (!is_string($value)) {
            return [false, []];
        }
        $tag = trim($value);
        if ($tag === '' || text_length($tag) > 24 || preg_match('/[\x00-\x1F\x7F]/u', $tag)) {
            return [false, []];
        }
        $tags[$tag] = true;
    }
    return [true, array_keys($tags)];
}

function list_path(
    string $search = '',
    string $view = 'active',
    int $page = 1,
    string $status = 'all',
    string $sort = 'created_desc',
    string $tag = '',
    bool $favoritesOnly = false,
    int $scroll = 0,
    string $section = 'links',
    string $maintenance = 'expiring'
): string
{
    $query = [];
    if (in_array($section, ['analytics', 'maintenance', 'webhooks', 'audit', 'status', 'security', 'domains', 'api'], true)) {
        $query['section'] = $section;
    }
    if ($section === 'maintenance' && $maintenance !== 'expiring'
        && in_array($maintenance, ['stale_zero', 'quota', 'invalid', 'target_health'], true)) {
        $query['maintenance'] = $maintenance;
    }
    if ($search !== '') {
        $query['q'] = $search;
    }
    if ($view === 'trash') {
        $query['view'] = 'trash';
    }
    if ($page > 1) {
        $query['page'] = $page;
    }
    if ($status !== 'all') {
        $query['status'] = $status;
    }
    if ($sort !== 'created_desc') {
        $query['sort'] = $sort;
    }
    if ($tag !== '') {
        $query['tag'] = $tag;
    }
    if ($favoritesOnly) {
        $query['favorite'] = '1';
    }
    if ($scroll > 0) {
        $query['scroll'] = min($scroll, 10000000);
    }
    return app_path('/') . ($query ? '?' . http_build_query($query) : '');
}

function edit_path(
    string $search,
    string $view,
    int $page,
    int $id,
    string $status = 'all',
    string $sort = 'created_desc',
    string $tag = '',
    bool $favoritesOnly = false
): string
{
    $returnPath = list_path($search, $view, $page, $status, $sort, $tag, $favoritesOnly);
    $returnQuery = [];
    $query = parse_url($returnPath, PHP_URL_QUERY);
    if (is_string($query)) {
        parse_str($query, $returnQuery);
    }
    $parameters = ['id' => max(0, $id)];
    foreach (scalar_query_parameters($returnQuery) as $name => $value) {
        $parameters['return_' . $name] = $value;
    }
    return app_path('/edit') . '?' . http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
}

function expiration_input_value(string $utcValue): string
{
    if ($utcValue === '') {
        return '';
    }
    try {
        return (new DateTimeImmutable($utcValue))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i');
    } catch (Throwable) {
        return '';
    }
}

function returned_list_path(array $return): string
{
    $search = limit_text(trim((string)($return['return_q'] ?? '')), 200);
    $view = (string)($return['return_view'] ?? '') === 'trash' ? 'trash' : 'active';
    $page = max(1, (int)($return['return_page'] ?? 1));
    $statusValue = (string)($return['return_status'] ?? 'all');
    $status = in_array($statusValue, ['all', 'active', 'inactive', 'scheduled', 'expired', 'exhausted'], true)
        ? $statusValue : 'all';
    $sortValue = (string)($return['return_sort'] ?? 'created_desc');
    $sort = in_array($sortValue, [
        'created_desc', 'created_asc', 'clicks_desc', 'clicks_asc', 'last_accessed_desc', 'title_asc',
    ], true) ? $sortValue : 'created_desc';
    $tag = limit_text(trim((string)($return['return_tag'] ?? '')), 24);
    $favoritesOnly = (string)($return['return_favorite'] ?? '') === '1';
    $scroll = min(10000000, max(0, (int)($return['return_scroll'] ?? 0)));
    $sectionValue = (string)($return['return_section'] ?? 'links');
    $section = in_array($sectionValue, ['links', 'analytics', 'maintenance', 'webhooks', 'audit', 'status', 'security', 'domains', 'api'], true) ? $sectionValue : 'links';
    $maintenanceValue = (string)($return['return_maintenance'] ?? 'expiring');
    $maintenance = in_array($maintenanceValue, ['expiring', 'stale_zero', 'quota', 'invalid', 'target_health'], true)
        ? $maintenanceValue : 'expiring';
    return list_path($search, $view, $page, $status, $sort, $tag, $favoritesOnly, $scroll, $section, $maintenance);
}

function posted_list_path(): string
{
    $return = $_POST;
    if (!array_key_exists('return_q', $return) && is_string($_SERVER['HTTP_REFERER'] ?? null)) {
        $refererQuery = parse_url($_SERVER['HTTP_REFERER'], PHP_URL_QUERY);
        $referer = [];
        if (is_string($refererQuery)) {
            parse_str($refererQuery, $referer);
        }
        $return = [
            'return_q' => $referer['q'] ?? '',
            'return_view' => $referer['view'] ?? '',
            'return_page' => $referer['page'] ?? 1,
            'return_status' => $referer['status'] ?? 'all',
            'return_sort' => $referer['sort'] ?? 'created_desc',
            'return_tag' => $referer['tag'] ?? '',
            'return_favorite' => $referer['favorite'] ?? '',
            'return_scroll' => $referer['scroll'] ?? 0,
            'return_section' => $referer['section'] ?? 'links',
            'return_maintenance' => $referer['maintenance'] ?? 'expiring',
        ];
    }
    return returned_list_path($return);
}

function format_bytes(int $bytes): string
{
    $bytes = max(0, $bytes);
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $value = (float)$bytes;
    $unit = 0;
    while ($value >= 1024 && $unit < count($units) - 1) {
        $value /= 1024;
        $unit++;
    }
    return ($unit === 0 ? (string)(int)$value : number_format($value, 1)) . ' ' . $units[$unit];
}

function format_duration(int $seconds): string
{
    $seconds = max(0, $seconds);
    if ($seconds < 60) {
        return $seconds . ' 秒';
    }
    if ($seconds < 3600) {
        return (int)floor($seconds / 60) . ' 分钟';
    }
    if ($seconds < 86400) {
        return (int)floor($seconds / 3600) . ' 小时';
    }
    return (int)floor($seconds / 86400) . ' 天';
}

function random_slug(PDO $pdo, int $length): string
{
    $alphabet = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $length = max(4, $length);
    for ($attempt = 0; $attempt < 20; $attempt++) {
        $slug = '';
        for ($i = 0; $i < $length; $i++) {
            $slug .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $stmt = $pdo->prepare(<<<'SQL'
            SELECT 1 FROM links WHERE slug = :slug
            UNION ALL SELECT 1 FROM link_aliases WHERE alias = :slug
            LIMIT 1
        SQL);
        $stmt->execute(['slug' => $slug]);
        if (valid_slug($slug) && !$stmt->fetchColumn()) {
            return $slug;
        }
        $length++;
    }
    throw new RuntimeException('Cannot generate a unique short code.');
}
