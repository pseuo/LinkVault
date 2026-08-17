<?php

declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');

$root = dirname(__DIR__);
$config = require $root . '/config.php';
require $root . '/lib/database_schema.php';
require $root . '/lib/operational_status.php';

/** @return array{exit_code: int, stdout: string, stderr: string} */
function run_restore_command(
    string $binary,
    array $arguments,
    string $workingDirectory,
    array $environment,
    int $timeoutSeconds = 900
): array
{
    if ($binary === '' || strlen($binary) > 4096 || preg_match('/[\x00-\x1F\x7F]/', $binary) === 1) {
        throw new RuntimeException('Restore drill command path is invalid.');
    }
    if (preg_match('/\.php$/i', $binary) === 1) {
        if (!is_file($binary) || is_link($binary) || !is_readable($binary)) {
            throw new RuntimeException('Restore drill PHP command is unavailable.');
        }
        $command = array_merge([PHP_BINARY, $binary], $arguments);
    } else {
        $command = array_merge([$binary], $arguments);
    }
    $pipes = [];
    $process = proc_open(
        $command,
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $workingDirectory,
        $environment,
        ['bypass_shell' => true]
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start restore drill command.');
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $stdout = '';
    $stderr = '';
    $deadline = microtime(true) + max(1, $timeoutSeconds);
    $lastStatus = null;
    while (true) {
        foreach ([1 => 'stdout', 2 => 'stderr'] as $pipeNumber => $outputName) {
            while (($chunk = fread($pipes[$pipeNumber], 8192)) !== false && $chunk !== '') {
                if ($outputName === 'stdout') {
                    $stdout = substr($stdout . $chunk, -1048576);
                } else {
                    $stderr = substr($stderr . $chunk, -1048576);
                }
            }
        }
        $lastStatus = proc_get_status($process);
        if (!$lastStatus['running']) {
            break;
        }
        if (microtime(true) >= $deadline) {
            proc_terminate($process);
            usleep(100000);
            $status = proc_get_status($process);
            if ($status['running']) {
                proc_terminate($process, 9);
            }
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            throw new RuntimeException('Restore drill command timed out.');
        }
        usleep(10000);
    }
    foreach ([1 => 'stdout', 2 => 'stderr'] as $pipeNumber => $outputName) {
        $chunk = stream_get_contents($pipes[$pipeNumber]);
        if (is_string($chunk) && $chunk !== '') {
            if ($outputName === 'stdout') {
                $stdout = substr($stdout . $chunk, -1048576);
            } else {
                $stderr = substr($stderr . $chunk, -1048576);
            }
        }
    }
    fclose($pipes[1]);
    fclose($pipes[2]);
    $closeCode = proc_close($process);
    $exitCode = $closeCode >= 0
        ? $closeCode
        : ((int)$lastStatus['exitcode'] >= 0 ? (int)$lastStatus['exitcode'] : -1);
    return [
        'exit_code' => $exitCode,
        'stdout' => $stdout,
        'stderr' => $stderr,
    ];
}

function remove_restore_tree(string $path): void
{
    if (is_link($path)) {
        if (!unlink($path) || file_exists($path) || is_link($path)) {
            throw new RuntimeException('Cannot remove a restore drill symlink.');
        }
        return;
    }
    if (!file_exists($path)) {
        return;
    }
    if (!is_dir($path)) {
        throw new RuntimeException('Restore drill cleanup target is not a directory.');
    }
    $entries = scandir($path);
    if (!is_array($entries)) {
        throw new RuntimeException('Cannot inspect the restore drill directory during cleanup.');
    }
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $child = $path . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($child) && !is_link($child)) {
            remove_restore_tree($child);
        } elseif (!unlink($child)) {
            throw new RuntimeException('Cannot remove a restore drill file.');
        }
    }
    if (!rmdir($path) || file_exists($path) || is_link($path)) {
        throw new RuntimeException('Cannot remove the restore drill directory.');
    }
}

final class RestoreRunCleanup
{
    public string $path = '';

    public function cleanup(): void
    {
        if ($this->path !== '') {
            remove_restore_tree($this->path);
            $this->path = '';
        }
    }
}

