<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$config = require $root . '/config.php';
require $root . '/app/bootstrap.php';
require $root . '/app/P2Service.php';

try {
    $pdo = database($config, max(1000, (int)($config['sqlite_busy_timeout_ms'] ?? 5000)));
    $service = new P2Service($pdo, $config);
    $batchSize = max(1, min(1000, (int)($config['risk_scan_batch_size'] ?? 100)));
    $statement = $pdo->prepare(<<<'SQL'
        SELECT l.id, l.target_url, s.target_url_hash
        FROM links l LEFT JOIN link_risk_scans s ON s.link_id = l.id
        WHERE l.deleted_at IS NULL
        ORDER BY CASE WHEN s.link_id IS NULL THEN 0 ELSE 1 END, l.updated_at ASC, l.id ASC
    SQL);
    $statement->execute();
    $scanned = 0;
    $highRisk = 0;
    foreach ($statement as $row) {
        if ($scanned >= $batchSize) {
            break;
        }
        if (is_string($row['target_url_hash'] ?? null)
            && hash_equals((string)$row['target_url_hash'], hash('sha256', (string)$row['target_url']))) {
            continue;
        }
        $risk = $service->scanLink((int)$row['id']);
        $scanned++;
        if (in_array($risk['risk_level'], ['high', 'critical'], true)) {
            $highRisk++;
        }
    }
    fwrite(STDOUT, "Scanned {$scanned} links; {$highRisk} high-risk results.\n");
} catch (Throwable $exception) {
    log_event($config, 'risk_scan_failed', ['error' => limit_text($exception->getMessage(), 300)]);
    fwrite(STDERR, "Risk scan failed. Check the application log.\n");
    exit(1);
}
