<?php

declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');

$root = dirname(__DIR__);
$config = require $root . '/config.php';
require $root . '/app/bootstrap.php';
require $root . '/app/TargetHealthService.php';

if (empty($config['target_health_enabled'])) {
    fwrite(STDOUT, 'Target health checks are disabled.' . PHP_EOL);
    exit(0);
}

try {
    if (!extension_loaded('curl')) {
        throw new RuntimeException('The curl extension is required when target health checks are enabled.');
    }
    $pdo = database($config, 5000, true);
    $result = (new TargetHealthService($pdo, $config))->runBatch();
    audit_event($pdo, $config, 'system', 'target_health_check', 'success', 'target_health', null, $result);
    fwrite(STDOUT, sprintf(
        'Target health batch completed: processed=%d healthy=%d issues=%d backlog=%d%s',
        $result['processed'],
        $result['healthy'],
        $result['issues'],
        $result['backlog'],
        PHP_EOL
    ));
} catch (Throwable $exception) {
    try {
        linkvault_record_target_health_failure($config, $exception->getMessage());
    } catch (Throwable) {
    }
    log_event($config, 'target_health_check_failed', [
        'error' => limit_text($exception->getMessage(), 300),
    ]);
    fwrite(STDERR, 'Target health check failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