function normalized_restore_path(string $path): string
{
    $normalized = str_replace('\\', '/', $path);
    $trimmed = rtrim($normalized, '/');
    return $trimmed === '' ? '/' : $trimmed;
}

function restore_paths_overlap(string $first, string $second): bool
{
    $first = normalized_restore_path($first);
    $second = normalized_restore_path($second);
    if (strcasecmp($first, $second) === 0) {
        return true;
    }
    $firstPrefix = $first === '/' ? '/' : $first . '/';
    $secondPrefix = $second === '/' ? '/' : $second . '/';
    return strncasecmp($first, $secondPrefix, strlen($secondPrefix)) === 0
        || strncasecmp($second, $firstPrefix, strlen($firstPrefix)) === 0;
}

function verified_restore_credential(string $path, string $label): string
{
    if ($path === '' || !is_file($path) || is_link($path) || !is_readable($path)) {
        throw new RuntimeException("{$label} must be a readable regular non-symlink file.");
    }
    $resolved = realpath($path);
    if (!is_string($resolved)) {
        throw new RuntimeException("Cannot resolve {$label}.");
    }
    return $resolved;
}

function validate_restore_file(string $path, int $expectedSize, string $expectedHash): void
{
    clearstatcache(true, $path);
    if (!is_file($path) || is_link($path) || (int)filesize($path) !== $expectedSize) {
        throw new RuntimeException('Restore source is not a regular file with the expected size.');
    }
    $actualHash = hash_file('sha256', $path);
    if (!is_string($actualHash) || !hash_equals($expectedHash, $actualHash)) {
        throw new RuntimeException('Restore source SHA-256 does not match its success marker.');
    }
}

function bounded_restore_error(string $error): string
{
    $error = trim(preg_replace('/\s+/', ' ', $error) ?? $error);
    if ($error === '') {
        $error = 'Unknown restore drill failure.';
    }
    return substr($error, 0, 300);
}

