<?php

declare(strict_types=1);

require dirname(__DIR__) . '/lib/database_schema.php';

$root = dirname(__DIR__);
$paths = glob($root . '/migrations/[0-9][0-9][0-9]_*.sql') ?: [];
$versions = [];

foreach ($paths as $path) {
    if (preg_match('/^(\d{3})_[A-Za-z0-9_-]+\.sql$/D', basename($path), $matches) !== 1) {
        throw new RuntimeException('Invalid migration filename: ' . basename($path));
    }
    $version = (int)$matches[1];
    if (isset($versions[$version])) {
        throw new RuntimeException('Duplicate migration version: ' . $version);
    }
    linkvault_verified_migration_sql($path, $version);
    $versions[$version] = true;
}

ksort($versions, SORT_NUMERIC);
if (array_keys($versions) !== range(1, LINKVAULT_SCHEMA_VERSION)) {
    throw new RuntimeException('Migrations must be contiguous and match schema version ' . LINKVAULT_SCHEMA_VERSION . '.');
}

fwrite(STDOUT, 'Validated ' . count($versions) . ' migrations.' . PHP_EOL);
