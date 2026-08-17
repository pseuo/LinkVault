<?php

declare(strict_types=1);

function linkvault_write_json_marker(string $path, array $payload): void
{
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
        throw new RuntimeException('Cannot create marker directory.');
    }
    $temporaryPath = $path . '.tmp-' . getmypid() . '-' . bin2hex(random_bytes(4));
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    if (@file_put_contents($temporaryPath, $json . PHP_EOL, LOCK_EX) === false
        || !@chmod($temporaryPath, 0640)
        || !@rename($temporaryPath, $path)) {
        @unlink($temporaryPath);
        throw new RuntimeException('Cannot update operational status marker.');
    }
}

function linkvault_read_json_marker(string $path): ?array
{
    if (!is_file($path) || is_link($path)) {
        return null;
    }
    $raw = @file_get_contents($path);
    if (!is_string($raw) || strlen($raw) > 65536) {
        return null;
    }
    try {
        $payload = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        return null;
    }
    return is_array($payload) ? $payload : null;
}

function linkvault_synthetic_monitor_status(array $config): array
{
    $path = trim((string)($config['synthetic_status_path'] ?? ''));
    $result = [
        'available' => false,
        'fresh' => false,
        'status' => null,
        'completed_at' => 0,
        'duration_ms' => 0,
        'failed' => 0,
        'reason' => 'missing_marker',
        'probes' => [],
    ];
    if ($path === '') {
        return $result;
    }

    $marker = linkvault_read_json_marker($path);
    if (!is_array($marker)) {
        if (file_exists($path) || is_link($path)) {
            $result['reason'] = 'invalid_marker';
        }
        return $result;
    }
    if (($marker['version'] ?? null) !== 1
        || !is_int($marker['completed_at'] ?? null)
        || $marker['completed_at'] <= 0
        || !is_int($marker['duration_ms'] ?? null)
        || $marker['duration_ms'] < 0
        || !in_array($marker['status'] ?? null, ['success', 'failure'], true)
        || !is_array($marker['probes'] ?? null)) {
        $result['reason'] = 'invalid_marker';
        return $result;
    }

    $probes = [];
    $probeIds = [];
    $failed = 0;
    foreach ($marker['probes'] as $probe) {
        if (!is_array($probe)
            || !is_string($probe['id'] ?? null)
            || preg_match('/^[a-z][a-z0-9_]{1,31}$/D', $probe['id']) !== 1
            || isset($probeIds[$probe['id']])
            || !is_string($probe['label'] ?? null)
            || $probe['label'] === ''
            || strlen($probe['label']) > 60
            || !is_string($probe['path'] ?? null)
            || $probe['path'] === ''
            || strlen($probe['path']) > 160
            || !in_array($probe['status'] ?? null, ['ok', 'error', 'unconfigured'], true)
            || (!is_null($probe['http_status'] ?? null)
                && (!is_int($probe['http_status']) || $probe['http_status'] < 0 || $probe['http_status'] > 599))
            || (!is_null($probe['latency_ms'] ?? null)
                && (!is_int($probe['latency_ms']) || $probe['latency_ms'] < 0 || $probe['latency_ms'] > 300000))
            || !is_string($probe['detail'] ?? null)
            || strlen($probe['detail']) > 300) {
            $result['reason'] = 'invalid_marker';
            return $result;
        }
        $probeIds[$probe['id']] = true;
        if ($probe['status'] === 'error') {
            $failed++;
        }
        $probes[] = [
            'id' => $probe['id'],
            'label' => $probe['label'],
            'path' => $probe['path'],
            'status' => $probe['status'],
            'http_status' => $probe['http_status'] ?? null,
            'latency_ms' => $probe['latency_ms'] ?? null,
            'detail' => $probe['detail'],
        ];
    }
    foreach (['home', 'login', 'api', 'canary'] as $requiredProbe) {
        if (!isset($probeIds[$requiredProbe])) {
            $result['reason'] = 'invalid_marker';
            return $result;
        }
    }
    if (($marker['status'] === 'success' && $failed > 0)
        || ($marker['status'] === 'failure' && $failed === 0)) {
        $result['reason'] = 'invalid_marker';
        return $result;
    }

    $now = time();
    $completedAt = $marker['completed_at'];
    $maxAge = max(60, (int)($config['synthetic_status_max_age_seconds'] ?? 900));
    $fresh = $completedAt <= $now + 300 && $completedAt >= $now - $maxAge;
    $reason = match (true) {
        $completedAt > $now + 300 => 'future_marker',
        !$fresh => 'stale',
        $marker['status'] === 'failure' => 'check_failed',
        default => null,
    };

    return [
        'available' => true,
        'fresh' => $fresh,
        'status' => $marker['status'],
        'completed_at' => $completedAt,
        'duration_ms' => $marker['duration_ms'],
        'failed' => $failed,
        'reason' => $reason,
        'probes' => $probes,
    ];
}

