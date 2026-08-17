<?php

declare(strict_types=1);

final class PrivacyController
{
    /** @param array<string, mixed> $config */
    public static function dispatch(array $config): never
    {
        $rawLogRetentionDays = max(1, (int)($config['analytics_raw_log_retention_days'] ?? 30));
        $hourlyRetentionDays = max(1, (int)($config['analytics_hourly_retention_days'] ?? 90));
        $aggregateRetentionDays = max($hourlyRetentionDays, (int)($config['analytics_retention_days'] ?? 365));
        require dirname(__DIR__, 2) . '/templates/privacy.php';
        exit;
    }
}
