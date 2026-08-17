<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/app/bootstrap.php';
require $root . '/app/AnalyticsReportService.php';
require $root . '/app/AnalyticsExportJobService.php';
require_once $root . '/lib/database_schema.php';

function analytics_boundary_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$pdo = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec('PRAGMA foreign_keys = ON');
$migrations = glob($root . '/migrations/*.sql') ?: [];
sort($migrations, SORT_STRING);
foreach ($migrations as $path) {
    $version = (int)basename($path, '.sql');
    $pdo->exec(linkvault_verified_migration_sql($path, $version));
}
$pdo->exec(<<<'SQL'
    INSERT INTO links (slug, target_url, title, created_at, updated_at)
    VALUES ('archive01', 'https://example.test/', 'Archive', '2026-01-01T00:00:00Z', '2026-01-01T00:00:00Z');
    INSERT INTO visitor_daily_stats (
        link_id, accessed_on, country_code, device_type, browser, operating_system,
        referrer_domain, visitor_kind, request_kind, campaign_name, campaign_source,
        campaign_medium, campaign_content, clicks
    ) VALUES
        (1, '2026-01-01', 'US', 'desktop', 'Other', 'Other', 'direct',
         'suspected_human', 'redirect_get', '', '', '', '', 5),
        (1, '2026-01-02', 'US', 'desktop', 'Other', 'Other', 'direct',
         'suspected_human', 'redirect_get', '', '', '', '', 7),
        (1, '2026-01-03', 'US', 'desktop', 'Other', 'Other', 'direct',
         'suspected_human', 'redirect_get', '', '', '', '', 11);
SQL);

$service = new AnalyticsReportService($pdo);
$invalidDateRejected = false;
try {
    $service->normalizeRequest(['range' => 'custom', 'start' => '2026-02-30', 'end' => '2026-03-01']);
} catch (AnalyticsInvalidDateRange) {
    $invalidDateRejected = true;
}
analytics_boundary_assert($invalidDateRejected, 'An invalid custom analytics date was silently reset.');
$reversedRangeRejected = false;
try {
    $service->normalizeRequest(['range' => 'custom', 'start' => '2026-03-02', 'end' => '2026-03-01']);
} catch (AnalyticsInvalidDateRange) {
    $reversedRangeRejected = true;
}
analytics_boundary_assert($reversedRangeRejected, 'A reversed custom analytics range was silently reset.');
$request = $service->normalizeRequest([
    'range' => 'custom',
    'start' => '2026-01-01',
    'end' => '2026-01-02',
    'timezone' => 'America/Los_Angeles',
]);
$dashboard = $service->dashboard($request);
analytics_boundary_assert(
    (int)$dashboard['totals']['proxy_requests'] === 7,
    'Partial UTC boundary dates were included in the local-timezone query.'
);
analytics_boundary_assert(
    count($dashboard['trend']) === 2
        && $dashboard['trend'][0]['accessed_on'] === '2026-01-01'
        && (int)$dashboard['trend'][0]['proxy_requests'] === 0
        && $dashboard['trend'][1]['accessed_on'] === '2026-01-02'
        && (int)$dashboard['trend'][1]['proxy_requests'] === 7,
    'UTC daily archive boundaries were mapped incorrectly.'
);
$sourceExport = $service->export('sources', $request);
analytics_boundary_assert(
    $sourceExport['rows'] instanceof Traversable,
    'Analytics CSV rows were materialized instead of exposed as a stream.'
);
$sourceRows = iterator_to_array($sourceExport['rows']);
analytics_boundary_assert(
    count($sourceRows) === 1
        && $sourceRows[0][0] === 'direct'
        && (int)$sourceRows[0][3] === 7,
    'Streamed analytics CSV rows are incomplete or incorrectly mapped.'
);

$pdo->exec(<<<'SQL'
    INSERT INTO analytics_daily_dimensions (
        link_id, accessed_on, country_code, device_type, browser, operating_system,
        referrer_domain, visitor_kind, request_kind, campaign_name, campaign_source,
        campaign_medium, campaign_content, clicks
    )
    SELECT link_id, accessed_on, country_code, device_type, browser, operating_system,
           referrer_domain, visitor_kind, request_kind, campaign_name, campaign_source,
           campaign_medium, campaign_content, clicks
    FROM visitor_daily_stats;
    UPDATE analytics_rollup_state SET status = 'ready' WHERE id = 1;
SQL);
$rollupService = new AnalyticsReportService($pdo);
$rollupDashboard = $rollupService->dashboard($request);
analytics_boundary_assert(
    (int)$rollupDashboard['totals']['proxy_requests'] === (int)$dashboard['totals']['proxy_requests'],
    'Ready daily dimension rollups changed analytics totals.'
);

$exportDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'linkvault-export-' . bin2hex(random_bytes(6));
$jobService = new AnalyticsExportJobService($pdo, [
    'analytics_export_directory' => $exportDirectory,
    'analytics_export_retention_hours' => 1,
], $rollupService);
$ownerHash = hash('sha256', 'analytics-boundary-owner');
$jobId = $jobService->enqueue($ownerHash, 'sources', $request);
analytics_boundary_assert($jobService->status($jobId, hash('sha256', 'other-owner')) === null, 'Analytics export owner isolation failed.');
$jobResult = $jobService->process(1);
$completedJob = $jobService->status($jobId, $ownerHash);
analytics_boundary_assert(
    $jobResult['completed'] === 1
        && is_array($completedJob)
        && $completedJob['status'] === 'completed'
        && (int)$completedJob['row_count'] === 1,
    'Analytics export job did not complete with the expected row count.'
);
$artifact = is_array($completedJob) ? $jobService->artifactPath($completedJob) : null;
analytics_boundary_assert(
    is_string($artifact) && str_contains((string)file_get_contents($artifact), 'direct'),
    'Analytics export job did not publish a complete CSV artifact.'
);
if (is_string($artifact)) {
    unlink($artifact);
}
if (is_dir($exportDirectory)) {
    rmdir($exportDirectory);
}

fwrite(STDOUT, "Analytics boundary tests passed.\n");
