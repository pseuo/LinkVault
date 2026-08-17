<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$environmentExample = file_get_contents($root . '/deploy/linkvault.env.example');
$fpmConfig = file_get_contents($root . '/deploy/php-fpm-linkvault.conf');
$nginxConfig = file_get_contents($root . '/deploy/nginx.conf');
$caddyConfig = file_get_contents($root . '/deploy/Caddyfile');
$backupService = file_get_contents($root . '/deploy/linkvault-backup.service');
$baotaDeployment = file_get_contents($root . '/BAOTA_DEPLOYMENT.md');
$assetManifest = json_decode((string)file_get_contents($root . '/public/assets/manifest.json'), true);
if (!is_string($environmentExample) || !is_string($fpmConfig) || !is_string($nginxConfig)
    || !is_string($caddyConfig) || !is_string($backupService)
    || !is_string($baotaDeployment) || !is_array($assetManifest)) {
    throw new RuntimeException('Cannot read deployment configuration fixtures.');
}
if (!str_contains($backupService, 'User=linkvault-backup')
    || str_contains($backupService, 'User=www-data')
    || !str_contains($backupService, 'ReadWritePaths=/var/backups/linkvault /var/lib/linkvault-backup-status')
    || !str_contains($environmentExample, 'LINKVAULT_BACKUP_STATUS_DIR=/var/lib/linkvault-backup-status')) {
    throw new RuntimeException('The backup service is not isolated from the Web runtime account.');
}
if (!str_contains($environmentExample, 'LINKVAULT_ENV=production')
    || !str_contains($fpmConfig, 'env[LINKVAULT_ENV] = $LINKVAULT_ENV')) {
    throw new RuntimeException('The production environment mode is not explicit in deployment configuration.');
}

if (str_contains($nginxConfig, '(?:GET|POST|PATCH|DELETE):/api/')
    || preg_match('/zone api_(?:per_client|global).*?method GET POST PATCH DELETE/s', $caddyConfig) === 1) {
    throw new RuntimeException('API edge quotas still exclude unsupported HTTP methods.');
}
foreach (['gzip on', 'max-age=31536000', 'immutable'] as $requiredDirective) {
    if (!str_contains($nginxConfig . $caddyConfig, $requiredDirective)) {
        throw new RuntimeException('Static asset policy is missing: ' . $requiredDirective);
    }
}
foreach (['linkvault-performance.log', 'linkvault-static.log', 'linkvault_performance_route'] as $performanceDirective) {
    if (!str_contains($nginxConfig, $performanceDirective)) {
        throw new RuntimeException('Performance logging is missing: ' . $performanceDirective);
    }
}
if (!str_contains($caddyConfig, 'output discard')
    || !str_contains($caddyConfig, 'request>remote_ip delete')
    || !str_contains($caddyConfig, 'request>headers delete')
    || str_contains($nginxConfig, '"remote_addr":"$remote_addr"')
    || str_contains($nginxConfig, '"uri":"$request_uri"')) {
    throw new RuntimeException('Proxy endpoint logging exceeds the documented minimum field set.');
}
$logrotate = file_get_contents($root . '/deploy/linkvault-logrotate.conf');
if (!is_string($logrotate) || !str_contains($logrotate, 'linkvault-security.log')
    || !str_contains($logrotate, 'linkvault-endpoints.log')
    || !str_contains($logrotate, 'maxage 30')) {
    throw new RuntimeException('Proxy log retention policy is incomplete.');
}
foreach (['opcache.enable = 1', 'opcache.memory_consumption', 'opcache.validate_timestamps = 0'] as $opcacheDirective) {
    $productionIni = file_get_contents($root . '/deploy/php-production.ini');
    if (!is_string($productionIni) || !str_contains($productionIni, $opcacheDirective)) {
        throw new RuntimeException('Production OPcache policy is missing: ' . $opcacheDirective);
    }
}
if (!str_contains((string)file_get_contents($root . '/deploy/linkvault-migrate.service'), 'Wants=linkvault-analytics-rollup-backfill.service')) {
    throw new RuntimeException('Database migration does not schedule the analytics Rollup backfill.');
}
if (!str_contains($nginxConfig, 'expires 1y') || !str_contains($caddyConfig, 'encode zstd gzip')) {
    throw new RuntimeException('Static asset compression or expiration is not configured.');
}
foreach ($assetManifest as $logicalPath => $fingerprintedPath) {
    $fingerprintMatches = [];
    if (!is_string($logicalPath) || !is_string($fingerprintedPath)
        || preg_match('#^/assets/(?:fonts/)?[A-Za-z0-9_.-]+\.([0-9a-f]{12})\.(?:css|js|woff2|svg)$#D', $fingerprintedPath, $fingerprintMatches) !== 1
        || !is_file($root . '/public' . $fingerprintedPath)) {
        throw new RuntimeException('Asset manifest contains an invalid or missing file: ' . (string)$logicalPath);
    }
    if (substr((string)hash_file('sha256', $root . '/public' . $fingerprintedPath), 0, 12) !== $fingerprintMatches[1]) {
        throw new RuntimeException('Asset fingerprint does not match its content: ' . $fingerprintedPath);
    }
}
$seedPosition = strpos($baotaDeployment, '/bin/seed-canary.php &&');
$checkPosition = strpos($baotaDeployment, '/bin/check-http-endpoints.php', $seedPosition === false ? 0 : $seedPosition);
if ($seedPosition === false || $checkPosition === false || $seedPosition >= $checkPosition) {
    throw new RuntimeException('Baota Canary task does not seed before checking endpoints.');
}

preg_match_all('/^(LINKVAULT_[A-Z0-9_]+)=/m', $environmentExample, $environmentMatches);
preg_match_all('/^env\[(LINKVAULT_[A-Z0-9_]+)\]\s*=/m', $fpmConfig, $fpmMatches);
$missing = array_values(array_diff(array_unique($environmentMatches[1]), array_unique($fpmMatches[1])));
if ($missing !== []) {
    throw new RuntimeException('PHP-FPM does not forward environment variables: ' . implode(', ', $missing));
}

fwrite(STDOUT, 'Deployment configuration tests passed.' . PHP_EOL);
