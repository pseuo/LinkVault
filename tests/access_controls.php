<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';
require dirname(__DIR__) . '/app/LinkService.php';

function access_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

try {
    $root = dirname(__DIR__);
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    foreach (glob($root . '/migrations/*.sql') ?: [] as $index => $migration) {
        $sql = file_get_contents($migration);
        access_assert(is_string($sql), 'Cannot read migration fixture.');
        $pdo->exec($sql);
        $pdo->exec('PRAGMA user_version = ' . ($index + 1));
    }
    access_assert((int)$pdo->query('PRAGMA user_version')->fetchColumn() === LINKVAULT_SCHEMA_VERSION,
        'The in-memory migration did not reach the current schema.');
    access_assert(linkvault_schema_problems($pdo) === [], 'The migrated schema is incomplete.');

    $service = new LinkService($pdo);
    $plaintext = 'PrivateShare!234';
    $hash = password_hash($plaintext, PASSWORD_DEFAULT);
    access_assert(is_string($hash), 'Cannot hash an access password.');
    $id = $service->create(
        'private01',
        'https://example.com/private',
        'Private link',
        null,
        [],
        false,
        null,
        null,
        false,
        'immediate',
        '',
        '',
        '',
        '',
        $hash,
        'This link is no longer available.',
        'https://example.com/fallback'
    );
    $link = $service->getAdminLink($id);
    access_assert(is_array($link) && password_verify($plaintext, (string)$link['access_password_hash']),
        'The link password was not stored as a verifiable hash.');
    access_assert((string)$link['access_password_hash'] !== $plaintext, 'The plaintext password was stored.');
    $auditState = audit_link_state($link);
    access_assert(($auditState['password_protected'] ?? false) === true
        && !array_key_exists('access_password_hash', $auditState), 'Audit state exposed the password hash.');

    $limits = [
        'link_unlock_max_attempts' => 3,
        'link_unlock_attempt_window' => 300,
        'link_unlock_lock_duration' => 300,
    ];
    $identifier = hash('sha256', 'test-client');
    $first = reserve_link_unlock_attempt($pdo, $id, $identifier, $limits);
    $second = reserve_link_unlock_attempt($pdo, $id, $identifier, $limits);
    $third = reserve_link_unlock_attempt($pdo, $id, $identifier, $limits);
    access_assert(!$first['blocked'] && !$second['blocked'] && $third['blocked']
        && $third['retry_after_seconds'] > 0, 'The dedicated unlock limit did not lock at its threshold.');
    clear_link_unlock_failures($pdo, $id, $identifier);
    access_assert((int)$pdo->query('SELECT COUNT(*) FROM link_password_attempts')->fetchColumn() === 0,
        'Successful verification could not clear its dedicated throttle state.');

    $_SESSION = [];
    set_link_unlock_grant($link, ['link_unlock_grant_ttl' => 120]);
    access_assert(consume_link_unlock_grant($link, false), 'The unlock grant did not authorize one access.');
    access_assert(!consume_link_unlock_grant($link, false), 'The unlock grant was reusable.');
    set_link_unlock_grant($link, ['link_unlock_grant_ttl' => 120]);
    access_assert(consume_link_unlock_grant($link, true) && consume_link_confirmation_grant($link),
        'A protected confirmation flow lost its one-time authorization.');
    access_assert(!consume_link_confirmation_grant($link), 'The confirmation authorization was reusable.');

    $updatedAt = (string)$link['updated_at'];
    access_assert($service->update(
        $id, (string)$link['target_url'], (string)$link['title'], null, $updatedAt,
        [], false, null, null, false, 'immediate', '', '', '', '', null, false,
        (string)$link['invalid_message'], (string)$link['fallback_url']
    ), 'A blank-password edit failed.');
    $preserved = $service->getAdminLink($id);
    access_assert(is_array($preserved) && hash_equals($hash, (string)$preserved['access_password_hash']),
        'A blank edit changed the existing password hash.');
    $replacementHash = password_hash('ReplacementAccess!234', PASSWORD_DEFAULT);
    access_assert($service->update(
        $id, (string)$preserved['target_url'], (string)$preserved['title'], null,
        (string)$preserved['updated_at'], [], false, null, null, false, 'immediate',
        '', '', '', '', $replacementHash, false, (string)$preserved['invalid_message'],
        (string)$preserved['fallback_url']
    ), 'Replacing the access password failed.');
    $replaced = $service->getAdminLink($id);
    access_assert(is_array($replaced) && hash_equals($replacementHash, (string)$replaced['access_password_hash']),
        'Replacing the access password did not store the new hash.');
    access_assert($service->update(
        $id, (string)$replaced['target_url'], (string)$replaced['title'], null,
        (string)$replaced['updated_at'], [], false, null, null, false, 'immediate',
        '', '', '', '', null, true, (string)$replaced['invalid_message'],
        (string)$replaced['fallback_url']
    ), 'Explicit password removal failed.');
    access_assert(!link_is_password_protected((array)$service->getAdminLink($id)),
        'Explicit password removal retained protection.');

    fwrite(STDOUT, 'Access-control tests passed.' . PHP_EOL);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Access-control test failed at line ' . $exception->getLine() . ': '
        . $exception->getMessage() . PHP_EOL);
    exit(1);
}
