<?php

declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');

$root = dirname(__DIR__);
$config = require $root . '/config.php';
require $root . '/app/bootstrap.php';
require $root . '/app/LinkService.php';

try {
    $pdo = database($config, 5000, true);
    $service = new LinkService($pdo);
    $batchSize = max(10, min(400, (int)($config['domain_retirement_batch_size'] ?? 200)));
    $maxBatches = max(1, min(100, (int)($config['domain_retirement_max_batches'] ?? 10)));
    $result = ['processed' => 0, 'migrated' => 0, 'completed' => 0];
    for ($batch = 0; $batch < $maxBatches; $batch++) {
        $step = $service->processShortDomainRetirementBatch($batchSize);
        if ($step['status'] === 'idle') {
            break;
        }
        $result['processed']++;
        $result['migrated'] += (int)$step['migrated'];
        $result['completed'] += $step['status'] === 'completed' ? 1 : 0;
    }
    log_event($config, 'short_domain_retirement_worker_completed', $result);
    fwrite(STDOUT, 'Domain retirement worker completed: ' . json_encode($result, JSON_THROW_ON_ERROR) . PHP_EOL);
} catch (Throwable $exception) {
    log_event($config, 'short_domain_retirement_worker_failed', [
        'error' => limit_text($exception->getMessage(), 300),
    ]);
    fwrite(STDERR, 'Domain retirement worker failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
