<?php

declare(strict_types=1);

/** @return list<string> */
function linkvault_deployment_expected_hosts(PDO $pdo, array $config): array
{
    $hosts = [];
    $baseUrl = trim((string)($config['base_url'] ?? ''));
    $baseHost = strtolower((string)parse_url($baseUrl, PHP_URL_HOST));
    if ($baseHost !== '') {
        $hosts[] = $baseHost;
    }

    try {
        $statement = $pdo->query(
            'SELECT hostname FROM short_domains WHERE verified_at IS NOT NULL AND is_enabled = 1 ORDER BY hostname'
        );
        foreach ($statement ?: [] as $row) {
            $hostname = strtolower(trim((string)($row['hostname'] ?? '')));
            if ($hostname !== '') {
                $hosts[] = $hostname;
            }
        }
    } catch (PDOException) {
        // A pre-domain schema is still valid for an initial deployment.
    }

    return linkvault_deployment_normalize_values($hosts, false);
}

/** @return list<string> */
function linkvault_deployment_normalize_values(array $values, bool $ipAddresses): array
{
    $normalized = [];
    foreach ($values as $value) {
        $value = strtolower(trim((string)$value));
        if ($value === '') {
            continue;
        }
        if ($ipAddresses ? filter_var($value, FILTER_VALIDATE_IP) : filter_var($value, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            $normalized[] = $value;
        }
    }
    $normalized = array_values(array_unique($normalized));
    sort($normalized, SORT_STRING);

    return $normalized;
}

/** @return array{hosts: list<string>, trusted_proxies: list<string>, certificates: list<string>, certificate_keys: list<string>, automatic_tls: bool} */
function linkvault_parse_deployment_config(string $server, string $contents): array
{
    $hosts = [];
    $proxies = [];
    $certificates = [];
    $certificateKeys = [];

    if ($server === 'caddy') {
        if (preg_match_all('/^([A-Za-z0-9.-]+(?:\s*,\s*[A-Za-z0-9.-]+)*)\s*\{/m', $contents, $matches)) {
            foreach ($matches[1] as $siteLabels) {
                $hosts = [...$hosts, ...preg_split('/\s*,\s*/', $siteLabels)];
            }
        }
        if (preg_match_all('/\btrusted_proxies\s+static\s+([^\r\n#]+)/', $contents, $matches)) {
            foreach ($matches[1] as $proxyList) {
                $proxies = [...$proxies, ...preg_split('/\s+/', trim($proxyList))];
            }
        }
    } elseif ($server === 'nginx') {
        if (preg_match_all('/^\s*server_name\s+([^;]+);/m', $contents, $matches)) {
            foreach ($matches[1] as $serverNames) {
                $hosts = [...$hosts, ...preg_split('/\s+/', trim($serverNames))];
            }
        }
        if (preg_match_all('/^\s*set_real_ip_from\s+([^;\s]+);/m', $contents, $matches)) {
            $proxies = $matches[1];
        }
        if (preg_match_all('/^\s*ssl_certificate\s+([^;]+);/m', $contents, $matches)) {
            $certificates = array_map('trim', $matches[1]);
        }
        if (preg_match_all('/^\s*ssl_certificate_key\s+([^;]+);/m', $contents, $matches)) {
            $certificateKeys = array_map('trim', $matches[1]);
        }
    } else {
        throw new InvalidArgumentException('Server must be caddy or nginx.');
    }

    return [
        'hosts' => linkvault_deployment_normalize_values($hosts, false),
        'trusted_proxies' => linkvault_deployment_normalize_values($proxies, true),
        'certificates' => array_values(array_unique($certificates)),
        'certificate_keys' => array_values(array_unique($certificateKeys)),
        'automatic_tls' => $server !== 'caddy' || !preg_match('/\bauto_https\s+off\b/', $contents),
    ];
}

/** @return list<string> */
function linkvault_validate_deployment_config(string $server, array $expectedHosts, array $expectedProxies, array $parsed): array
{
    $errors = [];
    $expectedHosts = linkvault_deployment_normalize_values($expectedHosts, false);
    $expectedProxies = linkvault_deployment_normalize_values($expectedProxies, true);
    if ($expectedHosts === []) {
        $errors[] = 'LINKVAULT_BASE_URL must provide a valid public hostname.';
    }
    if ($parsed['hosts'] !== $expectedHosts) {
        $errors[] = 'Configured hosts do not exactly match the base URL and verified enabled domains.';
    }
    if ($parsed['trusted_proxies'] !== $expectedProxies) {
        $errors[] = 'Configured trusted proxies do not exactly match LINKVAULT_TRUSTED_PROXIES.';
    }
    if ($server === 'caddy' && empty($parsed['automatic_tls'])) {
        $errors[] = 'Caddy automatic HTTPS is disabled.';
    }
    if ($server === 'nginx' && (count($parsed['certificates']) === 0 || count($parsed['certificate_keys']) === 0)) {
        $errors[] = 'Nginx must configure both ssl_certificate and ssl_certificate_key.';
    }

    return $errors;
}

/** @return list<string> */
function linkvault_validate_nginx_certificate_hosts(array $certificates, array $hosts): array
{
    $errors = [];
    if (!function_exists('openssl_x509_parse')) {
        return ['The OpenSSL extension is required to inspect Nginx certificates.'];
    }
    $names = [];
    foreach ($certificates as $certificatePath) {
        $certificate = @file_get_contents((string)$certificatePath);
        $parsed = is_string($certificate) ? @openssl_x509_parse($certificate) : false;
        if (!is_array($parsed)) {
            $errors[] = 'Cannot read or parse Nginx certificate: ' . $certificatePath;
            continue;
        }
        if ((int)($parsed['validTo_time_t'] ?? 0) <= time()) {
            $errors[] = 'Nginx certificate is expired: ' . $certificatePath;
        }
        $commonName = strtolower(trim((string)($parsed['subject']['CN'] ?? '')));
        if ($commonName !== '') {
            $names[] = $commonName;
        }
        foreach (explode(',', (string)($parsed['extensions']['subjectAltName'] ?? '')) as $entry) {
            if (str_starts_with(trim($entry), 'DNS:')) {
                $names[] = strtolower(substr(trim($entry), 4));
            }
        }
    }
    foreach (linkvault_deployment_normalize_values($hosts, false) as $host) {
        $covered = false;
        foreach (array_unique($names) as $name) {
            if ($name === $host || (str_starts_with($name, '*.')
                && str_ends_with($host, substr($name, 1))
                && substr_count($host, '.') === substr_count($name, '.'))) {
                $covered = true;
                break;
            }
        }
        if (!$covered) {
            $errors[] = 'No configured Nginx certificate covers ' . $host . '.';
        }
    }

    return array_values(array_unique($errors));
}

function linkvault_render_deployment_inventory(string $server, array $hosts, array $proxies): string
{
    $hosts = linkvault_deployment_normalize_values($hosts, false);
    $proxies = linkvault_deployment_normalize_values($proxies, true);
    if ($server === 'caddy') {
        $proxyLine = $proxies === [] ? '# No upstream proxy: omit trusted_proxies.' : 'trusted_proxies static ' . implode(' ', $proxies);
        return "# Generated by bin/check-deployment-domains.php. Do not edit.\n"
            . "{\n    servers {\n        " . $proxyLine . "\n    }\n}\n\n"
            . implode(', ', $hosts) . " {\n    # Keep this site label synchronized with the inventory.\n}\n";
    }

    $proxyLines = $proxies === [] ? '# No upstream proxy: omit set_real_ip_from.' : implode("\n", array_map(
        static fn (string $proxy): string => 'set_real_ip_from ' . $proxy . ';',
        $proxies
    ));
    return "# Generated by bin/check-deployment-domains.php. Do not edit.\n"
        . "server_name " . implode(' ', $hosts) . ";\n"
        . $proxyLines . "\n"
        . "# Configure a certificate whose SAN covers every server_name above.\n";
}
