<?php

declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');

$root = dirname(__DIR__);
$config = require $root . '/config.php';
require $root . '/lib/database_schema.php';
require $root . '/lib/release_metadata.php';
require $root . '/lib/operational_status.php';

/** @return array{exit_code: int, stdout: string, stderr: string} */
function run_backup_command(string $binary, array $arguments, int $timeoutSeconds = 900): array
{
    if ($binary === '' || strlen($binary) > 4096 || preg_match('/[\x00-\x1F\x7F]/', $binary) === 1) {
        throw new RuntimeException('Backup command path is invalid.');
    }
    $pipes = [];
    $process = proc_open(
        array_merge([$binary], $arguments),
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        null,
        ['bypass_shell' => true]
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start backup command: ' . $binary);
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
            $terminateDeadline = microtime(true) + 1.0;
            do {
                usleep(10000);
                $status = proc_get_status($process);
            } while ($status['running'] && microtime(true) < $terminateDeadline);
            if ($status['running']) {
                proc_terminate($process, 9);
            }
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            throw new RuntimeException('Backup command timed out: ' . $binary);
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

function backup_application_problems(string $databasePath): array
{
    $pdo = new PDO('sqlite:' . $databasePath, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');

    $problems = [];
    $version = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
    if ($version !== LINKVAULT_SCHEMA_VERSION) {
        $problems[] = "schema version {$version}; expected " . LINKVAULT_SCHEMA_VERSION;
    }
    foreach (linkvault_schema_problems($pdo) as $problem) {
        $problems[] = $problem;
    }
    foreach ($pdo->query('PRAGMA foreign_key_check') as $violation) {
        $problems[] = 'foreign key violation in ' . (string)($violation['table'] ?? 'unknown table');
    }

    return array_values(array_unique($problems));
}

$backupPath = null;
$encryptedPath = null;
$backupVerified = false;
try {
    $databasePath = (string)($config['database_path'] ?? '');
    $backupDirectory = (string)($config['backup_directory'] ?? '');
    $backupStatusDirectory = rtrim((string)($config['backup_status_directory'] ?? ''), '/\\');
    $sqliteBinary = (string)($config['sqlite3_binary'] ?? 'sqlite3');
    $retentionDays = max(1, (int)($config['backup_retention_days'] ?? 14));
    $commandTimeout = max(1, min(86400, (int)($config['backup_command_timeout_seconds'] ?? 900)));
    $remoteRequired = !empty($config['backup_remote_required']);
    $ageBinary = (string)($config['backup_age_binary'] ?? 'age');
    $ageRecipient = trim((string)($config['backup_age_recipient'] ?? ''));
    $rcloneBinary = (string)($config['backup_rclone_binary'] ?? 'rclone');
    $rcloneRemote = rtrim(trim((string)($config['backup_rclone_remote'] ?? '')), '/');
    $remoteEnabled = $remoteRequired || $ageRecipient !== '' || $rcloneRemote !== '';
    if ($databasePath === '' || !is_file($databasePath)) {
        throw new RuntimeException('The configured database does not exist.');
    }
    if ($backupDirectory === '') {
        throw new RuntimeException('The backup directory is empty.');
    }
    if (!is_dir($backupDirectory) && !mkdir($backupDirectory, 0775, true) && !is_dir($backupDirectory)) {
        throw new RuntimeException('Cannot create the backup directory.');
    }
    if ($remoteEnabled && ($ageRecipient === '' || $rcloneRemote === '')) {
        throw new RuntimeException('Remote backup requires both an age recipient and an rclone destination.');
    }

    $lockHandle = fopen(rtrim($backupDirectory, '/\\') . DIRECTORY_SEPARATOR . '.backup.lock', 'c');
    if (!is_resource($lockHandle) || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
        throw new RuntimeException('Another backup process is already running.');
    }

    $backupPath = rtrim($backupDirectory, '/\\') . DIRECTORY_SEPARATOR . 'linkvault-' . gmdate('Ymd-His') . '.sqlite';
    $quotedBackupPath = str_replace("'", "''", $backupPath);
    $backup = run_backup_command($sqliteBinary, [$databasePath, ".backup '{$quotedBackupPath}'"], $commandTimeout);
    if ($backup['exit_code'] !== 0 || !is_file($backupPath)) {
        throw new RuntimeException('SQLite online backup failed: ' . trim($backup['stderr']));
    }

    $integrity = run_backup_command($sqliteBinary, [$backupPath, 'PRAGMA integrity_check;'], $commandTimeout);
    if ($integrity['exit_code'] !== 0 || trim($integrity['stdout']) !== 'ok') {
        @unlink($backupPath);
        throw new RuntimeException('Backup integrity check failed: ' . trim($integrity['stdout'] . ' ' . $integrity['stderr']));
    }
    $applicationProblems = backup_application_problems($backupPath);
    if ($applicationProblems) {
        @unlink($backupPath);
        throw new RuntimeException('Backup application validation failed: ' . implode('; ', $applicationProblems));
    }
    if (!chmod($backupPath, 0640)) {
        throw new RuntimeException('Cannot restrict backup file permissions.');
    }
    $backupVerified = true;

    $localHash = hash_file('sha256', $backupPath);
    if (!is_string($localHash)) {
        throw new RuntimeException('Cannot hash the verified local backup.');
    }
    linkvault_write_json_marker(
        rtrim($backupDirectory, '/\\') . DIRECTORY_SEPARATOR . '.last-local-success.json',
        array_merge([
            'version' => 1,
            'completed_at' => time(),
            'backup_file' => basename($backupPath),
            'size_bytes' => (int)filesize($backupPath),
            'sha256' => $localHash,
            'schema_version' => LINKVAULT_SCHEMA_VERSION,
        ], ['release' => release_metadata($config)])
    );
    if ($backupStatusDirectory !== '') {
        if (!is_dir($backupStatusDirectory)) {
            throw new RuntimeException('The backup status directory does not exist.');
        }
        linkvault_write_json_marker(
            $backupStatusDirectory . DIRECTORY_SEPARATOR . '.last-local-success.json',
            array_merge([
                'version' => 1,
                'completed_at' => time(),
                'backup_file' => basename($backupPath),
                'size_bytes' => (int)filesize($backupPath),
                'sha256' => $localHash,
                'schema_version' => LINKVAULT_SCHEMA_VERSION,
                'verification' => 'sqlite_integrity_sha256',
            ], ['release' => release_metadata($config)])
        );
    }

    $remoteUploaded = false;
    if ($remoteEnabled) {
        $encryptedPath = $backupPath . '.age';
        $encryptedTemporaryPath = $encryptedPath . '.part';
        $encryption = run_backup_command($ageBinary, [
            '--recipient', $ageRecipient,
            '--output', $encryptedTemporaryPath,
            $backupPath,
        ], $commandTimeout);
        if ($encryption['exit_code'] !== 0 || !is_file($encryptedTemporaryPath)
            || (int)filesize($encryptedTemporaryPath) <= 0) {
            @unlink($encryptedTemporaryPath);
            throw new RuntimeException('Backup encryption failed: ' . trim($encryption['stderr']));
        }
        if (!rename($encryptedTemporaryPath, $encryptedPath) || !chmod($encryptedPath, 0640)) {
            @unlink($encryptedTemporaryPath);
            throw new RuntimeException('Cannot finalize the encrypted backup.');
        }

        $remoteObject = $rcloneRemote . '/' . basename($encryptedPath);
        $upload = run_backup_command($rcloneBinary, ['copyto', '--immutable', $encryptedPath, $remoteObject], $commandTimeout);
        if ($upload['exit_code'] !== 0) {
            throw new RuntimeException('Remote backup upload failed: ' . trim($upload['stderr']));
        }
        $remoteSize = run_backup_command($rcloneBinary, ['lsjson', '--stat', $remoteObject], $commandTimeout);
        $sizePayload = json_decode($remoteSize['stdout'], true);
        if ($remoteSize['exit_code'] !== 0 || !is_array($sizePayload)
            || !empty($sizePayload['IsDir'])
            || (int)($sizePayload['Size'] ?? -1) !== (int)filesize($encryptedPath)) {
            throw new RuntimeException('Remote backup verification failed.');
        }
        $remoteUploaded = true;
        $remoteHash = hash_file('sha256', $encryptedPath);
        if (!is_string($remoteHash)) {
            throw new RuntimeException('Cannot hash the verified encrypted backup.');
        }
        linkvault_write_json_marker(
            rtrim($backupDirectory, '/\\') . DIRECTORY_SEPARATOR . '.last-remote-success.json',
            array_merge([
                'version' => 1,
                'completed_at' => time(),
                'object_name' => basename($encryptedPath),
                'size_bytes' => (int)filesize($encryptedPath),
                'sha256' => $remoteHash,
                'verification' => 'remote_size',
                'schema_version' => LINKVAULT_SCHEMA_VERSION,
            ], ['release' => release_metadata($config)])
        );
        if ($backupStatusDirectory !== '') {
            linkvault_write_json_marker(
                $backupStatusDirectory . DIRECTORY_SEPARATOR . '.last-remote-success.json',
                array_merge([
                    'version' => 1,
                    'completed_at' => time(),
                    'object_name' => basename($encryptedPath),
                    'size_bytes' => (int)filesize($encryptedPath),
                    'sha256' => $remoteHash,
                    'verification' => 'remote_size_sha256',
                    'schema_version' => LINKVAULT_SCHEMA_VERSION,
                ], ['release' => release_metadata($config)])
            );
        }
    }

    $cutoff = time() - $retentionDays * 86400;
    foreach (glob(rtrim($backupDirectory, '/\\') . DIRECTORY_SEPARATOR . 'linkvault-*') ?: [] as $oldBackup) {
        if ($oldBackup !== $backupPath && $oldBackup !== $encryptedPath && is_file($oldBackup)
            && preg_match('/^linkvault-\d{8}-\d{6}\.sqlite(?:\.age)?$/', basename($oldBackup)) === 1
            && (int)filemtime($oldBackup) < $cutoff && !unlink($oldBackup)) {
            throw new RuntimeException('Cannot remove an expired backup.');
        }
    }

    $markerPath = rtrim($backupDirectory, '/\\') . DIRECTORY_SEPARATOR . '.last-success.json';
    $markerTemporaryPath = $markerPath . '.tmp-' . getmypid();
    $backupHash = hash_file('sha256', $remoteEnabled ? $encryptedPath : $backupPath);
    if (!is_string($backupHash)) {
        throw new RuntimeException('Cannot hash the verified backup.');
    }
    $marker = json_encode([
        'completed_at' => time(),
        'backup_file' => basename($backupPath),
        'encrypted' => $remoteEnabled,
        'remote_uploaded' => $remoteUploaded,
        'sha256' => $backupHash,
        'schema_version' => LINKVAULT_SCHEMA_VERSION,
        'release' => release_metadata($config),
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (file_put_contents($markerTemporaryPath, $marker . PHP_EOL, LOCK_EX) === false
        || !chmod($markerTemporaryPath, 0640)
        || !rename($markerTemporaryPath, $markerPath)) {
        @unlink($markerTemporaryPath);
        throw new RuntimeException('Cannot update the backup success marker.');
    }

    fwrite(STDOUT, 'Backup created, verified' . ($remoteUploaded ? ', encrypted, and uploaded' : '')
        . ": {$backupPath}" . PHP_EOL);
} catch (Throwable $exception) {
    if (!$backupVerified && is_string($backupPath)) {
        @unlink($backupPath);
    }
    if (is_string($encryptedPath) && is_file($encryptedPath . '.part')) {
        @unlink($encryptedPath . '.part');
    }
    fwrite(STDERR, 'Backup failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
