<?php

declare(strict_types=1);

ini_set('display_errors', '0');

require dirname(__DIR__) . '/lib/operational_status.php';

function operations_integrity_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function operations_integrity_remove_tree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $child = $path . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($child) && !is_link($child)) {
            operations_integrity_remove_tree($child);
        } else {
            @unlink($child);
        }
    }
    @rmdir($path);
}

$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'linkvault-integrity-' . bin2hex(random_bytes(6));

try {
    operations_integrity_assert(mkdir($directory, 0770), 'Cannot create the integrity test directory.');
    $path = $directory . DIRECTORY_SEPARATOR . 'linkvault-20260806-120000.sqlite';
    $contents = str_repeat('0123456789abcdef', 65536);
    operations_integrity_assert(file_put_contents($path, $contents) === strlen($contents), 'Cannot create the backup fixture.');
    $expectedHash = hash('sha256', $contents);
    $config = ['backup_integrity_check_interval_seconds' => 300];
    $cachePath = $directory . DIRECTORY_SEPARATOR . '.health-local-backup-integrity.json';
    $lockPath = $cachePath . '.lock';

    $first = linkvault_backup_file_integrity($config, $path, $expectedHash, 'local-backup');
    operations_integrity_assert($first['valid'] && !$first['cached'], 'The first integrity check did not hash the file.');
    $cache = linkvault_read_json_marker($cachePath);
    operations_integrity_assert(
        ($cache['version'] ?? null) === 2
            && is_int($cache['identity']['metadata']['changed_at'] ?? null)
            && is_string($cache['identity']['sample_sha256'] ?? null),
        'The integrity cache is missing strengthened file identity fields.'
    );
    $checkedAt = (int)$cache['checked_at'];

    $second = linkvault_backup_file_integrity($config, $path, $expectedHash, 'local-backup');
    operations_integrity_assert(
        $second['valid'] && $second['cached'] && $second['checked_at'] === $checkedAt,
        'An immediate integrity check advanced checked_at or missed the cache.'
    );
    operations_integrity_assert(
        (int)(linkvault_read_json_marker($cachePath)['checked_at'] ?? 0) === $checkedAt,
        'An immediate integrity check rewrote the cache marker.'
    );

    $originalModifiedAt = (int)filemtime($path);
    $tamperOffset = intdiv(strlen($contents) - 4096, 3) + 17;
    $handle = fopen($path, 'r+b');
    operations_integrity_assert(is_resource($handle) && fseek($handle, $tamperOffset) === 0, 'Cannot open the interior tamper offset.');
    $originalByte = fread($handle, 1);
    operations_integrity_assert(is_string($originalByte) && strlen($originalByte) === 1, 'Cannot read the interior tamper byte.');
    operations_integrity_assert(fseek($handle, $tamperOffset) === 0, 'Cannot rewind the interior tamper offset.');
    operations_integrity_assert(fwrite($handle, chr(ord($originalByte) ^ 1)) === 1, 'Cannot tamper with the interior sample.');
    fflush($handle);
    fclose($handle);
    operations_integrity_assert(touch($path, $originalModifiedAt), 'Cannot restore the fixture modification time.');
    $tampered = linkvault_backup_file_integrity($config, $path, $expectedHash, 'local-backup');
    operations_integrity_assert(!$tampered['valid'], 'The cached integrity check accepted an interior tamper.');

    $handle = fopen($path, 'r+b');
    operations_integrity_assert(is_resource($handle) && fseek($handle, $tamperOffset) === 0, 'Cannot reopen the interior tamper offset.');
    operations_integrity_assert(fwrite($handle, $originalByte) === 1, 'Cannot restore the interior tamper byte.');
    fflush($handle);
    fclose($handle);
    operations_integrity_assert(touch($path, $originalModifiedAt), 'Cannot restore the fixture timestamp after tampering.');
    operations_integrity_assert(
        linkvault_backup_file_integrity($config, $path, $expectedHash, 'local-backup')['valid'],
        'Integrity did not recover after the interior tamper was restored.'
    );

    operations_integrity_assert(unlink($cachePath), 'Cannot remove the integrity cache for the write-failure test.');
    operations_integrity_assert(mkdir($cachePath), 'Cannot block the integrity cache path.');
    $writeFailure = linkvault_backup_file_integrity($config, $path, $expectedHash, 'local-backup');
    operations_integrity_assert(
        !$writeFailure['valid'] && $writeFailure['reason'] === 'cache_write_failed',
        'An integrity cache write failure did not fail closed with an operational reason.'
    );
    operations_integrity_assert(rmdir($cachePath), 'Cannot unblock the integrity cache path.');

    operations_integrity_assert(is_file($lockPath) && unlink($lockPath), 'Cannot reset the integrity lock fixture.');
    operations_integrity_assert(mkdir($lockPath), 'Cannot block the integrity lock path.');
    $lockFailure = linkvault_backup_file_integrity($config, $path, $expectedHash, 'local-backup');
    operations_integrity_assert(
        !$lockFailure['valid'] && $lockFailure['reason'] === 'cache_unavailable',
        'An unusable per-cache lock did not fail closed.'
    );
    operations_integrity_assert(rmdir($lockPath), 'Cannot unblock the integrity lock path.');

    $parallelPath = $directory . DIRECTORY_SEPARATOR . 'linkvault-20260806-130000.sqlite';
    $parallelContents = str_repeat('parallel-integrity-fixture-', 1310720);
    operations_integrity_assert(
        file_put_contents($parallelPath, $parallelContents) === strlen($parallelContents),
        'Cannot create the parallel integrity fixture.'
    );
    $parallelHash = hash('sha256', $parallelContents);
    unset($parallelContents);
    $parallelPrime = linkvault_backup_file_integrity($config, $parallelPath, $parallelHash, 'parallel');
    operations_integrity_assert($parallelPrime['valid'], 'Cannot prime the parallel integrity cache.');
    $parallelCachePath = $directory . DIRECTORY_SEPARATOR . '.health-parallel-integrity.json';
    $parallelCache = linkvault_read_json_marker($parallelCachePath);
    operations_integrity_assert(is_array($parallelCache), 'Cannot read the primed parallel integrity cache.');
    $parallelCache['checked_at'] = time() - 301;
    linkvault_write_json_marker($parallelCachePath, $parallelCache);
    $barrierPath = $directory . DIRECTORY_SEPARATOR . '.parallel-start';
    $childCode = <<<'PHP'
require $argv[1];
while (!is_file($argv[4])) {
    usleep(1000);
}
$result = linkvault_backup_file_integrity(
    ['backup_integrity_check_interval_seconds' => 300],
    $argv[2],
    $argv[3],
    'parallel'
);
echo json_encode($result, JSON_THROW_ON_ERROR);
PHP;
    $children = [];
    for ($index = 0; $index < 6; $index++) {
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, '-r', $childCode, dirname(__DIR__) . '/lib/operational_status.php', $parallelPath, $parallelHash, $barrierPath],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__),
            null,
            ['bypass_shell' => true]
        );
        operations_integrity_assert(is_resource($process), 'Cannot start a parallel integrity worker.');
        fclose($pipes[0]);
        $children[] = [$process, $pipes];
    }
    operations_integrity_assert(file_put_contents($barrierPath, 'start') === 5, 'Cannot release parallel integrity workers.');
    $parallelResults = [];
    foreach ($children as [$process, $pipes]) {
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        operations_integrity_assert(proc_close($process) === 0, 'Parallel integrity worker failed: ' . $stderr);
        $parallelResults[] = json_decode((string)$stdout, true, 16, JSON_THROW_ON_ERROR);
    }
    operations_integrity_assert(
        count(array_filter($parallelResults, static fn (array $result): bool => !$result['cached'])) === 1
            && count(array_filter($parallelResults, static fn (array $result): bool => $result['valid'])) === 6
            && count(array_unique(array_column($parallelResults, 'checked_at'))) === 1
            && $parallelResults[0]['checked_at'] > $parallelCache['checked_at'],
        'Concurrent expired-cache refreshes performed more than one full verification.'
    );

    fwrite(STDOUT, 'Operational integrity tests passed.' . PHP_EOL);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Operational integrity test failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
} finally {
    operations_integrity_remove_tree($directory);
}
