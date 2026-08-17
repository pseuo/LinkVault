<?php

declare(strict_types=1);

final class HttpClient
{
    private array $cookies = [];

    public function __construct(private readonly string $baseUrl)
    {
    }

    public function request(string $method, string $path, string $body = '', array $headers = []): array
    {
        $requestHeaders = array_merge(['User-Agent: linkvault-smoke-test'], $headers);
        if ($this->cookies) {
            $pairs = [];
            foreach ($this->cookies as $name => $value) {
                $pairs[] = $name . '=' . $value;
            }
            $requestHeaders[] = 'Cookie: ' . implode('; ', $pairs);
        }

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $requestHeaders),
                'content' => $body,
                'ignore_errors' => true,
                'follow_location' => 0,
                'timeout' => 15,
            ],
        ]);
        $responseBody = @file_get_contents($this->baseUrl . $path, false, $context);
        $responseHeaders = $http_response_header ?? [];
        $status = 0;
        $parsedHeaders = [];

        foreach ($responseHeaders as $line) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $line, $matches)) {
                $status = (int)$matches[1];
                continue;
            }
            if (!str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $name = strtolower(trim($name));
            $value = trim($value);
            $parsedHeaders[$name][] = $value;
            if ($name === 'set-cookie' && preg_match('/^([^=;]+)=([^;]*)/', $value, $matches)) {
                $this->cookies[$matches[1]] = $matches[2];
            }
        }

        return [
            'status' => $status,
            'headers' => $parsedHeaders,
            'body' => is_string($responseBody) ? $responseBody : '',
        ];
    }

    public function form(string $path, array $fields, array $headers = []): array
    {
        if ($path === '/shorten' && !isset($fields['create_request_id'])) {
            $fields['create_request_id'] = bin2hex(random_bytes(16));
        }
        return $this->request(
            'POST',
            $path,
            http_build_query($fields, '', '&', PHP_QUERY_RFC3986),
            array_merge(['Content-Type: application/x-www-form-urlencoded'], $headers)
        );
    }
}

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function header_value(array $response, string $name): ?string
{
    $values = $response['headers'][strtolower($name)] ?? [];
    return $values[0] ?? null;
}

