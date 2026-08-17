<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$options = getopt('', ['sizes::', 'iterations::', 'analytics-rows::', 'output::', 'keep', 'rollup-ready']);
$sizes = array_values(array_filter(array_map('intval', explode(',', (string)($options['sizes'] ?? '10000,100000,1000000')))));
$iterations = max(2, (int)($options['iterations'] ?? 7));
$analyticsLimit = max(0, (int)($options['analytics-rows'] ?? 100000));
$output = (string)($options['output'] ?? '');
$keep = array_key_exists('keep', $options);
$rollupReady = array_key_exists('rollup-ready', $options);
if ($sizes === [] || array_filter($sizes, static fn (int $size): bool => $size < 1 || $size > 1000000)) {
    fwrite(STDERR, "--sizes must contain values from 1 to 1000000.\n");
    exit(2);
}

$config = require $root . '/config.php';
require $root . '/app/bootstrap.php';
require $root . '/app/LinkService.php';
require $root . '/app/AnalyticsReportService.php';

/** @return array{p50_ms: float, p95_ms: float, p99_ms: float, samples: int} */
function benchmark_operation(callable $operation, int $iterations): array
{
    $operation();
    $durations = [];
    for ($index = 0; $index < $iterations; $index++) {
        $startedAt = hrtime(true);
        $operation();
        $durations[] = (hrtime(true) - $startedAt) / 1_000_000;
    }
    sort($durations, SORT_NUMERIC);
    $at = static fn (float $p): float => round($durations[max(0, (int)ceil(count($durations) * $p) - 1)], 3);
    return ['p50_ms' => $at(0.50), 'p95_ms' => $at(0.95), 'p99_ms' => $at(0.99), 'samples' => count($durations)];
}

function create_benchmark_database(string $root, string $path): void
{
    $pdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA synchronous = OFF');
    $migrations = glob($root . '/migrations/[0-9][0-9][0-9]_*.sql') ?: [];
    sort($migrations, SORT_STRING);
    foreach ($migrations as $migration) {
        $version = (int)substr(basename($migration), 0, 3);
        $sql = file_get_contents($migration);
        if (!is_string($sql)) {
            throw new RuntimeException('Cannot read migration ' . basename($migration));
        }
        $pdo->exec($sql);
        $pdo->exec('PRAGMA user_version = ' . $version);
    }
    $pdo->exec('PRAGMA foreign_keys = ON');
}

