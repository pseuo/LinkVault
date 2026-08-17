<?php

declare(strict_types=1);

    $publicClient = new HttpClient($baseUrl);
    $reportPdo = new PDO('sqlite:' . $databasePath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $reportPage = $publicClient->request('GET', '/report');
    assert_true($reportPage['status'] === 200, 'The public report page must be accessible anonymously.');
    $reportCsrf = csrf_from($reportPage['body']);
    assert_true($reportCsrf !== '', 'The public report form is missing its CSRF token.');

    $missingReportCsrf = $publicClient->form('/report', [
        'url' => 'https://example.com/csrf-report',
        'reason' => 'phishing',
    ]);
    assert_true($missingReportCsrf['status'] === 400, 'An anonymous report without CSRF must be rejected.');
    assert_true(
        (int)$reportPdo->query("SELECT COUNT(*) FROM abuse_reports WHERE reported_url = 'https://example.com/csrf-report'")->fetchColumn() === 0,
        'A report rejected for missing CSRF must not be stored.'
    );

    $validReport = $publicClient->form('/report', [
        'csrf' => $reportCsrf,
        'url' => 'https://example.com/valid-report',
        'reason' => 'phishing',
        'details' => 'Smoke test report',
    ]);
    assert_true($validReport['status'] === 201, 'A valid anonymous report with CSRF must be accepted.');
