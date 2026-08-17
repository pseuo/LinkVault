<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$config = require $root . '/config.php';
require $root . '/lib/database_schema.php';

$checks = [];
$commands = [];
$add = static function (string $name, string $status, string $detail, ?string $command = null) use (&$checks, &$commands): void {
    $checks[] = compact('name', 'status', 'detail');
    if ($status !== 'ok' && $command !== null && !in_array($command, $commands, true)) {
        $commands[] = $command;
    }
};

$phpReady = version_compare(PHP_VERSION, '8.5.0', '>=');
$add('PHP', $phpReady ? 'ok' : 'error', PHP_VERSION, 'Install PHP 8.5 or newer.');
foreach (['pdo_sqlite', 'sqlite3'] as $extension) {
    $loaded = extension_loaded($extension);
    $add('Extension ' . $extension, $loaded ? 'ok' : 'error', $loaded ? 'loaded' : 'missing', 'Enable the PHP ' . $extension . ' extension.');
}
$curlLoaded = extension_loaded('curl');
$curlRequired = !empty($config['target_health_enabled'])
    || trim((string)($config['alert_webhook_url'] ?? '')) !== ''
    || trim((string)($config['maintenance_webhook_url'] ?? '')) !== '';
$add('Extension curl', $curlLoaded ? 'ok' : ($curlRequired ? 'error' : 'warn'), $curlLoaded ? 'loaded' : 'missing');

$databasePath = (string)($config['database_path'] ?? '');
$databaseDirectory = $databasePath === '' ? '' : dirname($databasePath);
$directoryReady = $databaseDirectory !== '' && is_dir($databaseDirectory) && is_readable($databaseDirectory) && is_writable($databaseDirectory);
$add('Data directory', $directoryReady ? 'ok' : 'error', $databaseDirectory ?: 'not configured', 'Create the data directory and grant the PHP runtime user read/write access.');

if ($databasePath === '' || !is_file($databasePath)) {
    $add('Database migration', 'error', 'database is not initialized', 'php bin/migrate.php');
} elseif (!extension_loaded('pdo_sqlite')) {
    $add('Database migration', 'error', 'cannot inspect without pdo_sqlite');
} else {
    try {
        $pdo = new PDO('sqlite:' . $databasePath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $version = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
        $add('Database migration', $version === LINKVAULT_SCHEMA_VERSION ? 'ok' : 'error', "schema {$version}/" . LINKVAULT_SCHEMA_VERSION, 'php bin/migrate.php');
        $fts5 = (bool)$pdo->query("SELECT sqlite_compileoption_used('ENABLE_FTS5')")->fetchColumn();
        if (!$fts5) {
            try {
                $pdo->exec('CREATE VIRTUAL TABLE temp.linkvault_fts_probe USING fts5(value)');
                $fts5 = true;
            } catch (Throwable) {
            }
        }
        $add('SQLite FTS5', $fts5 ? 'ok' : 'error', $fts5 ? 'available' : 'unavailable', 'Install a SQLite build compiled with FTS5.');
    } catch (Throwable $exception) {
        $add('Database', 'error', $exception->getMessage(), 'php bin/migrate.php');
    }
}

$baseUrl = trim((string)($config['base_url'] ?? ''));
$https = str_starts_with(strtolower($baseUrl), 'https://');
$add('Public HTTPS', $https ? 'ok' : 'warn', $baseUrl !== '' ? $baseUrl : 'LINKVAULT_BASE_URL is not configured', 'Set LINKVAULT_BASE_URL=https://your-domain.example');

$backupDirectory = (string)($config['backup_directory'] ?? '');
$backupStatusDirectory = (string)($config['backup_status_directory'] ?? '');
$backupReady = $backupStatusDirectory !== ''
    ? linkvault_backup_status_directory_secure($backupStatusDirectory)
    : is_dir($backupDirectory) && is_readable($backupDirectory) && is_writable($backupDirectory);
$add('Backup status', $backupReady ? 'ok' : 'error', $backupStatusDirectory ?: ($backupDirectory ?: 'not configured'), 'Configure a protected backup status directory readable by the runtime user.');
foreach (['sqlite3', 'age', 'rclone'] as $binary) {
    $output = [];
    $code = 1;
    $probe = PHP_OS_FAMILY === 'Windows' ? 'where ' . $binary . ' 2>NUL' : 'command -v ' . $binary . ' 2>/dev/null';
    exec($probe, $output, $code);
    $required = $binary === 'sqlite3' || !empty($config['backup_remote_required']);
    $add('Binary ' . $binary, $code === 0 ? 'ok' : ($required ? 'error' : 'warn'), $code === 0 ? trim((string)($output[0] ?? 'available')) : 'not found in PATH');
}

if (PHP_OS_FAMILY === 'Linux') {
    foreach (['linkvault-backup.timer', 'linkvault-target-health.timer', 'linkvault-data-cleanup.timer'] as $timer) {
        $output = [];
        $code = 1;
        exec('systemctl is-enabled ' . escapeshellarg($timer) . ' 2>/dev/null', $output, $code);
        $add('Timer ' . $timer, $code === 0 ? 'ok' : 'warn', trim((string)($output[0] ?? 'not enabled')), 'sudo systemctl enable --now ' . $timer);
    }
} else {
    $add('Scheduled tasks', 'warn', 'systemd timer status is only available on Linux; configure equivalent scheduled tasks');
}

fwrite(STDOUT, 'LinkVault doctor' . PHP_EOL . str_repeat('=', 64) . PHP_EOL);
foreach ($checks as $check) {
    $label = match ($check['status']) { 'ok' => 'OK', 'warn' => 'WARN', default => 'ERROR' };
    fwrite(STDOUT, sprintf('[%-5s] %-30s %s', $label, $check['name'], $check['detail']) . PHP_EOL);
}
if ($commands) {
    fwrite(STDOUT, PHP_EOL . 'Next steps:' . PHP_EOL);
    foreach ($commands as $index => $command) {
        fwrite(STDOUT, ($index + 1) . '. ' . $command . PHP_EOL);
    }
}

$hasErrors = count(array_filter($checks, static fn (array $check): bool => $check['status'] === 'error')) > 0;
exit($hasErrors ? 1 : 0);
