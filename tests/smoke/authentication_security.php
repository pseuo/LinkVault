<?php

declare(strict_types=1);

    $createManagedToken = $client->form('/api-tokens/create', [
        'csrf' => $csrf,
        'name' => 'Automation token',
        'expires_at' => gmdate('Y-m-d\TH:i', time() + 3600),
        'expires_at_offset' => '0',
    ]);
    assert_true($createManagedToken['status'] === 303, 'Creating a managed API token must redirect.');
    $managedTokenPage = $client->request('GET', '/?section=api');
    $managedApiToken = generated_api_token_from($managedTokenPage['body']);
    assert_true(str_starts_with($managedApiToken, 'slt_'), 'Managed API token has an unexpected format.');
    assert_true((string)$managedPdo->query("SELECT token_hash FROM api_tokens WHERE name = 'Automation token'")->fetchColumn() === hash('sha256', $managedApiToken), 'Managed API token was not stored as a digest.');
    assert_true((string)$managedPdo->query("SELECT scopes FROM api_tokens WHERE name = 'Automation token'")->fetchColumn() === 'links:create', 'A legacy token form submission gained additional API scopes.');
    $managedTokenId = (int)$managedPdo->query("SELECT id FROM api_tokens WHERE name = 'Automation token'")->fetchColumn();
    assert_true($managedTokenId > 0 && str_contains($managedTokenPage['body'], 'Automation token'), 'Managed API token metadata is missing from the status page.');
    assert_true(
        str_contains($managedTokenPage['body'], 'data-sensitive-download')
            && str_contains($managedTokenPage['body'], 'data-sensitive-print')
            && str_contains($managedTokenPage['body'], 'data-offline-saved'),
        'Managed API token result is missing offline save actions.'
    );

    $managedTokenCreate = $client->request('POST', '/api/shorten', json_encode([
        'url' => 'https://example.com/managed-token',
        'slug' => 'apitoken1',
    ], JSON_THROW_ON_ERROR), [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $managedApiToken,
    ]);
    assert_true($managedTokenCreate['status'] === 201, 'Managed API token did not authenticate.');
    assert_true((int)$managedPdo->query("SELECT use_count FROM api_tokens WHERE id = {$managedTokenId}")->fetchColumn() === 1, 'Managed API token use count was not updated.');
    assert_true((int)$managedPdo->query("SELECT COUNT(*) FROM api_token_usage WHERE token_id = {$managedTokenId} AND outcome = 'accepted'")->fetchColumn() === 1, 'Managed API token usage was not recorded.');

    assert_true($client->form('/api-tokens/rotate', [
        'csrf' => $csrf,
        'id' => $managedTokenId,
        'expires_at' => '',
        'expires_at_offset' => '0',
        'overlap_minutes' => '5',
    ])['status'] === 303, 'Rotating a managed API token must redirect.');
    $rotatedTokenPage = $client->request('GET', '/?section=api');
    $rotatedApiToken = generated_api_token_from($rotatedTokenPage['body']);
    $rotatedTokenId = (int)$managedPdo->query("SELECT id FROM api_tokens WHERE rotated_from_id = {$managedTokenId}")->fetchColumn();
    assert_true($rotatedTokenId > 0 && $rotatedApiToken !== $managedApiToken, 'API token rotation did not create a replacement.');
    assert_true((string)$managedPdo->query("SELECT scopes FROM api_tokens WHERE id = {$rotatedTokenId}")->fetchColumn() === 'links:create', 'API token rotation did not preserve its scopes.');
    assert_true(
        (string)$managedPdo->query("SELECT rotation_expires_at FROM api_tokens WHERE id = {$managedTokenId}")->fetchColumn() > gmdate('Y-m-d\TH:i:s\Z'),
        'API token rotation did not retain the old token for the overlap window.'
    );
    $oldTokenAttempt = $client->request('POST', '/api/shorten', '{}', [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $managedApiToken,
    ]);
    assert_true($oldTokenAttempt['status'] === 422, 'The old API token was not usable during the rotation overlap window.');
    assert_true((int)$managedPdo->query("SELECT COUNT(*) FROM api_token_usage WHERE token_id = {$managedTokenId} AND outcome = 'accepted'")->fetchColumn() >= 2, 'Overlapping old-token use was not recorded as accepted.');
    $managedPdo->exec("UPDATE api_tokens SET rotation_expires_at = '2000-01-01T00:00:00Z' WHERE id = {$managedTokenId}");
    $retiredTokenAttempt = $client->request('POST', '/api/shorten', '{}', [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $managedApiToken,
    ]);
    assert_true($retiredTokenAttempt['status'] === 401, 'The old API token remained usable after its rotation deadline.');
    assert_true((int)$managedPdo->query("SELECT COUNT(*) FROM api_token_usage WHERE token_id = {$managedTokenId} AND outcome = 'expired'")->fetchColumn() === 0, 'Retired old-token use wrote an API usage record.');
    $rotatedTokenCreate = $client->request('POST', '/api/shorten', json_encode([
        'url' => 'https://example.com/rotated-token',
        'slug' => 'apitoken2',
    ], JSON_THROW_ON_ERROR), [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $rotatedApiToken,
    ]);
    assert_true($rotatedTokenCreate['status'] === 201, 'Rotated API token did not authenticate.');

    $managedPdo->exec("UPDATE api_tokens SET expires_at = '2000-01-01T00:00:00Z' WHERE id = {$rotatedTokenId}");
    $expiredTokenAttempt = $client->request('POST', '/api/shorten', '{}', [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $rotatedApiToken,
    ]);
    assert_true($expiredTokenAttempt['status'] === 401, 'Expired API token remained usable.');
    assert_true((int)$managedPdo->query("SELECT COUNT(*) FROM api_token_usage WHERE token_id = {$rotatedTokenId} AND outcome = 'expired'")->fetchColumn() === 0, 'Expired API token use wrote an API usage record.');
    $managedPdo->exec("UPDATE api_tokens SET expires_at = NULL WHERE id = {$rotatedTokenId}");
    assert_true($client->form('/api-tokens/revoke', ['csrf' => $csrf, 'id' => $rotatedTokenId])['status'] === 303, 'Revoking a managed API token must redirect.');
    $revokedTokenAttempt = $client->request('POST', '/api/shorten', '{}', [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $rotatedApiToken,
    ]);
    assert_true($revokedTokenAttempt['status'] === 401, 'Revoked API token remained usable.');
    assert_true((int)$managedPdo->query("SELECT COUNT(*) FROM audit_events WHERE action IN ('api_token_create', 'api_token_rotate', 'api_token_revoke') AND outcome = 'success'")->fetchColumn() === 3, 'API token lifecycle changes were not audited.');

    $securityPage = $client->request('GET', '/?section=security');
    assert_true(str_contains($securityPage['body'], '管理员第二因素'), 'The administrator security panel is missing.');
    assert_true($client->form('/security/totp/setup', [
        'csrf' => $csrf,
        'password' => $password,
    ])['status'] === 303, 'Starting TOTP setup must redirect.');
    $totpSetupPage = $client->request('GET', '/?section=security');
    $totpSecret = totp_secret_from($totpSetupPage['body']);
    assert_true(str_contains($totpSetupPage['body'], 'otpauth://totp/'), 'TOTP setup does not render provisioning data.');
    assert_true($client->form('/security/totp/enable', [
        'csrf' => $csrf,
        'code' => totp_test_code($totpSecret),
    ])['status'] === 303, 'Enabling TOTP must redirect.');
    $recoveryPage = $client->request('GET', '/?section=security');
    $recoveryCodes = recovery_codes_from($recoveryPage['body']);
    assert_true(count($recoveryCodes) === 10, 'TOTP setup did not generate ten recovery codes.');
    assert_true(
        str_contains($recoveryPage['body'], 'linkvault-recovery-codes.txt')
            && str_contains($recoveryPage['body'], '已离线保存'),
        'Recovery codes are missing download, print, or offline confirmation controls.'
    );
    $encryptedSecret = (string)$managedPdo->query('SELECT totp_secret_encrypted FROM admin_security WHERE id = 1')->fetchColumn();
    assert_true($encryptedSecret !== '' && !str_contains($encryptedSecret, $totpSecret), 'The TOTP secret was stored in plaintext.');
    assert_true((string)$managedPdo->query('SELECT code_hash FROM admin_recovery_codes ORDER BY id LIMIT 1')->fetchColumn() !== str_replace('-', '', $recoveryCodes[0]), 'A recovery code was stored in plaintext.');

    assert_true($client->form('/logout', ['csrf' => $csrf])['status'] === 303, 'Logout before TOTP login failed.');
    $managedPdo->exec('UPDATE admin_security SET totp_last_counter = -1 WHERE id = 1');
    $totpLoginPage = $client->request('GET', '/login');
    assert_true(str_contains($totpLoginPage['body'], 'name="second_factor"'), 'TOTP-enabled login is missing its second-factor input.');
    assert_true($client->form('/login', [
        'csrf' => csrf_from($totpLoginPage['body']),
        'password' => $password,
        'second_factor' => totp_test_code($totpSecret),
    ])['status'] === 303, 'A valid TOTP code did not authenticate the administrator.');
    $csrf = csrf_from($client->request('GET', '/')['body']);
    assert_true($client->form('/logout', ['csrf' => $csrf])['status'] === 303, 'Logout before recovery login failed.');
    $recoveryLoginPage = $client->request('GET', '/login');
    assert_true($client->form('/login', [
        'csrf' => csrf_from($recoveryLoginPage['body']),
        'password' => $password,
        'second_factor' => $recoveryCodes[0],
    ])['status'] === 303, 'A valid recovery code did not authenticate the administrator.');
    $adminAfterRecovery = $client->request('GET', '/');
    $csrf = csrf_from($adminAfterRecovery['body']);
    assert_true((int)$managedPdo->query('SELECT COUNT(*) FROM admin_recovery_codes WHERE used_at IS NOT NULL')->fetchColumn() === 1, 'A used recovery code was not consumed.');
    assert_true((int)$managedPdo->query("SELECT COUNT(*) FROM audit_events WHERE action = 'totp_enable' AND outcome = 'success'")->fetchColumn() === 1, 'TOTP enablement was not audited.');
