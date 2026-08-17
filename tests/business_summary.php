<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/app/bootstrap.php';
require $root . '/app/LinkService.php';
require $root . '/app/AnalyticsReportService.php';
require $root . '/app/AnalyticsAnomalyService.php';
require $root . '/app/BusinessSummaryService.php';

function business_summary_assert(bool $condition, string $message): void
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
foreach (glob($root . '/migrations/*.sql') ?: [] as $migration) {
    $version = (int)basename($migration, '.sql');
    $pdo->exec(linkvault_verified_migration_sql($migration, $version));
}
$pdo->exec(<<<'SQL'
    INSERT INTO links (slug, target_url, title, created_at, updated_at)
    VALUES
        ('top-link', 'https://example.test/top', 'Top link', '2026-08-10T00:00:00Z', '2026-08-10T00:00:00Z'),
        ('other-link', 'https://example.test/other', 'Other link', '2026-08-08T00:00:00Z', '2026-08-08T00:00:00Z');
    INSERT INTO visitor_daily_stats (
        link_id, accessed_on, country_code, device_type, browser, operating_system,
        referrer_domain, visitor_kind, request_kind, campaign_name, campaign_source,
        campaign_medium, campaign_content, clicks
    ) VALUES
        (1, '2026-08-10', 'US', 'desktop', 'Other', 'Other', 'example.test',
         'suspected_human', 'redirect_get', '', 'newsletter', '', '', 25),
        (1, '2026-08-11', 'US', 'desktop', 'Other', 'Other', 'bot-source.test',
         'bot', 'redirect_get', '', '', '', '', 15),
        (2, '2026-08-12', 'US', 'mobile', 'Other', 'Other', 'example.test',
         'suspected_human', 'redirect_get', '', '', '', '', 5),
        (1, '2026-08-03', 'US', 'desktop', 'Other', 'Other', 'example.test',
         'suspected_human', 'redirect_get', '', '', '', '', 10);
SQL);

$summary = (new BusinessSummaryService($pdo, [
    'target_health_enabled' => false,
    'backup_directory' => sys_get_temp_dir() . '/missing-linkvault-business-summary-backups',
    'backup_status_directory' => sys_get_temp_dir() . '/missing-linkvault-business-summary-status',
]))->build('weekly', new DateTimeImmutable('2026-08-17T12:00:00Z'));

business_summary_assert(
    $summary['period'] === [
        'start' => '2026-08-10', 'end' => '2026-08-16',
        'previous_start' => '2026-08-03', 'previous_end' => '2026-08-09',
    ],
    'Weekly summary did not use the closed Monday-Sunday period.'
);
business_summary_assert(
    (int)$summary['new_links']['current'] === 1
        && (int)$summary['analytics']['totals']['proxy_requests'] === 45
        && (int)$summary['analytics']['deltas']['proxy_requests'] === 35,
    'Weekly summary totals or previous-period comparison are incorrect.'
);
business_summary_assert(
    $summary['analytics']['top_links'][0]['slug'] === 'top-link'
        && (int)$summary['analytics']['top_links'][0]['requests'] === 40,
    'Weekly summary did not rank top links.'
);
business_summary_assert(
    $summary['analytics']['anomalous_sources'][0]['referrer_domain'] === 'bot-source.test'
        && (float)$summary['analytics']['anomalous_sources'][0]['automated_ratio'] === 100.0,
    'Weekly summary did not surface automated anomalous sources.'
);
business_summary_assert(
    (int)$summary['backup']['count'] === 1 && $summary['target_health']['reason'] === 'disabled',
    'Weekly summary omitted backup or target-health status.'
);

$monthly = (new BusinessSummaryService($pdo, []))->build(
    'monthly', new DateTimeImmutable('2026-08-15T12:00:00Z')
);
business_summary_assert(
    $monthly['period']['start'] === '2026-07-01' && $monthly['period']['end'] === '2026-07-31',
    'Monthly summary did not use the fully completed calendar month.'
);

fwrite(STDOUT, "Business summary tests passed.\n");
