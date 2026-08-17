<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$config = require $root . '/config.php';
require $root . '/app/bootstrap.php';

try {
    $pdo = database($config);
    $state = $pdo->query('SELECT status, checkpoint_date FROM analytics_rollup_state WHERE id = 1')->fetch();
    $bounds = $pdo->query(<<<'SQL'
        SELECT MIN(accessed_on) AS first_date, MAX(accessed_on) AS last_date
        FROM (
            SELECT accessed_on FROM visitor_daily_stats
            UNION ALL
            SELECT substr(accessed_hour, 1, 10) AS accessed_on FROM visitor_hourly_stats
        )
    SQL)->fetch() ?: [];
    $first = (string)($bounds['first_date'] ?? '');
    $last = (string)($bounds['last_date'] ?? '');
    if ($first === '' || $last === '') {
        $pdo->exec("UPDATE analytics_rollup_state SET status = 'ready', completed_at = strftime('%Y-%m-%dT%H:%M:%SZ', 'now'), updated_at = strftime('%Y-%m-%dT%H:%M:%SZ', 'now') WHERE id = 1");
        fwrite(STDOUT, "Analytics rollups are ready; no source rows were found.\n");
        exit(0);
    }
    $checkpoint = is_array($state) ? (string)($state['checkpoint_date'] ?? '') : '';
    $date = new DateTimeImmutable($checkpoint !== '' ? $checkpoint : $first, new DateTimeZone('UTC'));
    if ($checkpoint !== '') {
        $date = $date->modify('+1 day');
    }
    $end = new DateTimeImmutable($last, new DateTimeZone('UTC'));
    $delete = $pdo->prepare('DELETE FROM analytics_daily_dimensions WHERE accessed_on = :accessed_on');
    $insert = $pdo->prepare(<<<'SQL'
        INSERT INTO analytics_daily_dimensions (
            link_id, accessed_on, country_code, device_type, browser, operating_system,
            referrer_domain, visitor_kind, request_kind, campaign_name, campaign_source,
            campaign_medium, campaign_content, clicks
        )
        SELECT link_id, :target_date, country_code, device_type, browser, operating_system,
               referrer_domain, visitor_kind, request_kind, campaign_name, campaign_source,
               campaign_medium, campaign_content, SUM(clicks)
        FROM (
            SELECT link_id, country_code, device_type, browser, operating_system,
                   referrer_domain, visitor_kind, request_kind, campaign_name, campaign_source,
                   campaign_medium, campaign_content, clicks
            FROM visitor_daily_stats WHERE accessed_on = :daily_date
            UNION ALL
            SELECT link_id, country_code, device_type, browser, operating_system,
                   referrer_domain, visitor_kind, request_kind, campaign_name, campaign_source,
                   campaign_medium, campaign_content, clicks
            FROM visitor_hourly_stats WHERE accessed_hour >= :hour_start AND accessed_hour < :hour_end
        ) source_rows
        GROUP BY link_id, country_code, device_type, browser, operating_system,
                 referrer_domain, visitor_kind, request_kind, campaign_name, campaign_source,
                 campaign_medium, campaign_content
    SQL);
    $update = $pdo->prepare(<<<'SQL'
        UPDATE analytics_rollup_state
        SET status = 'running', checkpoint_date = :checkpoint_date,
            last_error = NULL, updated_at = :updated_at
        WHERE id = 1
    SQL);
    $processed = 0;
    while ($date <= $end) {
        $day = $date->format('Y-m-d');
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $delete->execute(['accessed_on' => $day]);
            $insert->execute([
                'target_date' => $day,
                'daily_date' => $day,
                'hour_start' => $day . 'T00:00:00Z',
                'hour_end' => $date->modify('+1 day')->format('Y-m-d') . 'T00:00:00Z',
            ]);
            $update->execute(['checkpoint_date' => $day, 'updated_at' => utc_timestamp()]);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
        $processed++;
        $date = $date->modify('+1 day');
    }
    $pdo->exec("UPDATE analytics_rollup_state SET status = 'ready', completed_at = strftime('%Y-%m-%dT%H:%M:%SZ', 'now'), updated_at = strftime('%Y-%m-%dT%H:%M:%SZ', 'now') WHERE id = 1");
    fwrite(STDOUT, "Backfilled {$processed} analytics rollup day(s).\n");
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo instanceof PDO) {
        $statement = $pdo->prepare("UPDATE analytics_rollup_state SET status = 'failed', last_error = :error, updated_at = :updated_at WHERE id = 1");
        $statement->execute(['error' => limit_text($exception->getMessage(), 300), 'updated_at' => utc_timestamp()]);
    }
    fwrite(STDERR, 'Analytics rollup backfill failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