function linkvault_valid_rclone_remote(string $remote): bool
{
    if ($remote === '' || strlen($remote) > 1024
        || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*:[^\x00-\x1F\x7F\\\\]*$/D', $remote) !== 1) {
        return false;
    }
    $path = substr($remote, strpos($remote, ':') + 1);
    foreach (explode('/', trim($path, '/')) as $segment) {
        if ($segment === '.' || $segment === '..') {
            return false;
        }
    }
    return true;
}

function linkvault_valid_remote_backup_marker(?array $marker): ?array
{
    if (!is_array($marker)
        || ($marker['version'] ?? null) !== 1
        || !is_int($marker['completed_at'] ?? null)
        || $marker['completed_at'] <= 0
        || !is_string($marker['object_name'] ?? null)
        || !is_int($marker['size_bytes'] ?? null)
        || $marker['size_bytes'] <= 0
        || !is_string($marker['sha256'] ?? null)
        || preg_match('/^linkvault-\d{8}-\d{6}\.sqlite\.age$/D', $marker['object_name']) !== 1
        || basename($marker['object_name']) !== $marker['object_name']
        || preg_match('/^[a-f0-9]{64}$/D', $marker['sha256']) !== 1) {
        return null;
    }
    return $marker;
}

function linkvault_backup_stat_metadata(array $stat): ?array
{
    foreach (['size', 'mtime', 'ctime', 'mode'] as $field) {
        if (!is_int($stat[$field] ?? null)) {
            return null;
        }
    }
    if ($stat['size'] <= 0 || ($stat['mode'] & 0170000) !== 0100000) {
        return null;
    }

    return [
        'size_bytes' => $stat['size'],
        'modified_at' => $stat['mtime'],
        'changed_at' => $stat['ctime'],
        'device' => is_int($stat['dev'] ?? null) ? $stat['dev'] : null,
        'inode' => is_int($stat['ino'] ?? null) ? $stat['ino'] : null,
        'mode' => $stat['mode'],
        'links' => is_int($stat['nlink'] ?? null) ? $stat['nlink'] : null,
        'owner' => is_int($stat['uid'] ?? null) ? $stat['uid'] : null,
        'group' => is_int($stat['gid'] ?? null) ? $stat['gid'] : null,
    ];
}

function linkvault_backup_path_metadata(string $path): ?array
{
    clearstatcache(true, $path);
    $stat = @lstat($path);
    return is_array($stat) ? linkvault_backup_stat_metadata($stat) : null;
}

function linkvault_backup_handle_sample_fingerprint($handle, int $size): ?string
{
    if (!is_resource($handle) || $size <= 0) {
        return null;
    }

    $sampleBytes = min(4096, $size);
    $maximumOffset = max(0, $size - $sampleBytes);
    $offsets = [0];
    if ($maximumOffset > 0) {
        $offsets[] = intdiv($maximumOffset, 3);
        $offsets[] = intdiv($maximumOffset * 2, 3);
        $offsets[] = $maximumOffset;
    }
    $offsets = array_values(array_unique($offsets));

    $hash = hash_init('sha256');
    hash_update($hash, (string)$size . "\0");
    foreach ($offsets as $offset) {
        if (fseek($handle, $offset) !== 0) {
            return null;
        }
        $length = min($sampleBytes, $size - $offset);
        $sample = '';
        while (strlen($sample) < $length) {
            $chunk = fread($handle, $length - strlen($sample));
            if (!is_string($chunk) || $chunk === '') {
                return null;
            }
            $sample .= $chunk;
        }
        hash_update($hash, (string)$offset . ':' . (string)$length . "\0" . $sample . "\0");
    }

    return hash_final($hash);
}

function linkvault_backup_sample_fingerprint(string $path, int $size): ?string
{
    $handle = @fopen($path, 'rb');
    if (!is_resource($handle)) {
        return null;
    }
    try {
        return linkvault_backup_handle_sample_fingerprint($handle, $size);
    } finally {
        fclose($handle);
    }
}

/** @return array{handle: resource, metadata: array, sample_sha256: string}|null */
function linkvault_backup_open_snapshot(string $path): ?array
{
    $pathMetadata = linkvault_backup_path_metadata($path);
    if (!is_array($pathMetadata)) {
        return null;
    }
    $handle = @fopen($path, 'rb');
    if (!is_resource($handle)) {
        return null;
    }

    $handleStat = @fstat($handle);
    $handleMetadata = is_array($handleStat) ? linkvault_backup_stat_metadata($handleStat) : null;
    if ($handleMetadata !== $pathMetadata) {
        fclose($handle);
        return null;
    }
    $fingerprint = linkvault_backup_handle_sample_fingerprint($handle, $handleMetadata['size_bytes']);
    $finalHandleStat = @fstat($handle);
    $finalHandleMetadata = is_array($finalHandleStat)
        ? linkvault_backup_stat_metadata($finalHandleStat)
        : null;
    if (!is_string($fingerprint)
        || $finalHandleMetadata !== $handleMetadata
        || linkvault_backup_path_metadata($path) !== $handleMetadata) {
        fclose($handle);
        return null;
    }

    return ['handle' => $handle, 'metadata' => $handleMetadata, 'sample_sha256' => $fingerprint];
}

