<?php

declare(strict_types=1);

require dirname(__DIR__) . '/lib/http_endpoint_monitor.php';

function endpoint_monitor_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$failedRequest = static fn (string $url, $context): array => ['body' => false, 'headers' => []];

foreach (['DNS failure', 'connection timeout'] as $failure) {
    $response = endpoint_response('http://unreachable.invalid/', 'GET', [], '', $failedRequest);
    endpoint_monitor_assert(
        $response['status'] === 0 && $response['body'] === '' && $response['location'] === null,
        $failure . ' did not produce a writable failure result.'
    );
}

$response = endpoint_response(
    'http://unreachable.invalid/',
    'GET',
    [],
    '',
    static fn (string $url, $context): array => ['body' => 'partial response', 'headers' => []]
);
endpoint_monitor_assert(
    $response['status'] === 0 && $response['body'] === 'partial response' && $response['location'] === null,
    'A response without headers was not handled safely.'
);

$response = endpoint_response(
    'https://example.com/',
    'HEAD',
    [],
    '',
    static fn (string $url, $context): array => [
        'body' => '',
        'headers' => ['HTTP/1.1 302 Found', 'Location: https://example.net/'],
    ]
);
endpoint_monitor_assert(
    $response['status'] === 302 && $response['location'] === 'https://example.net/',
    'Successful response headers were not parsed.'
);

fwrite(STDOUT, 'HTTP endpoint monitor tests passed.' . PHP_EOL);
