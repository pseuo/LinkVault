<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/lib/deployment_config.php';
$config = require $root . '/config.php';

$options = getopt('', ['server:', 'config:', 'generate::', 'output::', 'help']);
if (isset($options['help']) || !isset($options['server'])) {
    fwrite(STDOUT, "Usage: php bin/check-deployment-domains.php --server=caddy|nginx --config=/path/to/config [--generate] [--output=/path/to/inventory]\n");
    exit(isset($options['help']) ? 0 : 2);
}
$server = strtolower((string)$options['server']);
if (!in_array($server, ['caddy', 'nginx'], true)) {
    fwrite(STDERR, "--server must be caddy or nginx.\n");
    exit(2);
}

$databasePath = (string)($config['database_path'] ?? '');
if ($databasePath === '' || !is_file($databasePath)) {
    fwrite(STDERR, "Configured SQLite database does not exist.\n");
    exit(2);
}
try {
    $pdo = new PDO('sqlite:' . $databasePath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $hosts = linkvault_deployment_expected_hosts($pdo, $config);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Cannot read deployment domain inventory: ' . $exception->getMessage() . PHP_EOL);
    exit(2);
}
$proxies = (array)($config['trusted_proxies'] ?? []);

if (array_key_exists('generate', $options)) {
    $inventory = linkvault_render_deployment_inventory($server, $hosts, $proxies);
    $output = trim((string)($options['output'] ?? ''));
    if ($output === '') {
        fwrite(STDOUT, $inventory);
    } elseif (@file_put_contents($output, $inventory, LOCK_EX) === false) {
        fwrite(STDERR, "Cannot write generated inventory.\n");
        exit(2);
    } else {
        fwrite(STDOUT, 'Generated ' . $output . PHP_EOL);
    }
}

$configPath = trim((string)($options['config'] ?? ''));
if ($configPath === '') {
    exit(0);
}
$contents = @file_get_contents($configPath);
if (!is_string($contents)) {
    fwrite(STDERR, "Cannot read proxy configuration.\n");
    exit(2);
}
$parsed = linkvault_parse_deployment_config($server, $contents);
$errors = linkvault_validate_deployment_config($server, $hosts, $proxies, $parsed);
if ($server === 'nginx' && $errors === []) {
    $errors = [...$errors, ...linkvault_validate_nginx_certificate_hosts($parsed['certificates'], $hosts)];
}
if ($errors !== []) {
    fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
    exit(1);
}
fwrite(STDOUT, 'Deployment domain, proxy, and TLS configuration checks passed.' . PHP_EOL);
