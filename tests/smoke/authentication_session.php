<?php

declare(strict_types=1);

    $invalidCsrf = $client->form('/login', ['csrf' => 'invalid', 'password' => $password]);
    assert_true($invalidCsrf['status'] === 303, 'Invalid login CSRF must be rejected with a redirect.');

    $csrf = login($client, $password);
    $invalidAction = $client->form('/shorten', [
        'csrf' => 'invalid',
        'target_url' => 'https://example.com/csrf',
        'custom_slug' => 'csrf01',
    ]);
    assert_true($invalidAction['status'] === 303, 'Invalid action CSRF must be rejected.');
    $loggedOutPage = $client->request('GET', '/');
    assert_true(!str_contains($loggedOutPage['body'], 'action="/logout"'), 'Invalid CSRF must reset authentication.');

    $csrf = login($client, $password);
