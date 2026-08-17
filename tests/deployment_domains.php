<?php

declare(strict_types=1);

require dirname(__DIR__) . '/lib/deployment_config.php';

function deployment_domains_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE short_domains (hostname TEXT, verified_at TEXT, is_enabled INTEGER)');
$pdo->exec("INSERT INTO short_domains VALUES ('Go.Example.test', '2026-08-13T00:00:00Z', 1)");
$pdo->exec("INSERT INTO short_domains VALUES ('disabled.example.test', '2026-08-13T00:00:00Z', 0)");
$hosts = linkvault_deployment_expected_hosts($pdo, ['base_url' => 'https://s.example.test']);
deployment_domains_assert($hosts === ['go.example.test', 's.example.test'], 'Expected hosts did not include only enabled verified domains.');

$caddy = linkvault_parse_deployment_config('caddy', "{\n trusted_proxies static 10.0.0.10 127.0.0.1\n}\ns.example.test, go.example.test {\n}\n");
deployment_domains_assert(
    linkvault_validate_deployment_config('caddy', $hosts, ['127.0.0.1', '10.0.0.10'], $caddy) === [],
    'Matching Caddy configuration was rejected.'
);
$caddy['hosts'] = ['s.example.test'];
deployment_domains_assert(
    linkvault_validate_deployment_config('caddy', $hosts, ['127.0.0.1', '10.0.0.10'], $caddy) !== [],
    'Missing verified Caddy domain was accepted.'
);

$nginx = linkvault_parse_deployment_config('nginx', "server {\n server_name s.example.test go.example.test;\n set_real_ip_from 127.0.0.1;\n set_real_ip_from 10.0.0.10;\n ssl_certificate /etc/ssl/fullchain.pem;\n ssl_certificate_key /etc/ssl/private/key.pem;\n}\n");
deployment_domains_assert(
    linkvault_validate_deployment_config('nginx', $hosts, ['10.0.0.10', '127.0.0.1'], $nginx) === [],
    'Matching Nginx configuration was rejected.'
);
$disabledTls = linkvault_parse_deployment_config('caddy', "{\n auto_https off\n}\ns.example.test, go.example.test {\n}\n");
deployment_domains_assert(
    linkvault_validate_deployment_config('caddy', $hosts, [], $disabledTls) !== [],
    'Caddy configuration with automatic TLS disabled was accepted.'
);
deployment_domains_assert(
    str_contains(linkvault_render_deployment_inventory('nginx', $hosts, ['127.0.0.1']), 'server_name go.example.test s.example.test;'),
    'Generated Nginx inventory is incomplete.'
);

fwrite(STDOUT, "Deployment domain checks passed.\n");