function linkvault_backup_snapshot_unchanged(string $path, array $snapshot): bool
{
    $handle = $snapshot['handle'] ?? null;
    $metadata = $snapshot['metadata'] ?? null;
    if (!is_resource($handle) || !is_array($metadata)) {
        return false;
    }

    $beforeStat = @fstat($handle);
    if (!is_array($beforeStat)
        || linkvault_backup_stat_metadata($beforeStat) !== $metadata
        || linkvault_backup_path_metadata($path) !== $metadata) {
        return false;
    }
    $fingerprint = linkvault_backup_handle_sample_fingerprint($handle, (int)$metadata['size_bytes']);
    $afterStat = @fstat($handle);
    return is_string($fingerprint)
        && hash_equals((string)($snapshot['sample_sha256'] ?? ''), $fingerprint)
        && is_array($afterStat)
        && linkvault_backup_stat_metadata($afterStat) === $metadata
        && linkvault_backup_path_metadata($path) === $metadata;
}

function linkvault_backup_handle_sha256($handle): ?string
{
    if (!is_resource($handle) || fseek($handle, 0) !== 0) {
        return null;
    }
    $hash = hash_init('sha256');
    while (!feof($handle)) {
        $chunk = fread($handle, 1024 * 1024);
        if (!is_string($chunk) || ($chunk === '' && !feof($handle))) {
            return null;
        }
        if ($chunk !== '') {
            hash_update($hash, $chunk);
        }
    }
    return hash_final($hash);
}

