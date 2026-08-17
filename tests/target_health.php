<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/TargetHealthService.php';
require dirname(__DIR__) . '/app/bootstrap.php';
require dirname(__DIR__) . '/app/LinkService.php';

function target_health_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

final class TargetHealthFakeResolver implements TargetHealthResolver
{
    /** @param array<string, list<string>> $answers */
    public function __construct(private readonly array $answers)
    {
    }

    public int $calls = 0;

    public function resolve(string $host): array
    {
        $this->calls++;
        return $this->answers[$host] ?? ['93.184.216.34'];
    }
}

final class TargetHealthFakeTransport implements TargetHealthTransport
{
    /** @param list<array<string, mixed>> $responses */
    public function __construct(private array $responses)
    {
    }

    /** @var list<array{url: string, method: string, ip: string}> */
    public array $requests = [];

    public function request(string $url, string $method, string $host, int $port, string $pinnedIp, array $limits): array
    {
        $this->requests[] = ['url' => $url, 'method' => $method, 'ip' => $pinnedIp];
        $response = array_shift($this->responses) ?: ['status' => 204];
        return array_merge([
            'ok' => true,
            'status' => 204,
            'headers' => [],
            'primary_ip' => $pinnedIp,
            'effective_url' => $url,
            'latency_ms' => 4,
            'error_no' => 0,
            'error' => '',
        ], $response);
    }
}

final class TargetHealthMutatingTransport implements TargetHealthTransport
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function request(string $url, string $method, string $host, int $port, string $pinnedIp, array $limits): array
    {
        $this->pdo->exec("UPDATE links SET target_url = 'https://changed.test/', updated_at = '2099-01-01T00:00:00Z' WHERE id = 1");
        return [
            'ok' => true,
            'status' => 204,
            'headers' => [],
            'primary_ip' => $pinnedIp,
            'effective_url' => $url,
            'latency_ms' => 1,
            'error_no' => 0,
            'error' => '',
        ];
    }
}

function target_health_database(): PDO
{
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec(<<<'SQL'
        CREATE TABLE links (
            id INTEGER PRIMARY KEY,
            slug TEXT NOT NULL DEFAULT '',
            target_url TEXT NOT NULL,
            title TEXT NOT NULL DEFAULT '',
            clicks INTEGER NOT NULL DEFAULT 0,
            is_active INTEGER NOT NULL,
            starts_at TEXT DEFAULT NULL,
            expires_at TEXT DEFAULT NULL,
            max_clicks INTEGER DEFAULT NULL,
            is_one_time INTEGER NOT NULL DEFAULT 0,
            deleted_at TEXT DEFAULT NULL,
            created_at TEXT NOT NULL DEFAULT '2026-01-01T00:00:00Z',
            updated_at TEXT NOT NULL
        );
        CREATE TABLE link_tags (link_id INTEGER NOT NULL, tag TEXT NOT NULL);
        CREATE TABLE target_health (
            link_id INTEGER PRIMARY KEY,
            target_url_hash TEXT NOT NULL,
            state TEXT NOT NULL,
            reason TEXT NOT NULL,
            checked_at TEXT NOT NULL,
            next_check_at TEXT NOT NULL,
            last_healthy_at TEXT DEFAULT NULL,
            http_status INTEGER DEFAULT NULL,
            latency_ms INTEGER DEFAULT NULL,
            effective_url TEXT DEFAULT NULL,
            redirect_count INTEGER NOT NULL,
            redirect_state TEXT NOT NULL,
            consecutive_failures INTEGER NOT NULL,
            redirect_chain_json TEXT NOT NULL DEFAULT '[]',
            ignored_at TEXT DEFAULT NULL,
            ignored_reason TEXT NOT NULL DEFAULT '',
            FOREIGN KEY (link_id) REFERENCES links(id) ON DELETE CASCADE
        );
    SQL);
    return $pdo;
}

