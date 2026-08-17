<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/operational_status.php';

interface TargetHealthResolver
{
    /** @return list<string> */
    public function resolve(string $host): array;
}

interface TargetHealthTransport
{
    /**
     * @param array{connect_timeout_ms: int, timeout_ms: int, header_max_bytes: int, body_max_bytes: int} $limits
     * @return array<string, mixed>
     */
    public function request(string $url, string $method, string $host, int $port, string $pinnedIp, array $limits): array;
}

final class TargetHealthDnsResolver implements TargetHealthResolver
{
    #[\Override]
    public function resolve(string $host): array
    {
        return array_keys($this->resolveName(strtolower(rtrim($host, '.')), [], 0));
    }

    /** @param array<string, true> $visited @return array<string, true> */
    private function resolveName(string $host, array $visited, int $depth): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $packed = @inet_pton($host);
            $normalized = is_string($packed) ? @inet_ntop($packed) : false;
            return is_string($normalized) ? [$normalized => true] : [];
        }
        if ($depth > 8 || isset($visited[$host])
            || !filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            return [];
        }
        $visited[$host] = true;

        $records = @dns_get_record($host, DNS_A | DNS_AAAA | DNS_CNAME);
        if (!is_array($records)) {
            return [];
        }
        $addresses = [];
        foreach ($records as $record) {
            $target = strtolower(rtrim((string)($record['target'] ?? ''), '.'));
            if (($record['type'] ?? null) === 'CNAME' && $target !== '') {
                foreach ($this->resolveName($target, $visited, $depth + 1) as $resolved => $_true) {
                    $addresses[$resolved] = true;
                }
                continue;
            }
            $address = $record['ip'] ?? $record['ipv6'] ?? null;
            if (!is_string($address) || !filter_var($address, FILTER_VALIDATE_IP)) {
                continue;
            }
            $packed = @inet_pton($address);
            $normalized = is_string($packed) ? @inet_ntop($packed) : false;
            if (is_string($normalized)) {
                $addresses[$normalized] = true;
            }
        }
        return $addresses;
    }
}

final class TargetHealthCurlTransport implements TargetHealthTransport
{
    #[\Override]
    public function request(string $url, string $method, string $host, int $port, string $pinnedIp, array $limits): array
    {
        if (!extension_loaded('curl')) {
            throw new RuntimeException('The curl extension is required for target health checks.');
        }

        $handle = curl_init();
        if ($handle === false) {
            throw new RuntimeException('Cannot initialize cURL.');
        }
        $headers = [];
        $headerBytes = 0;
        $bodyBytes = 0;
        $headerLimitExceeded = false;
        $bodyLimitExceeded = false;
        $resolveHost = str_contains($host, ':') ? '[' . $host . ']' : $host;
        $resolveAddress = str_contains($pinnedIp, ':') ? '[' . $pinnedIp . ']' : $pinnedIp;
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_NOBODY => $method === 'HEAD',
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_CONNECTTIMEOUT_MS => max(1, $limits['connect_timeout_ms']),
            CURLOPT_TIMEOUT_MS => max(1, $limits['timeout_ms']),
            CURLOPT_NOSIGNAL => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROXY => '',
            CURLOPT_HTTPAUTH => CURLAUTH_NONE,
            CURLOPT_UNRESTRICTED_AUTH => false,
            CURLOPT_USERAGENT => 'LinkVault-TargetHealth/1.0',
            CURLOPT_HTTPHEADER => ['Accept: */*', 'Accept-Encoding: identity'],
            CURLOPT_RESOLVE => [$resolveHost . ':' . $port . ':' . $resolveAddress],
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (
                &$headers,
                &$headerBytes,
                &$headerLimitExceeded,
                $limits
            ): int {
                $length = strlen($line);
                $headerBytes += $length;
                if ($headerBytes > $limits['header_max_bytes']) {
                    $headerLimitExceeded = true;
                    return 0;
                }
                if (preg_match('/^HTTP\/\S+\s+\d{3}/i', $line) === 1) {
                    $headers = [];
                    return $length;
                }
                $separator = strpos($line, ':');
                if ($separator === false) {
                    return $length;
                }
                $name = strtolower(trim(substr($line, 0, $separator)));
                if (preg_match('/^[a-z0-9!#$%&\'*+.^_`|~-]+$/D', $name) !== 1) {
                    return $length;
                }
                $headers[$name][] = trim(substr($line, $separator + 1));
                return $length;
            },
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (
                &$bodyBytes,
                &$bodyLimitExceeded,
                $limits
            ): int {
                $length = strlen($chunk);
                $bodyBytes += $length;
                if ($bodyBytes > $limits['body_max_bytes']) {
                    $bodyLimitExceeded = true;
                    return 0;
                }
                return $length;
            },
        ];
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }

        try {
            if (!curl_setopt_array($handle, $options)) {
                throw new RuntimeException('Cannot configure cURL target health request.');
            }
            $ok = curl_exec($handle) !== false;
            $errorNumber = curl_errno($handle);
            $error = curl_error($handle);
            $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $primaryIp = (string)curl_getinfo($handle, CURLINFO_PRIMARY_IP);
            $effectiveUrl = (string)curl_getinfo($handle, CURLINFO_EFFECTIVE_URL);
            $latencyMs = max(0, (int)round((float)curl_getinfo($handle, CURLINFO_TOTAL_TIME) * 1000));
        } finally {
            curl_close($handle);
        }

        return [
            'ok' => $ok,
            'status' => $status > 0 ? $status : null,
            'headers' => $headers,
            'primary_ip' => $primaryIp,
            'effective_url' => $effectiveUrl,
            'latency_ms' => $latencyMs,
            'error_no' => $errorNumber,
            'error' => $error,
            'header_limit_exceeded' => $headerLimitExceeded,
            'body_limit_exceeded' => $bodyLimitExceeded,
        ];
    }
}