$startedAt = microtime(true);
$runDirectory = '';
$sourceName = null;
$source = (string)($config['restore_drill_source'] ?? 'local');
$phase = 'configuration';
$totalLinks = 0;
$sampled = 0;
$schemaVersion = 0;
$canWriteMarkers = false;
$backupDirectory = rtrim((string)($config['backup_directory'] ?? ''), '/\\');
$attemptMarker = '';
$runCleanup = new RestoreRunCleanup();
try {
    $drillDirectory = rtrim((string)($config['restore_drill_directory'] ?? ''), '/\\');
    $databasePath = (string)($config['database_path'] ?? '');
    if ($backupDirectory === '' || $drillDirectory === '' || $databasePath === '') {
        throw new RuntimeException('Restore drill paths are not configured.');
    }
    if (!in_array($source, ['local', 'remote'], true)) {
        throw new RuntimeException('LINKVAULT_RESTORE_DRILL_SOURCE must be local or remote.');
    }
    if (!is_dir($backupDirectory) || is_link($backupDirectory)) {
        throw new RuntimeException('Backup directory must be an existing non-symlink directory.');
    }
    $resolvedBackupDirectory = realpath($backupDirectory);
    if (!is_string($resolvedBackupDirectory)) {
        throw new RuntimeException('Cannot resolve the backup directory.');
    }
    $backupDirectory = $resolvedBackupDirectory;
    $attemptMarker = $backupDirectory . DIRECTORY_SEPARATOR . '.last-restore-attempt.json';
    $canWriteMarkers = true;

    $databaseDirectory = dirname($databasePath);
    if (is_link($databaseDirectory)) {
        throw new RuntimeException('Live database directory must not be a symlink.');
    }
    $resolvedDatabasePath = realpath($databasePath);
    $resolvedDatabaseDirectory = is_string($resolvedDatabasePath)
        ? dirname($resolvedDatabasePath)
        : realpath($databaseDirectory);
    if (!is_string($resolvedDatabaseDirectory)) {
        throw new RuntimeException('Cannot resolve the live database directory.');
    }
    if (is_link($drillDirectory)) {
        throw new RuntimeException('Restore drill root must not be a symlink.');
    }
    if (!is_dir($drillDirectory) && !mkdir($drillDirectory, 0700, true) && !is_dir($drillDirectory)) {
        throw new RuntimeException('Cannot create the restore drill directory.');
    }
    $resolvedDrillDirectory = realpath($drillDirectory);
    if (!is_string($resolvedDrillDirectory)) {
        throw new RuntimeException('Cannot resolve a non-symlink restore drill root.');
    }
    if (restore_paths_overlap($resolvedDrillDirectory, $resolvedDatabaseDirectory)
        || restore_paths_overlap($resolvedDrillDirectory, $resolvedBackupDirectory)) {
        throw new RuntimeException('Restore drill root must not overlap the live database or backup directory.');
    }

    $phase = 'lock';
    $drillLock = fopen($drillDirectory . DIRECTORY_SEPARATOR . '.restore-drill.lock', 'c');
    if (!is_resource($drillLock) || !flock($drillLock, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Another restore drill is already running.');
    }
    $backupLock = fopen($backupDirectory . DIRECTORY_SEPARATOR . '.backup.lock', 'c');
    if (!is_resource($backupLock) || !flock($backupLock, LOCK_SH | LOCK_NB)) {
        throw new RuntimeException('A backup process is currently running.');
    }

    $phase = 'source_validation';
    $expectedSize = 0;
    $expectedHash = '';
    $remoteObject = null;
    $identityPath = null;
    $rcloneConfigPath = null;
    if ($source === 'local') {
        $localBackup = linkvault_local_backup_status(array_merge($config, [
            'backup_directory' => $backupDirectory,
        ]));
        if (empty($localBackup['available']) || empty($localBackup['fresh'])) {
            throw new RuntimeException('The latest verified local backup is unavailable or stale.');
        }
        $sourceName = (string)$localBackup['backup_file'];
        $expectedSize = (int)$localBackup['size_bytes'];
        $expectedHash = (string)$localBackup['sha256'];
    } else {
        $remoteMarker = linkvault_valid_remote_backup_marker(linkvault_read_json_marker(
            $backupDirectory . DIRECTORY_SEPARATOR . '.last-remote-success.json'
        ));
        $maxBackupAge = max(60, (int)($config['backup_max_age_seconds'] ?? 8 * 3600));
        if (!is_array($remoteMarker)
            || $remoteMarker['completed_at'] > time()
            || $remoteMarker['completed_at'] < time() - $maxBackupAge) {
            throw new RuntimeException('The latest verified remote backup marker is unavailable or stale.');
        }
        $rcloneRemote = rtrim(trim((string)($config['backup_rclone_remote'] ?? '')), '/');
        if (!linkvault_valid_rclone_remote($rcloneRemote)) {
            throw new RuntimeException('LINKVAULT_BACKUP_RCLONE_REMOTE is invalid for a remote restore drill.');
        }
        $identityPath = verified_restore_credential(
            trim((string)($config['restore_age_identity'] ?? '')),
            'LINKVAULT_RESTORE_AGE_IDENTITY'
        );
        $configuredRclonePath = trim((string)($config['restore_rclone_config'] ?? ''));
        if ($configuredRclonePath !== '') {
            $rcloneConfigPath = verified_restore_credential(
                $configuredRclonePath,
                'LINKVAULT_RESTORE_RCLONE_CONFIG'
            );
        }
        $sourceName = (string)$remoteMarker['object_name'];
        $expectedSize = (int)$remoteMarker['size_bytes'];
        $expectedHash = (string)$remoteMarker['sha256'];
        $remoteObject = $rcloneRemote . '/' . $sourceName;
    }

    $runDirectory = $resolvedDrillDirectory . DIRECTORY_SEPARATOR
        . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4));
    if (!mkdir($runDirectory, 0700, true)) {
        throw new RuntimeException('Cannot create the isolated restore directory.');
    }
    $resolvedRunDirectory = realpath($runDirectory);
    if (!is_string($resolvedRunDirectory) || is_link($runDirectory)
        || strcasecmp(normalized_restore_path(dirname($resolvedRunDirectory)), normalized_restore_path($resolvedDrillDirectory)) !== 0) {
        throw new RuntimeException('The isolated restore directory escaped its configured root.');
    }
    $runDirectory = $resolvedRunDirectory;
    $runCleanup->path = $runDirectory;
    $restoredPath = $runDirectory . DIRECTORY_SEPARATOR . 'linkvault.sqlite';
    $restoredTemporaryPath = $restoredPath . '.part';

    $environment = getenv();
    $environment = is_array($environment) ? $environment : [];
    if ($source === 'local') {
        $phase = 'copy';
        $sourcePath = $backupDirectory . DIRECTORY_SEPARATOR . $sourceName;
        if (!copy($sourcePath, $restoredTemporaryPath)) {
            throw new RuntimeException('Cannot copy the verified local backup into isolation.');
        }
        $phase = 'hash_validation';
        validate_restore_file($restoredTemporaryPath, $expectedSize, $expectedHash);
    } else {
        $phase = 'download';
        $encryptedPath = $runDirectory . DIRECTORY_SEPARATOR . $sourceName;
        $rcloneArguments = ['copyto'];
        if (is_string($rcloneConfigPath)) {
            $rcloneArguments[] = '--config';
            $rcloneArguments[] = $rcloneConfigPath;
        }
        $rcloneArguments[] = $remoteObject;
        $rcloneArguments[] = $encryptedPath;
        $download = run_restore_command(
            (string)($config['backup_rclone_binary'] ?? 'rclone'),
            $rcloneArguments,
            $root,
            $environment
        );
        if ($download['exit_code'] !== 0) {
            throw new RuntimeException('Remote restore download failed: ' . bounded_restore_error($download['stderr']));
        }
        $phase = 'hash_validation';
        validate_restore_file($encryptedPath, $expectedSize, $expectedHash);

        $phase = 'decrypt';
        $decryption = run_restore_command(
            (string)($config['backup_age_binary'] ?? 'age'),
            ['--decrypt', '--identity', $identityPath, '--output', $restoredTemporaryPath, $encryptedPath],
            $root,
            $environment
        );
        clearstatcache(true, $restoredTemporaryPath);
        if ($decryption['exit_code'] !== 0
            || !is_file($restoredTemporaryPath)
            || is_link($restoredTemporaryPath)
            || (int)filesize($restoredTemporaryPath) <= 0) {
            throw new RuntimeException('Remote restore decryption failed: ' . bounded_restore_error($decryption['stderr']));
        }
    }
    if (!chmod($restoredTemporaryPath, 0600)
        || !rename($restoredTemporaryPath, $restoredPath)
        || !is_file($restoredPath)
        || is_link($restoredPath)) {
        throw new RuntimeException('Cannot atomically publish the isolated SQLite copy.');
    }

    $environment = array_merge($environment, [
        'LINKVAULT_DATABASE_PATH' => $restoredPath,
        'LINKVAULT_LOG_PATH' => $runDirectory . DIRECTORY_SEPARATOR . 'application.log',
    ]);

    $phase = 'pre_migration';
    $pdo = new PDO('sqlite:' . $restoredPath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    if ($pdo->query('PRAGMA integrity_check')->fetchAll(PDO::FETCH_COLUMN) !== ['ok']) {
        throw new RuntimeException('Pre-migration database integrity check failed.');
    }
    $schemaVersion = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
    if ($schemaVersion < 1 || $schemaVersion > LINKVAULT_SCHEMA_VERSION) {
        throw new RuntimeException('Pre-migration database user_version is unsupported.');
    }
    $linkTable = $pdo->query(
        "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'links'"
    )->fetchColumn();
    $linkColumns = [];
    if ($linkTable) {
        foreach ($pdo->query('PRAGMA table_info("links")') as $column) {
            $linkColumns[] = (string)$column['name'];
        }
    }
    if (!$linkTable || array_diff(['id', 'slug', 'target_url'], $linkColumns)) {
        throw new RuntimeException('Pre-migration links table sanity check failed.');
    }
    $totalLinks = (int)$pdo->query('SELECT COUNT(*) FROM links')->fetchColumn();
    $pdo = null;

    $phase = 'migration';
    $migration = run_restore_command(PHP_BINARY, [$root . '/bin/migrate.php'], $root, $environment);
    if ($migration['exit_code'] !== 0) {
        throw new RuntimeException('Isolated migration failed: ' . trim($migration['stderr']));
    }

    $phase = 'post_migration';
    $pdo = new PDO('sqlite:' . $restoredPath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    if ($pdo->query('PRAGMA integrity_check')->fetchAll(PDO::FETCH_COLUMN) !== ['ok']) {
        throw new RuntimeException('Restored database integrity check failed.');
    }
    if ($pdo->query('PRAGMA foreign_key_check')->fetchColumn() !== false) {
        throw new RuntimeException('Restored database contains foreign key violations.');
    }
    $schemaProblems = linkvault_schema_problems($pdo);
    if ((int)$pdo->query('PRAGMA user_version')->fetchColumn() !== LINKVAULT_SCHEMA_VERSION || $schemaProblems) {
        throw new RuntimeException('Restored database does not match the application schema.');
    }
    $schemaVersion = LINKVAULT_SCHEMA_VERSION;
    $totalLinks = (int)$pdo->query('SELECT COUNT(*) FROM links')->fetchColumn();

    $phase = 'write_probe';
    $pdo->exec('BEGIN IMMEDIATE');
    $probe = $pdo->prepare(<<<'SQL'
        INSERT INTO healthcheck_probe (id, checked_at) VALUES (1, :checked_at)
        ON CONFLICT(id) DO UPDATE SET checked_at = excluded.checked_at
    SQL);
    $probe->execute(['checked_at' => gmdate('c')]);
    $pdo->exec('ROLLBACK');

    $phase = 'sample';
    foreach ($pdo->query('SELECT slug, target_url FROM links WHERE deleted_at IS NULL ORDER BY id DESC LIMIT 10') as $link) {
        if (preg_match('/^[A-Za-z0-9_-]{3,64}$/', (string)$link['slug']) !== 1
            || !filter_var((string)$link['target_url'], FILTER_VALIDATE_URL)
            || !in_array(strtolower((string)parse_url((string)$link['target_url'], PHP_URL_SCHEME)), ['http', 'https'], true)) {
            throw new RuntimeException('A sampled redirect record is invalid.');
        }
        $sampled++;
    }
    $probe = null;
    $pdo = null;

    $phase = 'cleanup';
    $runCleanup->cleanup();
    $runDirectory = '';
    $durationMs = (int)round((microtime(true) - $startedAt) * 1000);
    $success = [
        'version' => 2,
        'completed_at' => time(),
        'source' => $source,
        'source_backup' => $sourceName,
        'total_links' => $totalLinks,
        'sampled_links' => $sampled,
        'schema_version' => $schemaVersion,
        'duration_ms' => $durationMs,
        'status' => 'success',
    ];
    $phase = 'publish_marker';
    linkvault_write_json_marker($attemptMarker, $success);
    linkvault_write_json_marker($backupDirectory . DIRECTORY_SEPARATOR . '.last-restore-success.json', $success);
    fwrite(STDOUT, "Restore drill passed using {$sourceName} in {$durationMs} ms." . PHP_EOL);
} catch (Throwable $exception) {
    $probe = null;
    $pdo = null;
    if ($runCleanup->path !== '') {
        try {
            $runCleanup->cleanup();
            $runDirectory = '';
        } catch (Throwable $cleanupException) {
            $phase = 'cleanup';
            $exception = new RuntimeException('Restore cleanup failed: ' . $cleanupException->getMessage());
        }
    }
    if ($canWriteMarkers) {
        $failure = [
            'version' => 2,
            'completed_at' => time(),
            'source' => $source,
            'source_backup' => $sourceName,
            'total_links' => $totalLinks,
            'sampled_links' => $sampled,
            'schema_version' => $schemaVersion,
            'duration_ms' => (int)round((microtime(true) - $startedAt) * 1000),
            'status' => 'failure',
            'phase' => $phase,
            'error' => bounded_restore_error($exception->getMessage()),
        ];
        try {
            linkvault_write_json_marker($attemptMarker, $failure);
        } catch (Throwable) {
        }
    }
    fwrite(STDERR, 'Restore drill failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
