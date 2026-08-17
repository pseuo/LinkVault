<?php

declare(strict_types=1);

$config = require dirname(__DIR__) . '/config.php';
require dirname(__DIR__) . '/app/bootstrap.php';

if (!backup_is_fresh($config)) {
    fwrite(STDERR, 'The last end-to-end backup is missing, stale, or did not reach remote storage.' . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, 'Backup freshness check passed.' . PHP_EOL);