/** @return array{valid: bool, checked_at: int, cached: bool, reason: ?string} */
function linkvault_backup_file_integrity(
    array $config,
    string $path,
    string $expectedHash,
    string $cacheName
): array {
    $now = time();
    $interval = max(30, min(86400, (int)($config['backup_integrity_check_interval_seconds'] ?? 300)));
    $cachePath = dirname($path) . DIRECTORY_SEPARATOR . '.health-' . $cacheName . '-integrity.json';
    $cachedResult = static function (?array $cache, array $identity, int $at) use ($interval): ?array {
        if (!is_array($cache)
            || ($cache['version'] ?? null) !== 2
            || !is_int($cache['checked_at'] ?? null)
            || $cache['checked_at'] > $at
            || $cache['checked_at'] < $at - $interval
            || !is_bool($cache['valid'] ?? null)
            || ($cache['identity'] ?? null) !== $identity) {
            return null;
        }
        return [
            'valid' => $cache['valid'],
            'checked_at' => $cache['checked_at'],
            'cached' => true,
            'reason' => $cache['valid'] ? null : 'hash_mismatch',
        ];
    };
    $snapshotIdentity = static fn (array $snapshot): array => [
        'backup_file' => basename($path),
        'expected_sha256' => $expectedHash,
        'metadata' => $snapshot['metadata'],
        'sample_sha256' => $snapshot['sample_sha256'],
    ];

    $snapshot = linkvault_backup_open_snapshot($path);
    if (!is_array($snapshot)) {
        return ['valid' => false, 'checked_at' => 0, 'cached' => false, 'reason' => 'file_unreadable_or_changed'];
    }
    try {
        $cached = $cachedResult(linkvault_read_json_marker($cachePath), $snapshotIdentity($snapshot), $now);
        if (is_array($cached)) {
            return linkvault_backup_snapshot_unchanged($path, $snapshot)
                ? $cached
                : ['valid' => false, 'checked_at' => $now, 'cached' => false, 'reason' => 'file_changed'];
        }
    } finally {
        fclose($snapshot['handle']);
    }

    $lockPath = $cachePath . '.lock';
    if (is_link($lockPath) || (file_exists($lockPath) && !is_file($lockPath))) {
        return ['valid' => false, 'checked_at' => 0, 'cached' => false, 'reason' => 'cache_unavailable'];
    }
    $lockHandle = @fopen($lockPath, 'c+b');
    if (!is_resource($lockHandle)) {
        return ['valid' => false, 'checked_at' => 0, 'cached' => false, 'reason' => 'cache_unavailable'];
    }
    try {
        $lockStat = @fstat($lockHandle);
        $lockPathStat = @lstat($lockPath);
        if (!is_array($lockStat)
            || !is_array($lockPathStat)
            || (($lockStat['mode'] ?? 0) & 0170000) !== 0100000
            || (($lockPathStat['mode'] ?? 0) & 0170000) !== 0100000
            || ($lockPathStat['dev'] ?? null) !== ($lockStat['dev'] ?? null)
            || ($lockPathStat['ino'] ?? null) !== ($lockStat['ino'] ?? null)
            || !flock($lockHandle, LOCK_EX)) {
            return ['valid' => false, 'checked_at' => 0, 'cached' => false, 'reason' => 'cache_unavailable'];
        }

        $now = time();
        $snapshot = linkvault_backup_open_snapshot($path);
        if (!is_array($snapshot)) {
            return ['valid' => false, 'checked_at' => $now, 'cached' => false, 'reason' => 'file_unreadable_or_changed'];
        }
        try {
            $identity = $snapshotIdentity($snapshot);
            $cached = $cachedResult(linkvault_read_json_marker($cachePath), $identity, $now);
            if (is_array($cached)) {
                return linkvault_backup_snapshot_unchanged($path, $snapshot)
                    ? $cached
                    : ['valid' => false, 'checked_at' => $now, 'cached' => false, 'reason' => 'file_changed'];
            }

            $actualHash = linkvault_backup_handle_sha256($snapshot['handle']);
            $checkedAt = time();
            if (!is_string($actualHash) || !linkvault_backup_snapshot_unchanged($path, $snapshot)) {
                return ['valid' => false, 'checked_at' => $checkedAt, 'cached' => false, 'reason' => 'file_changed'];
            }
            $valid = hash_equals($expectedHash, $actualHash);
            try {
                linkvault_write_json_marker($cachePath, [
                    'version' => 2,
                    'checked_at' => $checkedAt,
                    'valid' => $valid,
                    'identity' => $identity,
                ]);
            } catch (Throwable) {
                return [
                    'valid' => false,
                    'checked_at' => $checkedAt,
                    'cached' => false,
                    'reason' => 'cache_write_failed',
                ];
            }
            return [
                'valid' => $valid,
                'checked_at' => $checkedAt,
                'cached' => false,
                'reason' => $valid ? null : 'hash_mismatch',
            ];
        } finally {
            fclose($snapshot['handle']);
        }
    } finally {
        @flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
}

function linkvault_analytics_status(array $config): array
{
    $path = (string)($config['analytics_state_path'] ?? '');
    $result = [
        'available' => false,
        'fresh' => false,
        'reason' => 'missing_marker',
        'collection_state' => 'missing_marker',
        'data_complete' => false,
    ];
    if ($path === '') {
        return $result;
    }

    $marker = linkvault_read_json_marker($path);
    if (!is_array($marker)) {
        if (file_exists($path) || is_link($path)) {
            $result['reason'] = 'invalid_marker';
            $result['collection_state'] = 'invalid_marker';
        }
        return $result;
    }

    $integerFields = [
        'version', 'offset', 'observed_size', 'backlog_bytes', 'completed_at', 'read', 'accepted', 'skipped',
    ];
    foreach ($integerFields as $field) {
        if (!is_int($marker[$field] ?? null)) {
            $result['reason'] = 'invalid_marker';
            $result['collection_state'] = 'invalid_marker';
            return $result;
        }
    }
    $optionalIntegerFields = [
        'last_attempt_at', 'last_success_at', 'failure_count', 'consecutive_failures',
        'last_failure_at', 'latest_event_at', 'active_backlog_bytes', 'duration_ms',
        'lock_wait_ms', 'throughput_per_second',
    ];
    foreach ($optionalIntegerFields as $field) {
        if (array_key_exists($field, $marker) && !is_int($marker[$field])) {
            $result['reason'] = 'invalid_marker';
            $result['collection_state'] = 'invalid_marker';
            return $result;
        }
    }
    if ($marker['version'] !== 1
        || !is_string($marker['inode'] ?? null)
        || strlen($marker['inode']) > 128
        || !is_bool($marker['log_exists'] ?? null)
        || !is_bool($marker['complete'] ?? null)
        || $marker['offset'] < 0
        || $marker['observed_size'] < 0
        || $marker['backlog_bytes'] < 0
        || $marker['completed_at'] < 0
        || $marker['read'] < 0
        || $marker['accepted'] < 0
        || $marker['skipped'] < 0
        || $marker['accepted'] + $marker['skipped'] !== $marker['read']
        || $marker['backlog_bytes'] !== max(0, $marker['observed_size'] - $marker['offset'])
            + max(0, (int)($marker['active_backlog_bytes'] ?? 0))
        || $marker['complete'] !== ($marker['backlog_bytes'] === 0)
        || (!$marker['log_exists'] && (
            $marker['inode'] !== ''
            || $marker['offset'] !== 0
            || $marker['observed_size'] !== 0
            || $marker['backlog_bytes'] !== 0
        ))
        || max(0, (int)($marker['consecutive_failures'] ?? 0))
            > max(0, (int)($marker['failure_count'] ?? 0))
        || (isset($marker['last_error']) && !is_string($marker['last_error']))) {
        $result['reason'] = 'invalid_marker';
        $result['collection_state'] = 'invalid_marker';
        return $result;
    }

    $now = time();
    $maxAge = max(1, (int)($config['analytics_status_max_age_seconds'] ?? 900));
    $lastSuccess = max((int)$marker['completed_at'], (int)($marker['last_success_at'] ?? 0));
    $fresh = $lastSuccess > 0 && $lastSuccess <= $now && $lastSuccess >= $now - $maxAge;
    $consecutiveFailures = max(0, (int)($marker['consecutive_failures'] ?? 0));
    $latestEventAt = max(0, (int)($marker['latest_event_at'] ?? 0));
    $reason = $consecutiveFailures > 0
        ? 'aggregation_failed'
        : ($fresh ? null : ($lastSuccess > $now ? 'future_marker' : 'stale'));
    $dataComplete = $fresh && $consecutiveFailures === 0 && $marker['log_exists'] && $marker['complete'];
    $collectionState = match (true) {
        $consecutiveFailures > 0 => 'failed',
        !$fresh => 'stale',
        !$marker['log_exists'] => 'log_missing',
        !$marker['complete'] => 'backlogged',
        default => 'current',
    };
    return array_merge($marker, [
        'available' => true,
        'fresh' => $fresh && $consecutiveFailures === 0,
        'reason' => $reason,
        'collection_state' => $collectionState,
        'data_complete' => $dataComplete,
        'failure_count' => max(0, (int)($marker['failure_count'] ?? 0)),
        'consecutive_failures' => $consecutiveFailures,
        'consumer_lag_seconds' => $latestEventAt > 0 ? max(0, $now - $latestEventAt) : null,
    ]);
}

function linkvault_record_analytics_failure(string $path, string $error): void
{
    if ($path === '') {
        return;
    }
    $previous = linkvault_read_json_marker($path) ?? [];
    $now = time();
    $marker = [
        'version' => 1,
        'inode' => is_string($previous['inode'] ?? null) ? $previous['inode'] : '',
        'offset' => max(0, (int)($previous['offset'] ?? 0)),
        'observed_size' => max(0, (int)($previous['observed_size'] ?? 0)),
        'active_backlog_bytes' => max(0, (int)($previous['active_backlog_bytes'] ?? 0)),
        'backlog_bytes' => max(0, (int)($previous['backlog_bytes'] ?? 0)),
        'completed_at' => max(0, (int)($previous['completed_at'] ?? 0)),
        'log_exists' => (bool)($previous['log_exists'] ?? false),
        'complete' => (bool)($previous['complete'] ?? true),
        'read' => max(0, (int)($previous['read'] ?? 0)),
        'accepted' => max(0, (int)($previous['accepted'] ?? 0)),
        'skipped' => max(0, (int)($previous['skipped'] ?? 0)),
        'last_attempt_at' => $now,
        'last_success_at' => max(0, (int)($previous['last_success_at'] ?? $previous['completed_at'] ?? 0)),
        'failure_count' => max(0, (int)($previous['failure_count'] ?? 0)) + 1,
        'consecutive_failures' => max(0, (int)($previous['consecutive_failures'] ?? 0)) + 1,
        'last_failure_at' => $now,
        'last_error' => function_exists('mb_substr') ? mb_substr($error, 0, 300) : substr($error, 0, 300),
        'latest_event_at' => max(0, (int)($previous['latest_event_at'] ?? 0)),
        'duration_ms' => max(0, (int)($previous['duration_ms'] ?? 0)),
        'lock_wait_ms' => max(0, (int)($previous['lock_wait_ms'] ?? 0)),
        'throughput_per_second' => max(0, (int)($previous['throughput_per_second'] ?? 0)),
    ];
    $marker['backlog_bytes'] = max(0, $marker['observed_size'] - $marker['offset'])
        + $marker['active_backlog_bytes'];
    $marker['complete'] = $marker['backlog_bytes'] === 0;
    if (!$marker['log_exists']) {
        $marker['inode'] = '';
        $marker['offset'] = 0;
        $marker['observed_size'] = 0;
        $marker['active_backlog_bytes'] = 0;
        $marker['backlog_bytes'] = 0;
        $marker['complete'] = true;
    }
    if ($marker['accepted'] + $marker['skipped'] !== $marker['read']) {
        $marker['read'] = $marker['accepted'] + $marker['skipped'];
    }
    linkvault_write_json_marker($path, $marker);
}

function linkvault_local_backup_status(array $config): array
{
    $statusDirectory = rtrim((string)($config['backup_status_directory'] ?? ''), '/\\');
    $attested = $statusDirectory !== '';
    $directory = $attested
        ? $statusDirectory
        : rtrim((string)($config['backup_directory'] ?? ''), '/\\');
    $result = ['available' => false, 'fresh' => false, 'reason' => 'missing_marker'];
    if ($directory === '') {
        return $result;
    }
    if ($attested && !linkvault_backup_status_directory_secure($directory)) {
        $result['reason'] = 'insecure_status_directory';
        return $result;
    }
    $marker = linkvault_read_json_marker($directory . DIRECTORY_SEPARATOR . '.last-local-success.json');
    if (!is_array($marker)
        || !is_int($marker['completed_at'] ?? null)
        || !is_string($marker['backup_file'] ?? null)
        || !is_int($marker['size_bytes'] ?? null)
        || $marker['size_bytes'] <= 0
        || !is_string($marker['sha256'] ?? null)
        || preg_match('/^linkvault-\d{8}-\d{6}\.sqlite$/', $marker['backup_file']) !== 1
        || basename($marker['backup_file']) !== $marker['backup_file']
        || preg_match('/^[a-f0-9]{64}$/', $marker['sha256']) !== 1
        || ($attested && ($marker['verification'] ?? null) !== 'sqlite_integrity_sha256')) {
        return $result;
    }
    if ($attested) {
        $maxAge = max(60, (int)($config['backup_max_age_seconds'] ?? 8 * 3600));
        $completedAt = $marker['completed_at'];
        $fresh = $completedAt <= time() && $completedAt >= time() - $maxAge;
        return array_merge($marker, [
            'available' => true,
            'fresh' => $fresh,
            'reason' => $fresh ? null : ($completedAt > time() ? 'future_marker' : 'stale'),
            'integrity_checked_at' => $completedAt,
            'integrity_cached' => true,
        ]);
    }
    $backupPath = $directory . DIRECTORY_SEPARATOR . $marker['backup_file'];
    if (!is_file($backupPath) || is_link($backupPath)
        || (int)filesize($backupPath) !== $marker['size_bytes']) {
        $result['reason'] = 'backup_file_missing_or_changed';
        return $result;
    }
    $integrity = linkvault_backup_file_integrity(
        $config,
        $backupPath,
        $marker['sha256'],
        'local-backup'
    );
    if (!$integrity['valid']) {
        $result['reason'] = match ($integrity['reason'] ?? null) {
            'cache_unavailable' => 'backup_integrity_cache_unavailable',
            'cache_write_failed' => 'backup_integrity_cache_write_failed',
            'file_changed' => 'backup_file_changed_during_verification',
            'file_unreadable_or_changed' => 'backup_file_missing_or_changed',
            default => 'backup_hash_mismatch',
        };
        return $result;
    }
    $maxAge = max(60, (int)($config['backup_max_age_seconds'] ?? 8 * 3600));
    $completedAt = $marker['completed_at'];
    $fresh = $completedAt <= time() && $completedAt >= time() - $maxAge;
    return array_merge($marker, [
        'available' => true,
        'fresh' => $fresh,
        'reason' => $fresh ? null : ($completedAt > time() ? 'future_marker' : 'stale'),
        'integrity_checked_at' => $integrity['checked_at'],
        'integrity_cached' => $integrity['cached'],
    ]);
}

function linkvault_remote_backup_status(array $config): array
{
    $statusDirectory = rtrim((string)($config['backup_status_directory'] ?? ''), '/\\');
    $attested = $statusDirectory !== '';
    $directory = $attested
        ? $statusDirectory
        : rtrim((string)($config['backup_directory'] ?? ''), '/\\');
    $enabled = !empty($config['backup_remote_required'])
        || trim((string)($config['backup_age_recipient'] ?? '')) !== ''
        || trim((string)($config['backup_rclone_remote'] ?? '')) !== '';
    $result = ['enabled' => $enabled, 'available' => false, 'fresh' => false, 'reason' => 'missing_marker'];
    if ($directory === '' || !$enabled) {
        $result['reason'] = $enabled ? 'missing_marker' : 'disabled';
        return $result;
    }
    if ($attested && !linkvault_backup_status_directory_secure($directory)) {
        $result['reason'] = 'insecure_status_directory';
        return $result;
    }
    $marker = linkvault_valid_remote_backup_marker(linkvault_read_json_marker(
        $directory . DIRECTORY_SEPARATOR . '.last-remote-success.json'
    ));
    if (!is_array($marker)) {
        return $result;
    }
    if ($attested) {
        if (($marker['verification'] ?? null) !== 'remote_size_sha256') {
            return $result;
        }
        $maxAge = max(60, (int)($config['backup_max_age_seconds'] ?? 8 * 3600));
        $completedAt = $marker['completed_at'];
        $fresh = $completedAt <= time() && $completedAt >= time() - $maxAge;
        return array_merge($marker, [
            'enabled' => true,
            'available' => true,
            'fresh' => $fresh,
            'reason' => $fresh ? null : ($completedAt > time() ? 'future_marker' : 'stale'),
            'integrity_checked_at' => $completedAt,
            'integrity_cached' => true,
        ]);
    }
    $encryptedPath = $directory . DIRECTORY_SEPARATOR . $marker['object_name'];
    if (!is_file($encryptedPath) || is_link($encryptedPath)
        || (int)filesize($encryptedPath) !== $marker['size_bytes']) {
        $result['reason'] = 'remote_backup_file_missing_or_changed';
        return $result;
    }
    $integrity = linkvault_backup_file_integrity(
        $config,
        $encryptedPath,
        $marker['sha256'],
        'remote-backup'
    );
    if (!$integrity['valid']) {
        $result['reason'] = match ($integrity['reason'] ?? null) {
            'cache_unavailable' => 'remote_backup_integrity_cache_unavailable',
            'cache_write_failed' => 'remote_backup_integrity_cache_write_failed',
            'file_changed' => 'remote_backup_file_changed_during_verification',
            'file_unreadable_or_changed' => 'remote_backup_file_missing_or_changed',
            default => 'remote_backup_hash_mismatch',
        };
        return $result;
    }
    $maxAge = max(60, (int)($config['backup_max_age_seconds'] ?? 8 * 3600));
    $completedAt = $marker['completed_at'];
    $fresh = $completedAt <= time() && $completedAt >= time() - $maxAge;
    return array_merge($marker, [
        'enabled' => true,
        'available' => true,
        'fresh' => $fresh,
        'reason' => $fresh ? null : ($completedAt > time() ? 'future_marker' : 'stale'),
        'integrity_checked_at' => $integrity['checked_at'],
        'integrity_cached' => $integrity['cached'],
    ]);
}

function linkvault_backup_status_directory_secure(string $directory): bool
{
    if (!is_dir($directory) || is_link($directory) || !is_readable($directory)) {
        return false;
    }
    $permissions = fileperms($directory);
    return !is_int($permissions) || ($permissions & 0022) === 0;
}

/** @return array{healthy: bool, local: array, remote: array} */
function linkvault_backup_health_status(array $config): array
{
    $local = linkvault_local_backup_status($config);
    $remote = linkvault_remote_backup_status($config);
    $healthy = !empty($local['fresh'])
        && (empty($config['backup_remote_required']) || !empty($remote['fresh']));

    return ['healthy' => $healthy, 'local' => $local, 'remote' => $remote];
}

function linkvault_normalize_restore_marker(?array $marker): ?array
{
    if (!is_array($marker)
        || !is_int($marker['completed_at'] ?? null)
        || $marker['completed_at'] <= 0
        || !in_array($marker['status'] ?? null, ['success', 'failure'], true)) {
        return null;
    }

    if (($marker['version'] ?? null) === 1) {
        if (isset($marker['source_backup']) && (!is_string($marker['source_backup'])
            || preg_match('/^linkvault-\d{8}-\d{6}\.sqlite$/D', $marker['source_backup']) !== 1)) {
            return null;
        }
        return array_merge($marker, ['source' => 'local', 'phase' => null]);
    }

    if (($marker['version'] ?? null) !== 2
        || !in_array($marker['source'] ?? null, ['local', 'remote'], true)
        || !array_key_exists('source_backup', $marker)
        || (!is_null($marker['source_backup']) && !is_string($marker['source_backup']))
        || !is_int($marker['total_links'] ?? null)
        || $marker['total_links'] < 0
        || !is_int($marker['sampled_links'] ?? null)
        || $marker['sampled_links'] < 0
        || $marker['sampled_links'] > min(10, $marker['total_links'])
        || !is_int($marker['schema_version'] ?? null)
        || $marker['schema_version'] < 0
        || !is_int($marker['duration_ms'] ?? null)
        || $marker['duration_ms'] < 0) {
        return null;
    }
    if (is_string($marker['source_backup'])) {
        $pattern = $marker['source'] === 'remote'
            ? '/^linkvault-\d{8}-\d{6}\.sqlite\.age$/D'
            : '/^linkvault-\d{8}-\d{6}\.sqlite$/D';
        if (preg_match($pattern, $marker['source_backup']) !== 1
            || basename($marker['source_backup']) !== $marker['source_backup']) {
            return null;
        }
    } elseif ($marker['status'] === 'success') {
        return null;
    }
    if ($marker['status'] === 'failure'
        && (!is_string($marker['phase'] ?? null)
            || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $marker['phase']) !== 1
            || !is_string($marker['error'] ?? null)
            || $marker['error'] === ''
            || strlen($marker['error']) > 300)) {
        return null;
    }
    return array_merge(['phase' => null], $marker);
}

