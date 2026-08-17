<?php

declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');

$root = dirname(__DIR__);
$config = require $root . '/config.php';
require $root . '/app/bootstrap.php';

$requestId = 'seed-canary-' . bin2hex(random_bytes(8));
try {
    if (empty($config['canary_enabled'])) {
        fwrite(STDOUT, 'Synthetic canary is disabled.' . PHP_EOL);
        exit(0);
    }
    $slug = trim((string)($config['canary_slug'] ?? ''));
    $target = trim((string)($config['canary_target_url'] ?? ''));
    if ($target === '') {
        $target = base_url($config) . '/';
    }
    if (!valid_slug($slug) || !valid_target_url($target, (int)($config['target_url_max_length'] ?? 2048))) {
        throw new RuntimeException('Synthetic canary slug or target URL is invalid.');
    }

    $pdo = database($config, 5000, true);
    $lookup = $pdo->prepare('SELECT id, target_url, is_active, deleted_at FROM links WHERE slug = :slug');
    $lookup->execute(['slug' => $slug]);
    $existing = $lookup->fetch();
    if (is_array($existing)) {
        if ((string)$existing['target_url'] !== $target
            || (int)$existing['is_active'] !== 1
            || $existing['deleted_at'] !== null) {
            throw new RuntimeException('The configured canary slug is already used by a conflicting link.');
        }
        fwrite(STDOUT, "Synthetic canary already exists: /{$slug}" . PHP_EOL);
        exit(0);
    }

    $now = utc_timestamp();
    $insert = $pdo->prepare(<<<'SQL'
        INSERT INTO links (slug, target_url, title, created_at, updated_at)
        VALUES (:slug, :target_url, :title, :created_at, :updated_at)
    SQL);
    $insert->execute([
        'slug' => $slug,
        'target_url' => $target,
        'title' => 'Synthetic monitoring canary',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    audit_event($pdo, $config, 'system', 'seed_canary', 'success', 'link', (string)$pdo->lastInsertId(), [
        'slug' => $slug,
    ]);
    fwrite(STDOUT, "Synthetic canary created: /{$slug}" . PHP_EOL);
} catch (Throwable $exception) {
    log_event($config, 'seed_canary_failed', ['error' => limit_text($exception->getMessage(), 300)]);
    fwrite(STDERR, 'Synthetic canary setup failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
