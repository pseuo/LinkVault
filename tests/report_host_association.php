<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/app/bootstrap.php';
require $root . '/app/LinkService.php';
require $root . '/app/P2Service.php';

function report_host_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'linkvault-report-host-' . bin2hex(random_bytes(8)) . '.sqlite';
try {
    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    foreach (glob($root . '/migrations/*.sql') ?: [] as $migrationPath) {
        $version = (int)basename($migrationPath, '.sql');
        $pdo->exec(linkvault_verified_migration_sql($migrationPath, $version));
    }

    $linkId = (new LinkService($pdo))->create(
        'existing-slug',
        'https://target.example/path',
        'Target',
        null
    );
    $service = new P2Service($pdo, ['base_url' => 'https://vault.example']);
    $reporterHash = hash('sha256', 'reporter');

    $external = $service->submitReport(
        'https://attacker.example/existing-slug',
        'phishing',
        'External domain',
        '',
        $reporterHash
    );
    $lookup = $pdo->prepare('SELECT link_id, reported_url FROM abuse_reports WHERE public_id = :public_id');
    $lookup->execute(['public_id' => $external['public_id']]);
    $externalRow = $lookup->fetch();
    report_host_assert(is_array($externalRow) && $externalRow['link_id'] === null, 'An external host report was linked by matching slug.');
    report_host_assert($externalRow['reported_url'] === 'https://attacker.example/existing-slug', 'The external reported URL was not preserved.');

    $canonical = $service->submitReport(
        'https://vault.example/existing-slug',
        'phishing',
        'Canonical domain',
        '',
        $reporterHash
    );
    $lookup->execute(['public_id' => $canonical['public_id']]);
    report_host_assert((int)$lookup->fetchColumn() === $linkId, 'A canonical-host report was not linked.');

    $now = utc_timestamp();
    $insertDomain = $pdo->prepare(<<<'SQL'
        INSERT INTO short_domains (
            hostname, verification_token, verified_at, is_enabled, created_at, updated_at
        ) VALUES (
            'go.example', :verification_token, :verified_at, 1, :created_at, :updated_at
        )
    SQL);
    $insertDomain->execute([
        'verification_token' => str_repeat('c', 48),
        'verified_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $shortDomain = $service->submitReport(
        'https://go.example/existing-slug',
        'phishing',
        'Managed short domain',
        '',
        $reporterHash
    );
    $lookup->execute(['public_id' => $shortDomain['public_id']]);
    report_host_assert((int)$lookup->fetchColumn() === $linkId, 'A verified enabled short-domain report was not linked.');

    fwrite(STDOUT, "Report host association tests passed.\n");
} finally {
    @unlink($path);
    @unlink($path . '-wal');
    @unlink($path . '-shm');
}