function linkvault_restore_drill_status(array $config): array
{
    $directory = rtrim((string)($config['backup_directory'] ?? ''), '/\\');
    $configuredSource = (string)($config['restore_drill_source'] ?? 'local');
    $empty = [
        'available' => false,
        'fresh' => false,
        'status' => null,
        'source' => null,
        'phase' => null,
        'configured_source' => $configuredSource,
        'source_matches' => false,
        'last_success_at' => null,
    ];
    if ($directory === '') {
        return $empty;
    }
    $success = linkvault_normalize_restore_marker(linkvault_read_json_marker(
        $directory . DIRECTORY_SEPARATOR . '.last-restore-success.json'
    ));
    $attempt = linkvault_normalize_restore_marker(linkvault_read_json_marker(
        $directory . DIRECTORY_SEPARATOR . '.last-restore-attempt.json'
    ));
    $successValid = is_array($success) && $success['status'] === 'success';
    $attemptValid = is_array($attempt);
    $marker = $attemptValid && (!$successValid || $attempt['completed_at'] >= $success['completed_at'])
        ? $attempt
        : ($successValid ? $success : null);
    if (!is_array($marker)) {
        return $empty;
    }

    $maxAge = max(3600, (int)($config['restore_drill_max_age_seconds'] ?? 8 * 86400));
    $sourceMatches = in_array($configuredSource, ['local', 'remote'], true)
        && $marker['source'] === $configuredSource;
    return array_merge($marker, [
        'available' => true,
        'fresh' => $marker['status'] === 'success'
            && $sourceMatches
            && $marker['completed_at'] <= time()
            && $marker['completed_at'] >= time() - $maxAge,
        'last_success_at' => $successValid ? $success['completed_at'] : null,
        'configured_source' => $configuredSource,
        'source_matches' => $sourceMatches,
    ]);
}

