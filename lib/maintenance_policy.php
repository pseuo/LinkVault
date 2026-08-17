<?php

declare(strict_types=1);

/** @return array<string, array{environment: string, default: int, min: int, max: int}> */
function linkvault_maintenance_threshold_specification(): array
{
    return [
        'expiring_days' => [
            'environment' => 'LINKVAULT_MAINTENANCE_EXPIRING_DAYS',
            'default' => 7,
            'min' => 1,
            'max' => 365,
        ],
        'stale_days' => [
            'environment' => 'LINKVAULT_MAINTENANCE_STALE_DAYS',
            'default' => 90,
            'min' => 1,
            'max' => 3650,
        ],
        'quota_percent' => [
            'environment' => 'LINKVAULT_MAINTENANCE_QUOTA_PERCENT',
            'default' => 80,
            'min' => 1,
            'max' => 99,
        ],
    ];
}

/** @return array{expiring_days: int, stale_days: int, quota_percent: int} */
function linkvault_maintenance_thresholds_from_environment(): array
{
    $thresholds = [];
    foreach (linkvault_maintenance_threshold_specification() as $name => $rule) {
        $raw = getenv($rule['environment']);
        $thresholds[$name] = (int)($raw === false || $raw === '' ? $rule['default'] : $raw);
    }
    return $thresholds;
}

/** @return array{expiring_days: int, stale_days: int, quota_percent: int} */
function linkvault_maintenance_thresholds(array $config): array
{
    $configured = is_array($config['maintenance_thresholds'] ?? null)
        ? $config['maintenance_thresholds']
        : [];
    $thresholds = [];
    foreach (linkvault_maintenance_threshold_specification() as $name => $rule) {
        $value = (int)($configured[$name] ?? $rule['default']);
        $thresholds[$name] = max($rule['min'], min($rule['max'], $value));
    }
    return $thresholds;
}