function csrf_from(string $html): string
{
    if (!preg_match('/name="csrf"\s+value="([^"]+)"/', $html, $matches)) {
        throw new RuntimeException('Cannot find a CSRF token in the response.');
    }

    return html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function generated_api_token_from(string $html): string
{
    if (!preg_match('/id="created-api-token"[^>]*value="([^"]+)"/', $html, $matches)) {
        throw new RuntimeException('Cannot find the generated API token in the response.');
    }
    return html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function totp_secret_from(string $html): string
{
    if (!preg_match('/id="totp-secret"[^>]*value="([A-Z2-7]{32})"/', $html, $matches)) {
        throw new RuntimeException('Cannot find the pending TOTP secret in the response.');
    }
    return $matches[1];
}

/** @return list<string> */
function recovery_codes_from(string $html): array
{
    if (!preg_match('/id="created-recovery-codes"[^>]*>([^<]+)<\/textarea>/s', $html, $matches)) {
        throw new RuntimeException('Cannot find generated recovery codes in the response.');
    }
    $value = html_entity_decode(trim($matches[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return array_values(array_filter(array_map('trim', preg_split('/\R/', $value) ?: [])));
}

function totp_test_code(string $secret, ?int $counter = null): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $buffer = 0;
    $bits = 0;
    $binary = '';
    foreach (str_split($secret) as $character) {
        $value = strpos($alphabet, $character);
        if ($value === false) {
            throw new RuntimeException('Invalid test TOTP secret.');
        }
        $buffer = ($buffer << 5) | $value;
        $bits += 5;
        if ($bits >= 8) {
            $bits -= 8;
            $binary .= chr(($buffer >> $bits) & 255);
            $buffer &= (1 << $bits) - 1;
        }
    }
    $counter ??= intdiv(time(), 30);
    $digest = hash_hmac('sha1', pack('N2', intdiv($counter, 4294967296), $counter % 4294967296), $binary, true);
    $position = ord($digest[19]) & 0x0f;
    $value = unpack('N', substr($digest, $position, 4));
    return str_pad((string)(((int)($value[1] ?? 0) & 0x7fffffff) % 1000000), 6, '0', STR_PAD_LEFT);
}

function import_json(HttpClient $client, string $csrf, string $json, string $conflictMode = 'skip'): array
{
    $boundary = '----linkvault-smoke-' . bin2hex(random_bytes(8));
    $multipart = "--{$boundary}\r\n"
        . "Content-Disposition: form-data; name=\"csrf\"\r\n\r\n{$csrf}\r\n"
        . "--{$boundary}\r\n"
        . "Content-Disposition: form-data; name=\"conflict_mode\"\r\n\r\n{$conflictMode}\r\n"
        . "--{$boundary}\r\n"
        . "Content-Disposition: form-data; name=\"import_file\"; filename=\"links.json\"\r\n"
        . "Content-Type: application/json\r\n\r\n{$json}\r\n"
        . "--{$boundary}--\r\n";

    return $client->request('POST', '/import', $multipart, [
        'Content-Type: multipart/form-data; boundary=' . $boundary,
        'Content-Length: ' . strlen($multipart),
    ]);
}

function process_environment(array $overrides): array
{
    $environment = getenv();
    return array_merge(is_array($environment) ? $environment : [], $overrides);
}

function run_process(array $command, string $workingDirectory, array $environment): array
{
    $pipes = [];
    $process = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, $workingDirectory, $environment, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start process: ' . implode(' ', $command));
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [
        'exit_code' => proc_close($process),
        'stdout' => is_string($stdout) ? $stdout : '',
        'stderr' => is_string($stderr) ? $stderr : '',
    ];
}

function available_port(): int
{
    $errno = 0;
    $error = '';
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if (!is_resource($socket)) {
        throw new RuntimeException("Cannot allocate a test port: {$error}");
    }
    $address = stream_socket_get_name($socket, false);
    fclose($socket);

    return (int)substr((string)$address, (int)strrpos((string)$address, ':') + 1);
}

function remove_tree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $child = $path . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($child)) {
            remove_tree($child);
        } else {
            @unlink($child);
        }
    }
    @rmdir($path);
}

function login(HttpClient $client, string $password): string
{
    $page = $client->request('GET', '/login');
    assert_true($page['status'] === 200, 'The login page must return 200.');
    $response = $client->form('/login', [
        'csrf' => csrf_from($page['body']),
        'password' => $password,
    ]);
    assert_true($response['status'] === 303, 'Successful login must redirect.');

    $page = $client->request('GET', '/');
    assert_true($page['status'] === 200 && str_contains($page['body'], '退出'), 'Login did not create an authenticated session.');
    return csrf_from($page['body']);
}

function start_server(string $root, int $port, array $environment, string $serverOutput)
{
    $pipes = [];
    $serverCommand = [PHP_BINARY];
    $coveragePrepend = getenv('LINKVAULT_COVERAGE_PREPEND');
    if (is_string($coveragePrepend) && $coveragePrepend !== '') {
        $serverCommand[] = '-d';
        $serverCommand[] = 'xdebug.mode=coverage';
        $serverCommand[] = '-d';
        $serverCommand[] = 'auto_prepend_file=' . $coveragePrepend;
        $environment['LINKVAULT_COVERAGE_PREPEND'] = $coveragePrepend;
        $coverageOutput = getenv('LINKVAULT_COVERAGE_OUTPUT');
        if (is_string($coverageOutput) && $coverageOutput !== '') {
            $environment['LINKVAULT_COVERAGE_OUTPUT'] = $coverageOutput;
        }
    }
    array_push($serverCommand, '-S', '127.0.0.1:' . $port, '-t', $root . '/public', $root . '/public/router.php');
    $process = proc_open(
        $serverCommand,
        [0 => ['pipe', 'r'], 1 => ['file', $serverOutput, 'a'], 2 => ['file', $serverOutput, 'a']],
        $pipes,
        $root,
        $environment,
        ['bypass_shell' => true]
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start the PHP development server.');
    }
    fclose($pipes[0]);

    return $process;
}

function stop_server($process): void
{
    if (!is_resource($process)) {
        return;
    }

    $status = proc_get_status($process);
    if (PHP_OS_FAMILY === 'Windows') {
        $pid = max(0, (int)($status['pid'] ?? 0));
        if (!empty($status['running']) && $pid > 0) {
            exec('taskkill /PID ' . $pid . ' /T /F >NUL 2>&1');
            for ($attempt = 0; $attempt < 40; $attempt++) {
                usleep(50000);
                if (empty(proc_get_status($process)['running'])) {
                    break;
                }
            }
        }
        if (empty(proc_get_status($process)['running'])) {
            proc_close($process);
        }
        return;
    }

    if (!empty($status['running'])) {
        proc_terminate($process);
        for ($attempt = 0; $attempt < 20; $attempt++) {
            usleep(50000);
            $status = proc_get_status($process);
            if (empty($status['running'])) {
                break;
            }
        }
    }
    if (empty(proc_get_status($process)['running'])) {
        proc_close($process);
    }
}

function start_parallel_redirects(array $baseUrls, int $count): array
{
    $code = <<<'PHP'
$context = stream_context_create(['http' => ['ignore_errors' => true, 'follow_location' => 0, 'timeout' => 15]]);
@file_get_contents($argv[1], false, $context);
$status = 0;
foreach ($http_response_header ?? [] as $line) {
    if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $line, $matches)) {
        $status = (int)$matches[1];
    }
}
exit($status === 302 ? 0 : 1);
PHP;
    $children = [];
    for ($index = 0; $index < $count; $index++) {
        $url = $baseUrls[$index % count($baseUrls)] . '/active01';
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, '-r', $code, $url],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            null,
            ['bypass_shell' => true]
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start a concurrent redirect request.');
        }
        fclose($pipes[0]);
        $children[] = [$process, $pipes];
    }

    $exitCodes = [];
    foreach ($children as [$process, $pipes]) {
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCodes[] = proc_close($process);
    }

    return $exitCodes;
}