function linkvault_backup_maintenance_summary(array $config): array
{
    $local = linkvault_local_backup_status($config);
    $remote = linkvault_remote_backup_status($config);
    $localProblem = empty($local['fresh']);
    $remoteProblem = !empty($remote['enabled']) && empty($remote['fresh']);

    return [
        'count' => ($localProblem ? 1 : 0) + ($remoteProblem ? 1 : 0),
        'local' => [
            'fresh' => !$localProblem,
            'reason' => $local['reason'] ?? null,
            'completed_at' => isset($local['completed_at']) && is_int($local['completed_at'])
                ? gmdate('c', $local['completed_at']) : null,
        ],
        'remote' => [
            'enabled' => !empty($remote['enabled']),
            'fresh' => empty($remote['enabled']) || !$remoteProblem,
            'reason' => $remote['reason'] ?? null,
            'completed_at' => isset($remote['completed_at']) && is_int($remote['completed_at'])
                ? gmdate('c', $remote['completed_at']) : null,
        ],
    ];
}

function linkvault_target_health_status(array $config): array
{
    $enabled = !empty($config['target_health_enabled']);
    $empty = [
        'enabled' => $enabled,
        'available' => false,
        'fresh' => false,
        'status' => null,
        'reason' => $enabled ? 'missing_marker' : 'disabled',
        'completed_at' => 0,
        'processed' => 0,
        'healthy' => 0,
        'issues' => 0,
        'backlog' => 0,
    ];
    if (!$enabled) {
        return $empty;
    }

    $path = (string)($config['target_health_status_path'] ?? '');
    $marker = $path === '' ? null : linkvault_read_json_marker($path);
    if (!is_array($marker)) {
        if ($path !== '' && (file_exists($path) || is_link($path))) {
            $empty['reason'] = 'invalid_marker';
        }
        return $empty;
    }

    foreach (['version', 'completed_at', 'processed', 'healthy', 'issues', 'backlog'] as $field) {
        if (!is_int($marker[$field] ?? null) || $marker[$field] < 0) {
            $empty['reason'] = 'invalid_marker';
            return $empty;
        }
    }
    if ($marker['version'] !== 1
        || !in_array($marker['status'] ?? null, ['success', 'failure'], true)
        || $marker['healthy'] + $marker['issues'] > $marker['processed']
        || (isset($marker['error']) && (!is_string($marker['error']) || strlen($marker['error']) > 300))) {
        $empty['reason'] = 'invalid_marker';
        return $empty;
    }

    $now = time();
    $maxAge = max(1800, max(60, (int)($config['target_health_interval_seconds'] ?? 900)) * 2);
    $fresh = $marker['completed_at'] > 0
        && $marker['completed_at'] <= $now
        && $marker['completed_at'] >= $now - $maxAge;
    $reason = $marker['status'] === 'failure'
        ? 'checker_failed'
        : ($fresh ? null : ($marker['completed_at'] > $now ? 'future_marker' : 'stale'));

    return array_merge($marker, [
        'enabled' => true,
        'available' => true,
        'fresh' => $fresh && $marker['status'] === 'success',
        'reason' => $reason,
    ]);
}

function linkvault_record_target_health_failure(array $config, string $error): void
{
    $path = (string)($config['target_health_status_path'] ?? '');
    if ($path === '') {
        return;
    }
    linkvault_write_json_marker($path, [
        'version' => 1,
        'status' => 'failure',
        'completed_at' => time(),
        'processed' => 0,
        'healthy' => 0,
        'issues' => 0,
        'backlog' => 0,
        'error' => function_exists('mb_substr') ? mb_substr($error, 0, 300) : substr($error, 0, 300),
    ]);
}