function seed_benchmark_database(PDO $pdo, int $size, int $analyticsLimit): int
{
    $link = $pdo->prepare(<<<'SQL'
        INSERT INTO links (slug, target_url, title, clicks, created_at, updated_at)
        VALUES (:slug, :target_url, :title, :clicks, :created_at, :updated_at)
    SQL);
    $day = gmdate('Y-m-d');
    $timestamp = $day . 'T00:00:00Z';
    for ($start = 1; $start <= $size; $start += 5000) {
        $pdo->beginTransaction();
        $end = min($size, $start + 4999);
        for ($id = $start; $id <= $end; $id++) {
            $slug = 'b' . str_pad(base_convert((string)$id, 10, 36), 9, '0', STR_PAD_LEFT);
            $link->execute([
                'slug' => $slug,
                'target_url' => 'https://example.test/target/' . $id,
                'title' => $id % 1000 === 0 ? 'needle benchmark ' . $id : 'benchmark link ' . $id,
                'clicks' => $id % 101,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
        }
        $pdo->commit();
    }

    $analyticsRows = min($size, $analyticsLimit);
    $stat = $pdo->prepare(<<<'SQL'
        INSERT INTO visitor_daily_stats (
            link_id, accessed_on, country_code, device_type, browser, operating_system,
            referrer_domain, visitor_kind, request_kind, campaign_name, campaign_source,
            campaign_medium, campaign_content, clicks
        ) VALUES (
            :link_id, :accessed_on, 'CN', :device_type, 'Chrome', 'Windows',
            'example.test', 'suspected_human', 'redirect_get', 'benchmark', 'seed',
            'test', '', :clicks
        )
    SQL);
    for ($start = 1; $start <= $analyticsRows; $start += 5000) {
        $pdo->beginTransaction();
        $end = min($analyticsRows, $start + 4999);
        for ($id = $start; $id <= $end; $id++) {
            $stat->execute([
                'link_id' => $id,
                'accessed_on' => $day,
                'device_type' => $id % 3 === 0 ? 'mobile' : 'desktop',
                'clicks' => ($id % 10) + 1,
            ]);
        }
        $pdo->commit();
    }
    $pdo->exec('PRAGMA optimize');
    return $analyticsRows;
}

$results = [];
foreach ($sizes as $size) {
    $databasePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'linkvault-benchmark-' . $size . '-' . bin2hex(random_bytes(4)) . '.sqlite';
    try {
        create_benchmark_database($root, $databasePath);
        $benchmarkConfig = array_merge($config, [
            'database_path' => $databasePath,
            'application_log_path' => $databasePath . '.log',
            'sqlite_slow_query_ms' => 0,
            'analytics_report_cache_seconds' => 0,
        ]);
        $pdo = database($benchmarkConfig, 5000, true);
        $analyticsRows = seed_benchmark_database($pdo, $size, $analyticsLimit);
        if ($rollupReady) {
            $pdo->exec(<<<'SQL'
                INSERT INTO analytics_daily_dimensions
                SELECT * FROM visitor_daily_stats;
                UPDATE analytics_rollup_state
                SET status = 'ready', checkpoint_date = (SELECT MAX(accessed_on) FROM visitor_daily_stats),
                    updated_at = strftime('%Y-%m-%dT%H:%M:%SZ', 'now'),
                    completed_at = strftime('%Y-%m-%dT%H:%M:%SZ', 'now');
            SQL);
        }
        $service = new LinkService($pdo, 2048, 100, 5000, $benchmarkConfig);
        $analytics = new AnalyticsReportService($pdo, $benchmarkConfig);
        $request = $analytics->normalizeRequest(['range' => '30', 'timezone' => 'UTC']);
        $cacheDirectory = $databasePath . '-report-cache';
        $cachedAnalytics = new AnalyticsReportService($pdo, array_merge($benchmarkConfig, [
            'analytics_report_cache_seconds' => 60,
            'analytics_report_cache_directory' => $cacheDirectory,
        ]));
        $cachedAnalytics->dashboard($request);
        $redirect = $pdo->prepare('SELECT id, target_url FROM links WHERE slug = :slug AND deleted_at IS NULL');
        $lastSlug = 'b' . str_pad(base_convert((string)$size, 10, 36), 9, '0', STR_PAD_LEFT);
        $lastPage = $service->listForAdmin('active', '', max(1, (int)ceil($size / 100)), 100);
        $expectedLastPageFirstId = $size % 100 === 0 ? 100 : $size % 100;
        if ((int)($lastPage['links'][0]['id'] ?? 0) !== $expectedLastPageFirstId
            || (int)($lastPage['links'][count($lastPage['links']) - 1]['id'] ?? 0) !== 1) {
            throw new RuntimeException('Reverse deep pagination returned incorrect rows.');
        }
        $filterOptions = $analytics->filterOptions($size);
        if (count($filterOptions['links']) > 501
            || !in_array($size, array_column($filterOptions['links'], 'id'), true)) {
            throw new RuntimeException('Analytics link filter options are not bounded or missing the selected link.');
        }

        $operations = [
            'redirect_lookup' => static function () use ($redirect, $lastSlug): void {
                $redirect->execute(['slug' => $lastSlug]);
                $redirect->fetch();
            },
            'admin_list_first_page' => static fn (): array => $service->listForAdmin('active', '', 1, 100),
            'admin_list_last_page' => static fn (): array => $service->listForAdmin('active', '', max(1, (int)ceil($size / 100)), 100),
            'search' => static fn (): array => $service->listForAdmin('active', 'needle', 1, 100),
            'analytics_report' => static fn (): array => $analytics->dashboard($request),
            'analytics_report_cache_hit' => static fn (): array => $cachedAnalytics->dashboard($request),
            'analytics_filter_options' => static fn (): array => $analytics->filterOptions($size),
            'csv_export_filtered' => static function () use ($service): int {
                $stream = fopen('php://temp', 'w+b');
                if (!is_resource($stream)) {
                    throw new RuntimeException('Cannot open temporary CSV stream.');
                }
                $rows = $service->exportLinks('active', 'needle');
                foreach ($rows as $row) {
                    fputcsv($stream, array_map(
                        static fn (mixed $value): string|int|float|null => is_array($value)
                            ? json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
                            : $value,
                        $row
                    ));
                }
                $bytes = ftell($stream);
                fclose($stream);
                return $bytes === false ? 0 : $bytes;
            },
        ];
        $measurements = [];
        foreach ($operations as $name => $operation) {
            $measurements[$name] = benchmark_operation($operation, $iterations);
        }
        $results[] = [
            'links' => $size,
            'analytics_rows' => $analyticsRows,
            'database_bytes' => (int)filesize($databasePath),
            'cache_size_kib' => (int)$benchmarkConfig['sqlite_cache_size_kib'],
            'operations' => $measurements,
        ];
        fwrite(STDERR, "Benchmarked {$size} links.\n");
    } finally {
        if (!$keep) {
            foreach (glob($databasePath . '-report-cache' . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
                @unlink($path);
            }
            if (is_dir($databasePath . '-report-cache')) {
                @rmdir($databasePath . '-report-cache');
            }
            foreach ([$databasePath, $databasePath . '-wal', $databasePath . '-shm', $databasePath . '.log'] as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }
        }
    }
}

$json = json_encode([
    'generated_at' => gmdate('c'),
    'iterations' => $iterations,
    'rollup_ready' => $rollupReady,
    'datasets' => $results,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
if ($output !== '') {
    if (file_put_contents($output, $json) === false) {
        throw new RuntimeException('Cannot write benchmark output.');
    }
} else {
    fwrite(STDOUT, $json);
}
