<?php

declare(strict_types=1);

require_once __DIR__ . '/database_schema.php';

/** @return array{version: string, build_time: string, schema_version: int} */
function release_metadata(array $config): array
{
    $version = trim((string)($config['release_version'] ?? 'development'));
    if ($version === '' || strlen($version) > 100 || preg_match('/[\x00-\x20\x7F]/', $version)) {
        $version = 'development';
    }
    $buildTime = trim((string)($config['build_time'] ?? ''));
    try {
        $buildTime = (new DateTimeImmutable($buildTime))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');
    } catch (Throwable) {
        $buildTime = 'unknown';
    }
    return [
        'version' => $version,
        'build_time' => $buildTime,
        'schema_version' => LINKVAULT_SCHEMA_VERSION,
    ];
}

/** @return array{version: string, build_time: string, schema_version: int, changelog: list<string>, rollback_version: string} */
function release_center_metadata(array $config): array
{
    $metadata = release_metadata($config);
    $rawChangelog = $config['release_changelog'] ?? '';
    $entries = is_array($rawChangelog)
        ? $rawChangelog
        : preg_split('/(?:\r\n|\r|\n|\|)/u', (string)$rawChangelog);
    $changelog = [];
    foreach (is_array($entries) ? $entries : [] as $entry) {
        if (!is_string($entry)) {
            continue;
        }
        $entry = trim($entry);
        if ($entry === '' || strlen($entry) > 500 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $entry) === 1) {
            continue;
        }
        $changelog[] = $entry;
        if (count($changelog) >= 20) {
            break;
        }
    }

    $rollbackVersion = trim((string)($config['release_rollback_version'] ?? ''));
    if ($rollbackVersion === '' || strlen($rollbackVersion) > 100
        || preg_match('/[\x00-\x20\x7F]/', $rollbackVersion) === 1) {
        $rollbackVersion = '';
    }

    return array_merge($metadata, [
        'changelog' => $changelog,
        'rollback_version' => $rollbackVersion,
    ]);
}
