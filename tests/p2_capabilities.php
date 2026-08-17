<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/app/bootstrap.php';
require $root . '/app/LinkService.php';
require $root . '/app/P2Service.php';
require $root . '/app/AdminNotificationService.php';

function p2_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'linkvault-p2-' . bin2hex(random_bytes(8)) . '.sqlite';
try {
    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    foreach (glob($root . '/migrations/*.sql') ?: [] as $migrationPath) {
        $version = (int)basename($migrationPath, '.sql');
        $pdo->exec(linkvault_verified_migration_sql($migrationPath, $version));
    }
    $links = new LinkService($pdo);
    $p2 = new P2Service($pdo, ['base_url' => 'https://service.example.test'], $links);
    $first = $links->create('p2-main', 'https://docs.example.test/path', 'Docs', null);
    $second = $links->create('p2-copy', 'https://docs.example.test/path', 'Copy', null);

    $p2->saveTagRule('Docs host', 'host', 'equals', 'docs.example.test', ['docs', 'work']);
    $tagResult = $p2->applyTagRules([$first, $second]);
    p2_assert($tagResult === ['matched' => 2, 'changed' => 2], 'Tag rules were not applied.');
    p2_assert(count($p2->duplicateGroups()) === 1, 'Duplicate group was not detected.');
    $merge = $p2->mergeDuplicates($first, [$second]);
    p2_assert($merge['merged'] === 1, 'Duplicate was not merged.');
    p2_assert((int)($links->find('p2-copy')['id'] ?? 0) === $first, 'Merged short code was not retained as an alias.');

    $token = 'slt_' . str_repeat('a', 43);
    $tokenInsert = $pdo->prepare(<<<'SQL'
        INSERT INTO api_tokens (name, token_prefix, token_hash, scopes, created_at)
        VALUES ('Conversions', 'slt_aaaaaaaa', :token_hash, 'conversions:write', :created_at)
    SQL);
    $tokenInsert->execute(['token_hash' => hash('sha256', $token), 'created_at' => utc_timestamp()]);
    $tokenId = (int)$pdo->lastInsertId();
    $conversion = [
        'event_id' => 'purchase-0001',
        'event' => 'purchase',
        'link_id' => $first,
        'occurred_at' => utc_timestamp(),
        'value_minor' => 1299,
        'currency' => 'CNY',
        'metadata' => ['order' => 'redacted'],
    ];
    $recorded = $p2->recordConversion($tokenId, 'p2-idempotency-1', $conversion);
    $replayed = $p2->recordConversion($tokenId, 'p2-idempotency-1', $conversion);
    p2_assert(!$recorded['replayed'] && $replayed['replayed'], 'Conversion idempotency failed.');
    $futureConversion = $conversion;
    $futureConversion['event_id'] = 'purchase-future-0001';
    $futureConversion['occurred_at'] = '2099-01-01T00:00:00Z';
    try {
        $p2->recordConversion($tokenId, 'p2-future-key', $futureConversion);
        throw new RuntimeException('Future conversion event was accepted.');
    } catch (InvalidArgumentException) {
    }

    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $barrierPath = $path . '.start';
    $childCode = <<<'PHP'
require $argv[1];
$pdo = new PDO('sqlite:' . $argv[2], null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec('PRAGMA busy_timeout = 5000');
require $argv[3];
while (!is_file($argv[4])) {
    usleep(1000);
}
try {
    $result = (new P2Service($pdo, ['base_url' => 'https://service.example.test']))->recordConversion((int)$argv[5], $argv[6], json_decode($argv[7], true, 32, JSON_THROW_ON_ERROR));
    echo json_encode(['ok' => true, 'result' => $result], JSON_THROW_ON_ERROR);
} catch (ConversionIdempotencyConflict $exception) {
    echo json_encode(['ok' => false, 'conflict' => true], JSON_THROW_ON_ERROR);
}
PHP;
    $runConcurrent = static function (string $key, array $payloads) use ($childCode, $root, $path, $barrierPath, $tokenId): array {
        @unlink($barrierPath);
        $children = [];
        for ($index = 0; $index < 2; $index++) {
            $pipes = [];
            $process = proc_open(
                [PHP_BINARY, '-r', $childCode, $root . '/app/bootstrap.php', $path, $root . '/app/P2Service.php', $barrierPath,
                    (string)$tokenId, $key, json_encode($payloads[$index], JSON_THROW_ON_ERROR)],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                $root,
                null,
                ['bypass_shell' => true]
            );
            p2_assert(is_resource($process), 'Cannot start concurrent conversion worker.');
            fclose($pipes[0]);
            $children[] = [$process, $pipes];
        }
        p2_assert(file_put_contents($barrierPath, 'start') === 5, 'Cannot release conversion workers.');
        $results = [];
        foreach ($children as [$process, $pipes]) {
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            p2_assert(proc_close($process) === 0, 'Concurrent conversion worker failed: ' . $stderr);
            $results[] = json_decode((string)$stdout, true, 16, JSON_THROW_ON_ERROR);
        }
        return $results;
    };
    $concurrentConversion = $conversion;
    $concurrentConversion['event_id'] = 'purchase-concurrent-0001';
    $concurrentResults = $runConcurrent('p2-concurrent-key', [$concurrentConversion, $concurrentConversion]);
    p2_assert(count(array_filter($concurrentResults, static fn (array $result): bool => $result['ok'] && !$result['result']['replayed'])) === 1,
        'Concurrent conversion did not elect exactly one original request.');
    p2_assert(count(array_filter($concurrentResults, static fn (array $result): bool => $result['ok'] && $result['result']['replayed'])) === 1,
        'Concurrent conversion did not replay the first result.');
    p2_assert((int)$pdo->query("SELECT COUNT(*) FROM conversion_events WHERE idempotency_key_hash = '" . hash('sha256', 'p2-concurrent-key') . "'")->fetchColumn() === 1,
        'Concurrent conversion inserted duplicate events.');
    $differentPayload = $concurrentConversion;
    $differentPayload['event_id'] = 'purchase-concurrent-0002';
    $differentPayload['metadata'] = ['order' => 'different'];
    $conflictPayload = $differentPayload;
    $conflictPayload['event_id'] = 'purchase-concurrent-0003';
    $conflictPayload['metadata'] = ['order' => 'conflict'];
    $conflictResults = $runConcurrent('p2-concurrent-conflict', [$differentPayload, $conflictPayload]);
    p2_assert(count(array_filter($conflictResults, static fn (array $result): bool => $result['ok'])) === 1
        && count(array_filter($conflictResults, static fn (array $result): bool => $result['conflict'] ?? false)) === 1,
        'Concurrent conversion payload conflict was not enforced.');
    @unlink($barrierPath);
    $body = json_encode($conversion, JSON_THROW_ON_ERROR);
    $timestamp = (string)time();
    $signature = 'sha256=' . hash_hmac('sha256', $timestamp . '.p2-idempotency-1.' . $body, $token);
    p2_assert(P2Service::validConversionSignature($token, $timestamp, 'p2-idempotency-1', $body, $signature), 'Conversion signature failed.');
    $p2->saveFunnel('Purchase', ['purchase']);
    $funnel = $p2->funnelReport();
    p2_assert((int)$funnel[0]['stages'][1]['count'] === 3, 'Funnel did not count conversions.');

    $p2->addBlacklistDomain('evil.example.test', 'Known phishing');
    $risk = $p2->evaluateRisk('https://login.evil.example.test/verify-account');
    p2_assert($risk['risk_level'] === 'critical' && $risk['score'] === 100, 'Blacklist risk was not critical.');
    $report = $p2->submitReport(
        'https://service.example.test/p2-main',
        'phishing',
        'Credential collection',
        '',
        hash('sha256', 'reporter')
    );
    p2_assert(strlen($report['public_id']) === 24, 'Report reference is invalid.');
    $reportLookup = $pdo->prepare('SELECT * FROM abuse_reports WHERE public_id = :public_id');
    $reportLookup->execute(['public_id' => $report['public_id']]);
    $reportRow = $reportLookup->fetch();
    p2_assert((int)($reportRow['link_id'] ?? 0) === $first, 'A canonical-host report was not linked.');
    $notifications = new AdminNotificationService($pdo);
    $notifications->sync();
    $inbox = $notifications->inbox();
    p2_assert($inbox['unread'] > 0 && $inbox['items'][0]['notification_type'] === 'open_report', 'Open report notification was not created.');
    p2_assert($notifications->markRead((int)$inbox['items'][0]['id']), 'Notification could not be marked as read.');
    p2_assert($notifications->dismiss((int)$inbox['items'][0]['id']), 'Notification could not be dismissed.');
    p2_assert(is_array($reportRow) && $p2->processReport((int)$reportRow['id'], 'disable_link', 'Confirmed'), 'Report action failed.');
    p2_assert((int)($links->getAdminLink($first)['is_active'] ?? 1) === 0, 'Abuse action did not disable the link.');
    p2_assert((int)$pdo->query("SELECT COUNT(*) FROM link_status_history WHERE link_id = {$first} AND event = 'disabled_by_report'")->fetchColumn() === 1,
        'Abuse action did not write link status history.');
    p2_assert((int)$pdo->query("SELECT COUNT(*) FROM audit_events WHERE action = 'abuse_report_action' AND outcome = 'success'")->fetchColumn() === 1,
        'Abuse action did not write an audit event.');

    fwrite(STDOUT, "P2 capability tests passed.\n");
} finally {
    @unlink($path);
    @unlink($path . '-wal');
    @unlink($path . '-shm');
}