function target_health_service(array $answers, array $responses): array
{
    $resolver = new TargetHealthFakeResolver($answers);
    $transport = new TargetHealthFakeTransport($responses);
    $service = new TargetHealthService(new PDO('sqlite::memory:'), [
        'target_health_allowed_ports' => ['80', '443'],
        'target_health_max_redirects' => 5,
        'target_health_interval_seconds' => 900,
        'target_health_connect_timeout_ms' => 3000,
        'target_health_hop_timeout_ms' => 8000,
        'target_health_total_timeout_ms' => 30000,
    ], $resolver, $transport);
    return [$service, $resolver, $transport];
}

try {
    foreach ([
        '0.0.0.0', '10.1.2.3', '100.64.1.1', '127.0.0.1', '169.254.1.1',
        '172.16.0.1', '192.168.1.1', '192.0.2.1', '198.18.0.1', '198.51.100.1',
        '203.0.113.1', '224.0.0.1', '240.0.0.1',
        '::', '::1', '::ffff:192.0.2.1', 'fc00::1', 'fe80::1', 'ff02::1',
        '2001:db8::1', '2001:2::1', '2001:0::1', '2002::1', '64:ff9b::c000:201',
    ] as $blocked) {
        target_health_assert(!TargetHealthService::isGloballyRoutableIp($blocked), 'Allowed blocked address: ' . $blocked);
    }
    foreach (['1.1.1.1', '93.184.216.34', '2606:4700:4700::1111', '2001:4860:4860::8888'] as $allowed) {
        target_health_assert(TargetHealthService::isGloballyRoutableIp($allowed), 'Rejected public address: ' . $allowed);
    }

    [$service, $resolver, $transport] = target_health_service(
        ['mixed.test' => ['93.184.216.34', '10.0.0.1']],
        []
    );
    $result = $service->checkUrl('https://mixed.test/path');
    target_health_assert($result['state'] === 'anomaly' && $result['reason'] === 'mixed_dns_blocked', 'Mixed DNS answers were not blocked.');
    target_health_assert($transport->requests === [], 'A blocked DNS answer still reached the transport.');

    foreach ([
        'https://user:secret@public.test/' => 'userinfo_forbidden',
        'https://public.test/path#fragment' => 'fragment_forbidden',
        'https://public.test:8443/path' => 'unsafe_port',
    ] as $unsafeUrl => $expectedReason) {
        [$service, $resolver, $transport] = target_health_service(['public.test' => ['93.184.216.34']], []);
        $result = $service->checkUrl($unsafeUrl);
        target_health_assert($result['state'] === 'anomaly' && $result['reason'] === $expectedReason,
            'Unsafe URL policy was not enforced: ' . $expectedReason);
        target_health_assert($transport->requests === [], 'An unsafe URL reached the transport: ' . $expectedReason);
    }

    [$service, $resolver, $transport] = target_health_service(
        ['public.test' => ['93.184.216.34'], 'private.test' => ['192.168.1.7']],
        [['status' => 302, 'headers' => ['location' => ['http://private.test/secret']]]]
    );
    $result = $service->checkUrl('https://public.test/start');
    target_health_assert($result['state'] === 'anomaly' && $result['reason'] === 'private_redirect'
        && $result['redirect_state'] === 'private_redirect', 'Private redirects were not blocked.');
    target_health_assert(count($transport->requests) === 1, 'Private redirect caused a network request.');

    [$service, $resolver, $transport] = target_health_service(
        ['loop.test' => ['93.184.216.34']],
        [['status' => 302, 'headers' => ['location' => ['/loop']]]]
    );
    $result = $service->checkUrl('https://loop.test/loop');
    target_health_assert($result['state'] === 'anomaly' && $result['reason'] === 'redirect_loop', 'Redirect loops were not detected.');

    [$service, $resolver, $transport] = target_health_service(
        ['secure.test' => ['93.184.216.34']],
        [['status' => 302, 'headers' => ['location' => ['http://secure.test/down']]]]
    );
    $result = $service->checkUrl('https://secure.test/start');
    target_health_assert($result['reason'] === 'https_downgrade' && $result['redirect_state'] === 'downgrade', 'HTTPS downgrade was not detected.');

    [$service, $resolver, $transport] = target_health_service(
        ['source.test' => ['93.184.216.34'], 'other.test' => ['1.1.1.1']],
        [['status' => 302, 'headers' => ['location' => ['https://other.test/final']]]]
    );
    $result = $service->checkUrl('https://source.test/start');
    target_health_assert($result['reason'] === 'cross_origin_redirect' && $result['redirect_state'] === 'cross_origin', 'Cross-origin redirects were not detected.');

    [$service, $resolver, $transport] = target_health_service(
        ['pin.test' => ['93.184.216.34']],
        [['status' => 204, 'primary_ip' => '1.1.1.1']]
    );
    $result = $service->checkUrl('https://pin.test/');
    target_health_assert($result['reason'] === 'primary_ip_mismatch', 'A cURL primary-IP mismatch was not detected.');

    [$service, $resolver, $transport] = target_health_service(
        ['cert.test' => ['93.184.216.34']],
        [[
            'ok' => false,
            'status' => null,
            'error_no' => defined('CURLE_PEER_FAILED_VERIFICATION') ? CURLE_PEER_FAILED_VERIFICATION : 60,
            'error' => 'certificate verify failed',
        ]]
    );
    $result = $service->checkUrl('https://cert.test/');
    target_health_assert($result['state'] === 'error' && $result['reason'] === 'certificate_error',
        'Certificate failures were not classified: ' . (string)$result['reason']);

    [$service, $resolver, $transport] = target_health_service(
        ['multi.test' => ['93.184.216.34', '1.1.1.1']],
        [[
            'ok' => false,
            'status' => null,
            'error_no' => 7,
            'error' => 'connection refused',
        ], ['status' => 204]]
    );
    $result = $service->checkUrl('https://multi.test/');
    target_health_assert(
        $result['state'] === 'healthy'
            && array_column($transport->requests, 'ip') === ['93.184.216.34', '1.1.1.1'],
        'Target health did not retry another validated DNS address after a transport failure.'
    );

    [$service, $resolver, $transport] = target_health_service(
        ['multi-http.test' => ['93.184.216.34', '1.1.1.1']],
        [['status' => 503], ['status' => 204]]
    );
    $result = $service->checkUrl('https://multi-http.test/');
    target_health_assert(
        $result['state'] === 'healthy'
            && array_column($transport->requests, 'ip') === ['93.184.216.34', '1.1.1.1'],
        'Target health did not retry another validated DNS address after a 5xx response.'
    );

    [$service, $resolver, $transport] = target_health_service(
        ['drift.test' => ['93.184.216.34']],
        [['status' => 204, 'effective_url' => 'https://drift.test/other']]
    );
    $result = $service->checkUrl('https://drift.test/expected');
    target_health_assert($result['reason'] === 'effective_url_drift'
        && $result['redirect_state'] === 'destination_drift', 'Effective destination drift was not detected.');

    [$service, $resolver, $transport] = target_health_service(
        ['head.test' => ['93.184.216.34']],
        [['status' => 405], ['status' => 204]]
    );
    $result = $service->checkUrl('https://head.test/a/b');
    target_health_assert($result['state'] === 'healthy' && count($transport->requests) === 2
        && $transport->requests[0]['method'] === 'HEAD' && $transport->requests[1]['method'] === 'GET', 'HEAD fallback was not bounded and ordered.');
    target_health_assert(
        TargetHealthService::resolveRedirectUrl('https://head.test/a/b/', '../ok?x=1') === 'https://head.test/a/ok?x=1',
        'Relative redirect resolution is incorrect.'
    );

    $batchPdo = target_health_database();
    $batchPdo->exec(<<<'SQL'
        INSERT INTO links (id, target_url, is_active, deleted_at, updated_at) VALUES
            (1, 'https://one.test/', 1, NULL, '2026-01-01T00:00:00Z'),
            (2, 'https://two.test/', 1, NULL, '2026-01-01T00:00:00Z'),
            (3, 'https://inactive.test/', 0, NULL, '2026-01-01T00:00:00Z'),
            (4, 'https://deleted.test/', 1, '2026-01-01T00:00:00Z', '2026-01-01T00:00:00Z');
    SQL);
    $batchTransport = new TargetHealthFakeTransport([['status' => 204]]);
    $batchService = new TargetHealthService($batchPdo, [
        'target_health_allowed_ports' => ['80', '443'],
        'target_health_batch_size' => 1,
    ], new TargetHealthFakeResolver([]), $batchTransport);
    $batch = $batchService->runBatch();
    target_health_assert($batch['processed'] === 1 && $batch['backlog'] === 1
        && count($batchTransport->requests) === 1, 'The due batch was not bounded to active, nondeleted links.');
    $checkedLinkId = (int)$batchPdo->query('SELECT link_id FROM target_health')->fetchColumn();
    $batchPdo->exec("UPDATE target_health SET state = 'anomaly', reason = 'private_address' WHERE link_id = " . $checkedLinkId);
    $linkService = new LinkService($batchPdo);
    $maintenanceCounts = $linkService->maintenanceCounts();
    $maintenance = $linkService->listForMaintenance('target_health', '', 1, 100);
    $latest = $linkService->targetHealthForLink($checkedLinkId);
    target_health_assert(($maintenanceCounts['target_health'] ?? 0) === 1
        && ($maintenance['total'] ?? 0) === 1
        && ($maintenance['links'][0]['target_health_reason'] ?? null) === 'private_address'
        && ($latest['state'] ?? null) === 'anomaly', 'Target health maintenance/detail integration is incomplete.');

    $racePdo = target_health_database();
    $racePdo->exec("INSERT INTO links (id, target_url, is_active, deleted_at, updated_at) VALUES (1, 'https://race.test/', 1, NULL, '2026-01-01T00:00:00Z')");
    $raceService = new TargetHealthService($racePdo, [
        'target_health_allowed_ports' => ['80', '443'],
        'target_health_batch_size' => 1,
    ], new TargetHealthFakeResolver(['race.test' => ['93.184.216.34']]), new TargetHealthMutatingTransport($racePdo));
    $raceService->runBatch();
    target_health_assert(
        (int)$racePdo->query('SELECT COUNT(*) FROM target_health')->fetchColumn() === 0,
        'A result was stored after the captured target URL hash changed.'
    );

    $casPdo = target_health_database();
    $casPdo->exec("INSERT INTO links (id, target_url, is_active, deleted_at, updated_at) VALUES (1, 'https://cas.test/', 1, NULL, '2026-01-01T00:00:00Z')");
    $casService = new TargetHealthService($casPdo, ['target_health_allowed_ports' => ['80', '443']]);
    $storeResult = new ReflectionMethod(TargetHealthService::class, 'storeResultIfCurrent');
    $failure = [
        'state' => 'unhealthy', 'reason' => 'http_5xx', 'http_status' => 503,
        'latency_ms' => 1, 'effective_url' => 'https://cas.test/', 'redirect_count' => 0,
        'redirect_state' => 'none', 'redirect_chain' => [],
    ];
    $storedFirst = $storeResult->invoke($casService, 1, 'https://cas.test/', hash('sha256', 'https://cas.test/'), null, $failure);
    $storedStale = $storeResult->invoke($casService, 1, 'https://cas.test/', hash('sha256', 'https://cas.test/'), null, $failure);
    target_health_assert(
        $storedFirst === true && $storedStale === false
            && (int)$casPdo->query('SELECT consecutive_failures FROM target_health WHERE link_id = 1')->fetchColumn() === 1,
        'A stale concurrent health result incremented consecutive failures twice.'
    );

    fwrite(STDOUT, 'Target health policy tests passed.' . PHP_EOL);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Target health policy test failed at line ' . $exception->getLine() . ': ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