final class TargetHealthPolicyViolation extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}

final class TargetHealthService
{
    private const MAX_ADDRESS_ATTEMPTS = 4;
    private readonly TargetHealthResolver $resolver;
    private readonly TargetHealthTransport $transport;
    /** @var list<int> */
    private readonly array $allowedPorts;
    private readonly int $intervalSeconds;
    private readonly int $batchSize;
    private readonly int $connectTimeoutMs;
    private readonly int $hopTimeoutMs;
    private readonly int $totalTimeoutMs;
    private readonly int $maxRedirects;
    private readonly int $headerMaxBytes;
    private readonly int $bodyMaxBytes;

    public function __construct(
        private readonly PDO $pdo,
        private readonly array $config,
        ?TargetHealthResolver $resolver = null,
        ?TargetHealthTransport $transport = null
    ) {
        $this->resolver = $resolver ?? new TargetHealthDnsResolver();
        $this->transport = $transport ?? new TargetHealthCurlTransport();
        $ports = [];
        $configuredPorts = (array)($config['target_health_allowed_ports'] ?? ['80', '443']);
        if (!$configuredPorts || count($configuredPorts) > 32) {
            throw new InvalidArgumentException('Target health allowed ports must contain 1-32 entries.');
        }
        foreach ($configuredPorts as $port) {
            if ((!is_int($port) && !is_string($port)) || !ctype_digit((string)$port)
                || (int)$port < 1 || (int)$port > 65535 || isset($ports[(int)$port])) {
                throw new InvalidArgumentException('Target health allowed ports are invalid or duplicated.');
            }
            $ports[(int)$port] = true;
        }
        $this->allowedPorts = array_map('intval', array_keys($ports));
        $this->intervalSeconds = max(60, min(604800, (int)($config['target_health_interval_seconds'] ?? 900)));
        $this->batchSize = max(1, min(500, (int)($config['target_health_batch_size'] ?? 50)));
        $this->connectTimeoutMs = max(100, min(30000, (int)($config['target_health_connect_timeout_ms'] ?? 3000)));
        $this->hopTimeoutMs = max(500, min(60000, (int)($config['target_health_hop_timeout_ms'] ?? 8000)));
        $this->totalTimeoutMs = max($this->hopTimeoutMs, min(300000, (int)($config['target_health_total_timeout_ms'] ?? 30000)));
        $this->maxRedirects = max(0, min(10, (int)($config['target_health_max_redirects'] ?? 5)));
        $this->headerMaxBytes = max(1024, min(262144, (int)($config['target_health_header_max_bytes'] ?? 32768)));
        $this->bodyMaxBytes = max(1024, min(1048576, (int)($config['target_health_body_max_bytes'] ?? 65536)));
    }

    /** @return array{processed: int, healthy: int, issues: int, backlog: int} */
    public function runBatch(): array
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $due = $this->pdo->prepare(<<<'SQL'
            SELECT l.id, l.target_url, h.checked_at AS health_checked_at
            FROM links l
            LEFT JOIN target_health h ON h.link_id = l.id
            WHERE l.deleted_at IS NULL
              AND l.is_active = 1
              AND (
                  h.link_id IS NULL
                  OR julianday(h.next_check_at) <= julianday(:due_at)
                  OR julianday(h.checked_at) < julianday(l.updated_at)
              )
            ORDER BY COALESCE(h.next_check_at, '') ASC, l.id ASC
            LIMIT :batch_size
        SQL);
        $due->bindValue(':due_at', $now);
        $due->bindValue(':batch_size', $this->batchSize, PDO::PARAM_INT);
        $due->execute();
        $links = $due->fetchAll();

