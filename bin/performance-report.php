<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$config = require $root . '/config.php';

$options = getopt('', ['endpoint-log::', 'static-log::', 'app-log::', 'window-seconds::']);
$endpointLog = (string)($options['endpoint-log'] ?? '/var/log/nginx/linkvault-performance.log');
$staticLog = (string)($options['static-log'] ?? '/var/log/nginx/linkvault-static.log');
$appLog = (string)($options['app-log'] ?? ($config['application_log_path'] ?? ''));
$windowSeconds = max(60, (int)($options['window-seconds'] ?? 86400));
$cutoff = time() - $windowSeconds;

/** @return iterable<array<string, mixed>> */
function json_log_rows(string $path, int $cutoff): iterable
{
    $handle = $path === '-' ? STDIN : @fopen($path, 'rb');
    if (!is_resource($handle)) {
        return;
    }
    try {
        while (($line = fgets($handle)) !== false) {
            $row = json_decode($line, true);
            if (!is_array($row)) {
                continue;
            }
            $timestamp = strtotime((string)($row['time'] ?? ''));
            if ($timestamp !== false && $timestamp >= $cutoff) {
                yield $row;
            }
        }
    } finally {
        if ($handle !== STDIN) {
            fclose($handle);
        }
    }
}

/** @param list<float> $values */
function latency_summary(array $values): array
{
    sort($values, SORT_NUMERIC);
    $count = count($values);
    $percentile = static function (float $p) use ($values, $count): ?float {
        if ($count === 0) {
            return null;
        }
        $index = max(0, (int)ceil($p * $count) - 1);
        return round($values[$index] * 1000, 2);
    };
    return [
        'requests' => $count,
        'p50_ms' => $percentile(0.50),
        'p95_ms' => $percentile(0.95),
        'p99_ms' => $percentile(0.99),
    ];
}

$routeDurations = [];
$routeErrors = [];
foreach (json_log_rows($endpointLog, $cutoff) as $row) {
    $route = (string)($row['route'] ?? 'unknown');
    if ($route === '') {
        continue;
    }
    $routeDurations[$route][] = max(0.0, (float)($row['request_time'] ?? 0));
    if ((int)($row['status'] ?? 0) >= 500) {
        $routeErrors[$route] = ($routeErrors[$route] ?? 0) + 1;
    }
}
$routes = [];
foreach ($routeDurations as $route => $values) {
    $routes[$route] = latency_summary($values) + ['server_errors' => $routeErrors[$route] ?? 0];
}
ksort($routes);

$static = ['requests' => 0, 'not_modified' => 0, 'bytes_sent' => 0, 'encoded' => 0];
foreach (json_log_rows($staticLog, $cutoff) as $row) {
    $static['requests']++;
    $static['not_modified'] += (int)($row['status'] ?? 0) === 304 ? 1 : 0;
    $static['bytes_sent'] += max(0, (int)($row['bytes_sent'] ?? 0));
    $static['encoded'] += (string)($row['encoding'] ?? '') !== '' ? 1 : 0;
}
$static['validation_hit_ratio'] = $static['requests'] === 0
    ? null : round($static['not_modified'] / $static['requests'], 4);
$static['encoded_ratio'] = $static['requests'] === 0
    ? null : round($static['encoded'] / $static['requests'], 4);

$databaseEvents = ['slow_queries' => 0, 'lock_wait_failures' => 0, 'max_slow_query_ms' => 0, 'max_lock_wait_ms' => 0];
foreach (json_log_rows($appLog, $cutoff) as $row) {
    $event = (string)($row['event'] ?? '');
    $duration = max(0, (int)($row['duration_ms'] ?? 0));
    if ($event === 'sqlite_slow_query') {
        $databaseEvents['slow_queries']++;
        $databaseEvents['max_slow_query_ms'] = max($databaseEvents['max_slow_query_ms'], $duration);
    } elseif ($event === 'sqlite_lock_wait') {
        $databaseEvents['lock_wait_failures']++;
        $databaseEvents['max_lock_wait_ms'] = max($databaseEvents['max_lock_wait_ms'], $duration);
    }
}

$databasePath = (string)($config['database_path'] ?? '');
$databaseEvents['database_bytes'] = is_file($databasePath) ? (int)filesize($databasePath) : null;
$databaseEvents['wal_bytes'] = is_file($databasePath . '-wal') ? (int)filesize($databasePath . '-wal') : 0;

fwrite(STDOUT, json_encode([
    'generated_at' => gmdate('c'),
    'window_seconds' => $windowSeconds,
    'routes' => $routes,
    'static_assets' => $static,
    'sqlite' => $databaseEvents,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
