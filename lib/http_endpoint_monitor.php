<?php

declare(strict_types=1);

/**
 * @param list<string> $headers
 * @param null|callable(string, resource): array{body: string|false, headers: list<string>} $transport
 * @return array{status: int, body: string, location: ?string, latency_ms: int}
 */
function endpoint_response(
    string $url,
    string $method,
    array $headers = [],
    string $body = '',
    ?callable $transport = null
): array {
    $startedAt = hrtime(true);
    $requestHeaders = array_merge(['User-Agent: linkvault-endpoint-monitor', 'Connection: close'], $headers);
    if ($body !== '') {
        $requestHeaders[] = 'Content-Length: ' . strlen($body);
    }
    $context = stream_context_create(['http' => [
        'method' => $method,
        'header' => implode("\r\n", $requestHeaders),
        'content' => $body,
        'ignore_errors' => true,
        'follow_location' => 0,
        'timeout' => 10,
    ]]);
    if ($transport === null) {
        $responseBody = @file_get_contents($url, false, $context);
        $responseHeaders = $http_response_header;
    } else {
        $response = $transport($url, $context);
        $responseBody = $response['body'];
        $responseHeaders = $response['headers'];
    }

    $status = 0;
    $location = null;
    foreach ($responseHeaders as $line) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $line, $matches) === 1) {
            $status = (int)$matches[1];
        } elseif (stripos($line, 'Location:') === 0) {
            $location = trim(substr($line, strlen('Location:')));
        }
    }
    return [
        'status' => $status,
        'body' => is_string($responseBody) ? $responseBody : '',
        'location' => $location,
        'latency_ms' => max(0, (int)round((hrtime(true) - $startedAt) / 1_000_000)),
    ];
}