        $processed = 0;
        $healthy = 0;
        $issues = 0;
        foreach ($links as $link) {
            $targetUrl = (string)$link['target_url'];
            $targetHash = hash('sha256', $targetUrl);
            $result = $this->checkUrl($targetUrl);
            $processed++;
            if ($result['state'] === 'healthy') {
                $healthy++;
            } else {
                $issues++;
            }
            $this->storeResultIfCurrent(
                (int)$link['id'],
                $targetUrl,
                $targetHash,
                is_string($link['health_checked_at'] ?? null) ? $link['health_checked_at'] : null,
                $result
            );
        }

        $backlog = $this->dueBacklog(gmdate('Y-m-d\TH:i:s\Z'));
        $marker = [
            'version' => 1,
            'status' => 'success',
            'completed_at' => time(),
            'processed' => $processed,
            'healthy' => $healthy,
            'issues' => $issues,
            'backlog' => $backlog,
        ];
        $statusPath = (string)($this->config['target_health_status_path'] ?? '');
        if ($statusPath !== '') {
            linkvault_write_json_marker($statusPath, $marker);
        }
        return compact('processed', 'healthy', 'issues', 'backlog');
    }

    /** @param list<int> $ids @return array{processed: int, healthy: int, issues: int} */
    public function checkSelected(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        if (!$ids || count($ids) > 50) {
            throw new InvalidArgumentException('Select between 1 and 50 links.');
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $statement = $this->pdo->prepare(
            "SELECT l.id, l.target_url, h.checked_at AS health_checked_at
             FROM links l LEFT JOIN target_health h ON h.link_id = l.id
             WHERE l.id IN ({$placeholders}) AND l.deleted_at IS NULL AND l.is_active = 1 ORDER BY l.id"
        );
        $statement->execute($ids);
        $processed = 0;
        $healthy = 0;
        $issues = 0;
        foreach ($statement->fetchAll() as $link) {
            $targetUrl = (string)$link['target_url'];
            $result = $this->checkUrl($targetUrl);
            $processed++;
            $result['state'] === 'healthy' ? $healthy++ : $issues++;
            $this->storeResultIfCurrent(
                (int)$link['id'],
                $targetUrl,
                hash('sha256', $targetUrl),
                is_string($link['health_checked_at'] ?? null) ? $link['health_checked_at'] : null,
                $result
            );
        }
        return compact('processed', 'healthy', 'issues');
    }

    /** @return array<string, mixed> */
    public function checkUrl(string $url): array
    {
        $result = [
            'state' => 'error',
            'reason' => 'unknown_error',
            'http_status' => null,
            'latency_ms' => null,
            'effective_url' => null,
            'redirect_count' => 0,
            'redirect_state' => 'none',
            'redirect_chain' => [],
        ];
        $startedAt = microtime(true);
        $visited = [];
        $currentUrl = $url;
        $networkLatency = 0;
        $networkAttempted = false;

        while (true) {
            if ((microtime(true) - $startedAt) * 1000 >= $this->totalTimeoutMs) {
                return $this->finish($result, 'error', 'total_timeout', $networkLatency, $networkAttempted);
            }
            try {
                $current = $this->parseUrl($currentUrl);
                $addresses = $this->validatedAddresses($current['host']);
            } catch (TargetHealthPolicyViolation $exception) {
                return $this->finish($result, 'anomaly', $exception->reason, $networkLatency, $networkAttempted);
            } catch (Throwable) {
                return $this->finish($result, 'error', 'dns_failure', $networkLatency, $networkAttempted);
            }
            if ((microtime(true) - $startedAt) * 1000 >= $this->totalTimeoutMs) {
                return $this->finish($result, 'error', 'total_timeout', $networkLatency, $networkAttempted);
            }

            $visited[$current['signature']] = true;
            $attempt = $this->requestAddresses($current, 'HEAD', $addresses, $startedAt);
            $response = $attempt['response'];
            $pinnedIp = $attempt['pinned_ip'];
            $networkAttempted = $networkAttempted || (int)$attempt['request_count'] > 0;
            $networkLatency += (int)$attempt['latency_ms'];
            $result['effective_url'] = $current['canonical'];
            $result['http_status'] = is_int($response['status'] ?? null) ? $response['status'] : null;
            if (!empty($attempt['total_timeout'])) {
                return $this->finish($result, 'error', 'total_timeout', $networkLatency, $networkAttempted);
            }
            if (empty($response['ok'])) {
                return $this->transportFailure($result, $response, $networkLatency);
            }
            if (!$this->sameIp((string)($response['primary_ip'] ?? ''), $pinnedIp)) {
                $result['redirect_state'] = 'pin_mismatch';
                return $this->finish($result, 'anomaly', 'primary_ip_mismatch', $networkLatency, true);
            }
            if (!$this->sameDestination((string)($response['effective_url'] ?? ''), $current)) {
                $result['redirect_state'] = 'destination_drift';
                return $this->finish($result, 'anomaly', 'effective_url_drift', $networkLatency, true);
            }

            $status = (int)($response['status'] ?? 0);
            if (in_array($status, [405, 501], true)) {
                try {
                    $addresses = $this->validatedAddresses($current['host']);
                } catch (TargetHealthPolicyViolation $exception) {
                    return $this->finish($result, 'anomaly', $exception->reason, $networkLatency, true);
                } catch (Throwable) {
                    return $this->finish($result, 'error', 'dns_failure', $networkLatency, true);
                }
                if ((microtime(true) - $startedAt) * 1000 >= $this->totalTimeoutMs) {
                    return $this->finish($result, 'error', 'total_timeout', $networkLatency, true);
                }
                $attempt = $this->requestAddresses($current, 'GET', $addresses, $startedAt);
                $response = $attempt['response'];
                $pinnedIp = $attempt['pinned_ip'];
                $networkLatency += (int)$attempt['latency_ms'];
                $result['http_status'] = is_int($response['status'] ?? null) ? $response['status'] : null;
                if (!empty($attempt['total_timeout'])) {
                    return $this->finish($result, 'error', 'total_timeout', $networkLatency, true);
                }
                if (empty($response['ok'])) {
                    return $this->transportFailure($result, $response, $networkLatency);
                }
                if (!$this->sameIp((string)($response['primary_ip'] ?? ''), $pinnedIp)) {
                    $result['redirect_state'] = 'pin_mismatch';
                    return $this->finish($result, 'anomaly', 'primary_ip_mismatch', $networkLatency, true);
                }
                if (!$this->sameDestination((string)($response['effective_url'] ?? ''), $current)) {
                    $result['redirect_state'] = 'destination_drift';
                    return $this->finish($result, 'anomaly', 'effective_url_drift', $networkLatency, true);
                }
                $status = (int)($response['status'] ?? 0);
            }

            $result['redirect_chain'][] = ['url' => $current['canonical'], 'status' => $status];

            if ($status >= 200 && $status < 300) {
                return $this->finish($result, 'healthy', 'http_success', $networkLatency, true);
            }
            if ($status >= 400 && $status < 500) {
                return $this->finish($result, 'unhealthy', 'http_4xx', $networkLatency, true);
            }
            if ($status >= 500 && $status < 600) {
                return $this->finish($result, 'unhealthy', 'http_5xx', $networkLatency, true);
            }
            if ($status < 300 || $status >= 400) {
                return $this->finish($result, 'error', 'invalid_http_status', $networkLatency, true);
            }

            $result['redirect_count']++;
            if ($result['redirect_count'] > $this->maxRedirects) {
                $result['redirect_state'] = 'too_many';
                return $this->finish($result, 'anomaly', 'too_many_redirects', $networkLatency, true);
            }
            $locations = $response['headers']['location'] ?? [];
            if (!is_array($locations) || count($locations) !== 1 || !is_string($locations[0]) || $locations[0] === '') {
                $result['redirect_state'] = 'invalid_location';
                return $this->finish($result, 'anomaly', 'invalid_redirect_location', $networkLatency, true);
            }
            try {
                $nextUrl = self::resolveRedirectUrl($current['canonical'], $locations[0]);
                $next = $this->parseUrl($nextUrl);
                $this->validatedAddresses($next['host']);
            } catch (TargetHealthPolicyViolation $exception) {
                $privateReason = in_array($exception->reason, ['private_address', 'mixed_dns_blocked'], true);
                $result['redirect_state'] = $privateReason ? 'private_redirect' : 'invalid_location';
                return $this->finish(
                    $result,
                    'anomaly',
                    $privateReason ? 'private_redirect' : 'redirect_' . $exception->reason,
                    $networkLatency,
                    true
                );
            } catch (Throwable) {
                $result['redirect_state'] = 'invalid_location';
                return $this->finish($result, 'error', 'redirect_dns_failure', $networkLatency, true);
            }
            if ((microtime(true) - $startedAt) * 1000 >= $this->totalTimeoutMs) {
                return $this->finish($result, 'error', 'total_timeout', $networkLatency, true);
            }
            if (isset($visited[$next['signature']])) {
                $result['redirect_state'] = 'loop';
                return $this->finish($result, 'anomaly', 'redirect_loop', $networkLatency, true);
            }
            if ($current['scheme'] === 'https' && $next['scheme'] === 'http') {
                $result['redirect_state'] = 'downgrade';
                return $this->finish($result, 'anomaly', 'https_downgrade', $networkLatency, true);
            }
            if ($current['origin'] !== $next['origin']) {
                $result['redirect_state'] = 'cross_origin';
                return $this->finish($result, 'anomaly', 'cross_origin_redirect', $networkLatency, true);
            }
            $result['redirect_state'] = 'followed';
            $currentUrl = $next['canonical'];
        }
    }

    public static function isGloballyRoutableIp(string $address): bool
    {
        $packed = @inet_pton($address);
        if (!is_string($packed)) {
            return false;
        }
        if (strlen($packed) === 4) {
            foreach ([
                ['0.0.0.0', 8], ['10.0.0.0', 8], ['100.64.0.0', 10], ['127.0.0.0', 8],
                ['169.254.0.0', 16], ['172.16.0.0', 12], ['192.0.0.0', 24], ['192.0.2.0', 24],
                ['192.88.99.0', 24], ['192.168.0.0', 16], ['198.18.0.0', 15], ['198.51.100.0', 24],
                ['203.0.113.0', 24], ['224.0.0.0', 4], ['240.0.0.0', 4],
            ] as [$network, $bits]) {
                if (self::packedMatchesPrefix($packed, (string)inet_pton($network), $bits)) {
                    return false;
                }
            }
            return true;
        }

        if (!self::packedMatchesPrefix($packed, (string)inet_pton('2000::'), 3)) {
            return false;
        }
        foreach ([
            ['64:ff9b::', 96], ['64:ff9b:1::', 48], ['100::', 64], ['2001::', 23],
            ['2001:db8::', 32],
            ['2002::', 16], ['3fff::', 20], ['5f00::', 16], ['fc00::', 7], ['fe80::', 10],
            ['ff00::', 8], ['::', 96], ['::ffff:0:0', 96],
        ] as [$network, $bits]) {
            if (self::packedMatchesPrefix($packed, (string)inet_pton($network), $bits)) {
                return false;
            }
        }
        return true;
    }

    public static function resolveRedirectUrl(string $baseUrl, string $location): string
    {
        if ($location === '' || strlen($location) > 2048
            || preg_match('/[\x00-\x20\x7f\\\\]/', $location) === 1) {
            throw new TargetHealthPolicyViolation('invalid_location');
        }
        $reference = parse_url($location);
        if ($reference === false) {
            throw new TargetHealthPolicyViolation('invalid_location');
        }
        if (isset($reference['scheme'])) {
            return $location;
        }
        $base = parse_url($baseUrl);
        if (!is_array($base) || !isset($base['scheme'], $base['host'])) {
            throw new TargetHealthPolicyViolation('invalid_location');
        }
        if (str_starts_with($location, '//')) {
            return strtolower((string)$base['scheme']) . ':' . $location;
        }

        $scheme = strtolower((string)$base['scheme']);
        $host = (string)$base['host'];
        $authority = str_contains($host, ':') && !str_starts_with($host, '[') ? '[' . $host . ']' : $host;
        if (isset($base['port'])) {
            $authority .= ':' . (int)$base['port'];
        }
        $basePath = (string)($base['path'] ?? '');
        if (str_starts_with($location, '?')) {
            return $scheme . '://' . $authority . ($basePath === '' ? '/' : $basePath) . $location;
        }
        if (str_starts_with($location, '#')) {
            return $scheme . '://' . $authority . ($basePath === '' ? '/' : $basePath)
                . (isset($base['query']) ? '?' . $base['query'] : '') . $location;
        }

        $fragmentPosition = strpos($location, '#');
        $fragment = $fragmentPosition === false ? '' : substr($location, $fragmentPosition);
        $withoutFragment = $fragmentPosition === false ? $location : substr($location, 0, $fragmentPosition);
        $queryPosition = strpos($withoutFragment, '?');
        $query = $queryPosition === false ? null : substr($withoutFragment, $queryPosition + 1);
        $referencePath = $queryPosition === false ? $withoutFragment : substr($withoutFragment, 0, $queryPosition);
        if ($referencePath === '') {
            $path = $basePath === '' ? '/' : $basePath;
            if ($query === null && isset($base['query'])) {
                $query = (string)$base['query'];
            }
        } elseif (str_starts_with($referencePath, '/')) {
            $path = self::removeDotSegments($referencePath);
        } else {
            $lastSlash = strrpos($basePath, '/');
            $merged = $basePath === ''
                ? '/' . $referencePath
                : ($lastSlash === false ? $referencePath : substr($basePath, 0, $lastSlash + 1) . $referencePath);
            $path = self::removeDotSegments($merged);
        }
        return $scheme . '://' . $authority . $path . ($query === null ? '' : '?' . $query) . $fragment;
    }

    /** @return array<string, mixed> */
    private function parseUrl(string $url): array
    {
        if ($url === '' || strlen($url) > 2048
            || preg_match('/[\x00-\x20\x7f\\\\]/', $url) === 1
            || !filter_var($url, FILTER_VALIDATE_URL)) {
            throw new TargetHealthPolicyViolation('invalid_url');
        }
        $parts = parse_url($url);
        if (!is_array($parts)) {
            throw new TargetHealthPolicyViolation('invalid_url');
        }
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new TargetHealthPolicyViolation('invalid_scheme');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new TargetHealthPolicyViolation('userinfo_forbidden');
        }
        if (isset($parts['fragment'])) {
            throw new TargetHealthPolicyViolation('fragment_forbidden');
        }
        $host = strtolower(rtrim((string)($parts['host'] ?? ''), '.'));
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }
        if ($host === '' || (!filter_var($host, FILTER_VALIDATE_IP)
            && !filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME))) {
            throw new TargetHealthPolicyViolation('invalid_host');
        }
        $port = isset($parts['port']) ? (int)$parts['port'] : ($scheme === 'https' ? 443 : 80);
        if (!in_array($port, $this->allowedPorts, true)) {
            throw new TargetHealthPolicyViolation('unsafe_port');
        }
        $displayHost = str_contains($host, ':') ? '[' . $host . ']' : $host;
        $defaultPort = $scheme === 'https' ? 443 : 80;
        $path = (string)($parts['path'] ?? '');
        $path = $path === '' ? '/' : $path;
        $canonical = $scheme . '://' . $displayHost . ($port === $defaultPort ? '' : ':' . $port) . $path
            . (isset($parts['query']) ? '?' . $parts['query'] : '');
        if (strlen($canonical) > 2048) {
            throw new TargetHealthPolicyViolation('invalid_url');
        }
        $origin = $scheme . '://' . $displayHost . ':' . $port;
        return [
            'scheme' => $scheme,
            'host' => $host,
            'port' => $port,
            'canonical' => $canonical,
            'origin' => $origin,
            'signature' => $canonical,
            'destination' => $scheme . '|' . $host . '|' . $port . '|' . $path
                . '|' . (string)($parts['query'] ?? ''),
        ];
    }

    /** @return list<string> */
    private function validatedAddresses(string $host): array
    {
        $answers = $this->resolver->resolve($host);
        $normalized = [];
        $blocked = false;
        $allowed = false;
        foreach ($answers as $answer) {
            if (!is_string($answer) || !filter_var($answer, FILTER_VALIDATE_IP)) {
                throw new TargetHealthPolicyViolation('invalid_dns_answer');
            }
            $packed = @inet_pton($answer);
            $address = is_string($packed) ? @inet_ntop($packed) : false;
            if (!is_string($address)) {
                throw new TargetHealthPolicyViolation('invalid_dns_answer');
            }
            $normalized[$address] = true;
            if (self::isGloballyRoutableIp($address)) {
                $allowed = true;
            } else {
                $blocked = true;
            }
        }
        if (!$normalized) {
            throw new RuntimeException('DNS returned no A or AAAA answers.');
        }
        if ($blocked) {
            throw new TargetHealthPolicyViolation($allowed ? 'mixed_dns_blocked' : 'private_address');
        }
        return array_keys($normalized);
    }

    /** @param array<string, mixed> $target */
    private function requestHop(array $target, string $method, string $pinnedIp, int $timeoutMs): array
    {
        return $this->transport->request(
            (string)$target['canonical'],
            $method,
            (string)$target['host'],
            (int)$target['port'],
            $pinnedIp,
            [
                'connect_timeout_ms' => min($this->connectTimeoutMs, $timeoutMs),
                'timeout_ms' => max(1, $timeoutMs),
                'header_max_bytes' => $this->headerMaxBytes,
                'body_max_bytes' => $this->bodyMaxBytes,
            ]
        );
    }

    /** @param array<string, mixed> $target @param list<string> $addresses @return array<string, mixed> */
    private function requestAddresses(array $target, string $method, array $addresses, float $startedAt): array
    {
        $lastResponse = ['ok' => false, 'status' => null, 'error_no' => 28, 'error' => 'total timeout'];
        $lastAddress = (string)($addresses[0] ?? '');
        $latencyMs = 0;
        $requestCount = 0;
        foreach (array_slice($addresses, 0, self::MAX_ADDRESS_ATTEMPTS) as $address) {
            $remainingMs = $this->totalTimeoutMs - (int)round((microtime(true) - $startedAt) * 1000);
            if ($remainingMs <= 0) {
                return [
                    'response' => $lastResponse,
                    'pinned_ip' => $lastAddress,
                    'latency_ms' => $latencyMs,
                    'request_count' => $requestCount,
                    'total_timeout' => true,
                ];
            }
            $lastAddress = $address;
            $lastResponse = $this->requestHop(
                $target,
                $method,
                $address,
                min($this->hopTimeoutMs, $remainingMs)
            );
            $requestCount++;
            $latencyMs += max(0, (int)($lastResponse['latency_ms'] ?? 0));
            $status = (int)($lastResponse['status'] ?? 0);
            $retryableHttpFailure = !empty($lastResponse['ok']) && ($status === 0 || $status >= 500);
            if ((!empty($lastResponse['ok']) && !$retryableHttpFailure)
                || !empty($lastResponse['header_limit_exceeded'])
                || !empty($lastResponse['body_limit_exceeded'])) {
                break;
            }
        }
        return [
            'response' => $lastResponse,
            'pinned_ip' => $lastAddress,
            'latency_ms' => $latencyMs,
            'request_count' => $requestCount,
            'total_timeout' => (microtime(true) - $startedAt) * 1000 >= $this->totalTimeoutMs,
        ];
    }

    /** @param array<string, mixed> $result @param array<string, mixed> $response */
    private function transportFailure(array $result, array $response, int $latencyMs): array
    {
        if (!empty($response['header_limit_exceeded'])) {
            return $this->finish($result, 'anomaly', 'response_headers_too_large', $latencyMs, true);
        }
        if (!empty($response['body_limit_exceeded'])) {
            return $this->finish($result, 'anomaly', 'response_body_too_large', $latencyMs, true);
        }
        $errorNumber = (int)($response['error_no'] ?? 0);
        $reason = match (true) {
            $errorNumber === 28 => 'timeout',
            $errorNumber === 6 => 'dns_error',
            $errorNumber === 7 => 'connect_error',
            in_array($errorNumber, [51, 60, 77, 82, 83, 90, 91], true) => 'certificate_error',
            in_array($errorNumber, [35, 53, 54, 58, 59, 64, 66, 80], true) => 'tls_error',
            default => 'transport_error',
        };
        return $this->finish($result, 'error', $reason, $latencyMs, true);
    }

    /** @param array<string, mixed> $result */
    private function finish(
        array $result,
        string $state,
        string $reason,
        int $latencyMs,
        bool $networkAttempted
    ): array {
        $result['state'] = $state;
        $result['reason'] = substr($reason, 0, 64);
        $result['latency_ms'] = $networkAttempted ? max(0, $latencyMs) : null;
        return $result;
    }

    /** @param array<string, mixed> $result */
    private function storeResultIfCurrent(
        int $linkId,
        string $capturedUrl,
        string $capturedHash,
        ?string $expectedCheckedAt,
        array $result
    ): bool {
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $current = $this->pdo->prepare(<<<'SQL'
                SELECT target_url FROM links
                WHERE id = :id AND deleted_at IS NULL AND is_active = 1
            SQL);
            $current->execute(['id' => $linkId]);
            $currentUrl = $current->fetchColumn();
            if (!is_string($currentUrl)
                || !hash_equals($capturedHash, hash('sha256', $currentUrl))
                || !hash_equals($capturedUrl, $currentUrl)) {
                $this->pdo->exec('ROLLBACK');
                return false;
            }

            $previous = $this->pdo->prepare(<<<'SQL'
                SELECT target_url_hash, checked_at, last_healthy_at, consecutive_failures
                FROM target_health WHERE link_id = :link_id
            SQL);
            $previous->execute(['link_id' => $linkId]);
            $old = $previous->fetch();
            if (($expectedCheckedAt === null && is_array($old))
                || ($expectedCheckedAt !== null && (!is_array($old)
                    || !hash_equals($expectedCheckedAt, (string)$old['checked_at'])))) {
                $this->pdo->exec('ROLLBACK');
                return false;
            }
            $sameTarget = is_array($old) && hash_equals((string)$old['target_url_hash'], $capturedHash);
            $checkedAt = utc_timestamp();
            if (is_array($old) && hash_equals((string)$old['checked_at'], $checkedAt)) {
                $checkedAt = (new DateTimeImmutable($checkedAt))->modify('+1 microsecond')->format('Y-m-d\TH:i:s.u\Z');
            }
            $isHealthy = $result['state'] === 'healthy';
            $lastHealthyAt = $isHealthy
                ? $checkedAt
                : ($sameTarget && is_string($old['last_healthy_at'] ?? null) ? $old['last_healthy_at'] : null);
            $failures = $isHealthy ? 0 : ($sameTarget ? max(0, (int)$old['consecutive_failures']) + 1 : 1);
            $store = $this->pdo->prepare(<<<'SQL'
                INSERT INTO target_health (
                    link_id, target_url_hash, state, reason, checked_at, next_check_at,
                    last_healthy_at, http_status, latency_ms, effective_url, redirect_count,
                    redirect_state, consecutive_failures, redirect_chain_json
                ) VALUES (
                    :link_id, :target_url_hash, :state, :reason, :checked_at, :next_check_at,
                    :last_healthy_at, :http_status, :latency_ms, :effective_url, :redirect_count,
                    :redirect_state, :consecutive_failures, :redirect_chain_json
                )
                ON CONFLICT(link_id) DO UPDATE SET
                    target_url_hash = excluded.target_url_hash,
                    state = excluded.state,
                    reason = excluded.reason,
                    checked_at = excluded.checked_at,
                    next_check_at = excluded.next_check_at,
                    last_healthy_at = excluded.last_healthy_at,
                    http_status = excluded.http_status,
                    latency_ms = excluded.latency_ms,
                    effective_url = excluded.effective_url,
                    redirect_count = excluded.redirect_count,
                    redirect_state = excluded.redirect_state,
                    consecutive_failures = excluded.consecutive_failures,
                    redirect_chain_json = excluded.redirect_chain_json,
                    ignored_at = CASE
                        WHEN target_health.target_url_hash = excluded.target_url_hash THEN target_health.ignored_at
                        ELSE NULL
                    END,
                    ignored_reason = CASE
                        WHEN target_health.target_url_hash = excluded.target_url_hash THEN target_health.ignored_reason
                        ELSE ''
                    END
            SQL);
            $store->execute([
                'link_id' => $linkId,
                'target_url_hash' => $capturedHash,
                'state' => $result['state'],
                'reason' => $result['reason'],
                'checked_at' => $checkedAt,
                'next_check_at' => gmdate('Y-m-d\TH:i:s\Z', time() + $this->intervalSeconds),
                'last_healthy_at' => $lastHealthyAt,
                'http_status' => $result['http_status'],
                'latency_ms' => $result['latency_ms'],
                'effective_url' => $result['effective_url'],
                'redirect_count' => $result['redirect_count'],
                'redirect_state' => $result['redirect_state'],
                'consecutive_failures' => $failures,
                'redirect_chain_json' => json_encode($result['redirect_chain'] ?? [], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ]);
            if (!$isHealthy && $failures === 2) {
                require_once __DIR__ . '/LifecycleWebhookService.php';
                LifecycleWebhookService::enqueue(
                    $this->pdo,
                    $this->config,
                    'link.target_unhealthy',
                    $linkId,
                    'link.target_unhealthy:' . $linkId . ':' . $checkedAt,
                    [
                        'state' => (string)$result['state'],
                        'reason' => (string)$result['reason'],
                        'http_status' => $result['http_status'],
                        'consecutive_failures' => $failures,
                    ]
                );
            }
            $this->pdo->exec('COMMIT');
            return true;
        } catch (Throwable $exception) {
            try {
                $this->pdo->exec('ROLLBACK');
            } catch (Throwable) {
            }
            throw $exception;
        }
    }

    private function dueBacklog(string $now): int
    {
        $statement = $this->pdo->prepare(<<<'SQL'
            SELECT COUNT(*)
            FROM links l
            LEFT JOIN target_health h ON h.link_id = l.id
            WHERE l.deleted_at IS NULL
              AND l.is_active = 1
              AND (
                  h.link_id IS NULL
                  OR julianday(h.next_check_at) <= julianday(:due_at)
                  OR julianday(h.checked_at) < julianday(l.updated_at)
              )
        SQL);
        $statement->execute(['due_at' => $now]);
        return (int)$statement->fetchColumn();
    }

    /** @param array<string, mixed> $target */
    private function sameDestination(string $effectiveUrl, array $target): bool
    {
        try {
            $effective = $this->parseUrl($effectiveUrl);
        } catch (Throwable) {
            return false;
        }
        return hash_equals((string)$target['destination'], (string)$effective['destination']);
    }

    private function sameIp(string $actual, string $expected): bool
    {
        $actualPacked = @inet_pton($actual);
        $expectedPacked = @inet_pton($expected);
        return is_string($actualPacked) && is_string($expectedPacked) && hash_equals($expectedPacked, $actualPacked);
    }

    private static function packedMatchesPrefix(string $address, string $network, int $bits): bool
    {
        if (strlen($address) !== strlen($network) || $bits < 0 || $bits > strlen($address) * 8) {
            return false;
        }
        $bytes = intdiv($bits, 8);
        if ($bytes > 0 && !hash_equals(substr($network, 0, $bytes), substr($address, 0, $bytes))) {
            return false;
        }
        $remaining = $bits % 8;
        if ($remaining === 0) {
            return true;
        }
        $mask = (0xff << (8 - $remaining)) & 0xff;
        return (ord($address[$bytes]) & $mask) === (ord($network[$bytes]) & $mask);
    }

    private static function removeDotSegments(string $path): string
    {
        $input = $path;
        $output = '';
        while ($input !== '') {
            if (str_starts_with($input, '../')) {
                $input = substr($input, 3);
            } elseif (str_starts_with($input, './')) {
                $input = substr($input, 2);
            } elseif (str_starts_with($input, '/./')) {
                $input = '/' . substr($input, 3);
            } elseif ($input === '/.') {
                $input = '/';
            } elseif (str_starts_with($input, '/../')) {
                $input = '/' . substr($input, 4);
                $output = preg_replace('#/?[^/]*$#', '', $output) ?? '';
            } elseif ($input === '/..') {
                $input = '/';
                $output = preg_replace('#/?[^/]*$#', '', $output) ?? '';
            } elseif ($input === '.' || $input === '..') {
                $input = '';
            } else {
                $slash = str_starts_with($input, '/') ? strpos($input, '/', 1) : strpos($input, '/');
                if ($slash === false) {
                    $output .= $input;
                    $input = '';
                } else {
                    $output .= substr($input, 0, $slash);
                    $input = substr($input, $slash);
                }
            }
        }
        return $output === '' ? '/' : $output;
    }
}