$root = dirname(__DIR__);
require $root . '/lib/database_schema.php';
require $root . '/lib/operational_status.php';
$password = 'SmokeTest!234';
$apiToken = 'smoke-api-token-234567890';
$testDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'linkvault-smoke-' . bin2hex(random_bytes(6));
$restoreDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'linkvault-restore-' . bin2hex(random_bytes(6));
$remoteRestoreDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'linkvault-remote-restore-' . bin2hex(random_bytes(6));
$databasePath = $testDirectory . DIRECTORY_SEPARATOR . 'linkvault.sqlite';
$logPath = $testDirectory . DIRECTORY_SEPARATOR . 'application.log';
$analyticsLogPath = $testDirectory . DIRECTORY_SEPARATOR . 'analytics-access.log';
$analyticsStatePath = $testDirectory . DIRECTORY_SEPARATOR . 'analytics-state.json';
$syntheticStatusPath = $testDirectory . DIRECTORY_SEPARATOR . 'synthetic-monitor-state.json';
$serverOutput = $testDirectory . DIRECTORY_SEPARATOR . 'server.log';
$serverProcesses = [];
$exitCode = 0;

try {
    require __DIR__ . '/smoke/operations_setup.php';
    require __DIR__ . '/smoke/operations_http.php';
    require __DIR__ . '/smoke/authentication_session.php';
    require __DIR__ . '/smoke/public_report.php';
    require __DIR__ . '/smoke/link_access.php';
    require __DIR__ . '/smoke/analytics.php';
    require __DIR__ . '/smoke/api.php';
    require __DIR__ . '/smoke/authentication_security.php';
    require __DIR__ . '/smoke/link_management.php';
    require __DIR__ . '/smoke/import_export.php';
    require __DIR__ . '/smoke/operations_cleanup.php';

    fwrite(STDOUT, "Smoke tests passed: migrations, access controls, confirmation links, scoped exports, saved filters, audit, retention, UX, and concurrent redirects." . PHP_EOL);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Smoke test failed in ' . basename($exception->getFile()) . ' at line ' . $exception->getLine() . ': ' . $exception->getMessage() . PHP_EOL);
    $exitCode = 1;
} finally {
    foreach ($serverProcesses as $serverProcess) {
        stop_server($serverProcess);
    }
    remove_tree($testDirectory);
    remove_tree($restoreDirectory);
    remove_tree($remoteRestoreDirectory);
}

exit($exitCode);
