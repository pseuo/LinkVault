<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/WebhookClient.php';

function webhook_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

final class WebhookFakeResolver implements TargetHealthResolver
{
    /** @param list<string> $answers */
    public function __construct(private readonly array $answers)
    {
    }

    public int $calls = 0;

    public function resolve(string $host): array
    {
        $this->calls++;
        return $this->answers;
    }
}

final class WebhookFakeTransport implements WebhookTransport
{
    /** @param array{ok?: bool, status?: int, primary_ip?: string, effective_url?: string} $response */
    public function __construct(private readonly array $response = [])
    {
    }

    /** @var list<array<string, mixed>> */
    public array $requests = [];

    public function post(
        string $url,
        string $host,
        int $port,
        string $pinnedIp,
        string $payload,
        array $headers
    ): array {
        $this->requests[] = compact('url', 'host', 'port', 'pinnedIp', 'payload', 'headers');
        return array_merge([
            'ok' => true,
            'status' => 204,
            'primary_ip' => $pinnedIp,
            'effective_url' => $url,
        ], $this->response);
    }
}

function webhook_policy_reason(string $url, string $token = ''): string
{
    try {
        WebhookClient::assertConfiguration($url, $token);
    } catch (WebhookPolicyViolation $exception) {
        return $exception->reason;
    }
    return '';
}

try {
    foreach ([
        'http://public.test/' => 'https_required',
        'https://user:secret@public.test/' => 'userinfo_forbidden',
        'https://public.test/path#fragment' => 'fragment_forbidden',
        'https://public.test:8443/' => 'unsafe_port',
        "https://public.test/path\r\nX-Test: injected" => 'invalid_url',
        'https://127.0.0.1/' => 'private_address',
        'https://[::1]/' => 'private_address',
    ] as $url => $reason) {
        webhook_assert(webhook_policy_reason($url) === $reason, 'Unsafe webhook URL was accepted: ' . $reason);
    }
    foreach (["line\nbreak", 'token with space', str_repeat('a', 4097)] as $token) {
        webhook_assert(
            webhook_policy_reason('https://public.test/hook', $token) === 'invalid_bearer_token',
            'Unsafe webhook Bearer token was accepted.'
        );
    }
    WebhookClient::assertConfiguration('https://public.test/hook?channel=ops', 'valid-token_123');

    foreach ([
        [['192.168.1.2'], 'private_address'],
        [['93.184.216.34', '10.0.0.1'], 'mixed_dns_blocked'],
        [['not-an-ip'], 'invalid_dns_answer'],
    ] as [$answers, $reason]) {
        $transport = new WebhookFakeTransport();
        try {
            (new WebhookClient(new WebhookFakeResolver($answers), $transport))
                ->postJson('https://public.test/hook', '{}');
            throw new RuntimeException('Unsafe DNS answer was accepted: ' . $reason);
        } catch (WebhookPolicyViolation $exception) {
            webhook_assert($exception->reason === $reason, 'Unexpected webhook DNS policy reason.');
        }
        webhook_assert($transport->requests === [], 'Blocked webhook DNS reached the transport.');
    }

    $resolver = new WebhookFakeResolver(['93.184.216.34']);
    $transport = new WebhookFakeTransport();
    $status = (new WebhookClient($resolver, $transport))->postJson(
        'https://public.test/hook?channel=ops',
        '{"ok":true}',
        'valid-token_123'
    );
    webhook_assert($status === 204 && $resolver->calls === 1, 'Valid webhook delivery did not succeed.');
    webhook_assert(count($transport->requests) === 1, 'Valid webhook delivery made an unexpected number of requests.');
    $request = $transport->requests[0];
    webhook_assert(
        $request['url'] === 'https://public.test/hook?channel=ops'
            && $request['pinnedIp'] === '93.184.216.34'
            && in_array('Authorization: Bearer valid-token_123', $request['headers'], true),
        'Webhook delivery was not pinned or did not carry the validated Bearer token.'
    );

    $redirectTransport = new WebhookFakeTransport(['status' => 302]);
    $redirectStatus = (new WebhookClient(
        new WebhookFakeResolver(['93.184.216.34']),
        $redirectTransport
    ))->postJson('https://public.test/hook', '{}');
    webhook_assert($redirectStatus === 302 && count($redirectTransport->requests) === 1,
        'Webhook redirect handling made more than one request.');

    foreach ([
        ['primary_ip' => '1.1.1.1'],
        ['effective_url' => 'https://other.test/hook'],
        ['ok' => false],
    ] as $response) {
        $secret = 'sensitive-token';
        try {
            (new WebhookClient(
                new WebhookFakeResolver(['93.184.216.34']),
                new WebhookFakeTransport($response)
            ))->postJson('https://public.test/hook', '{}', $secret);
            throw new RuntimeException('Unsafe webhook transport response was accepted.');
        } catch (RuntimeException $exception) {
            webhook_assert(!str_contains($exception->getMessage(), $secret), 'Webhook error exposed the Bearer token.');
        }
    }

    fwrite(STDOUT, 'Webhook policy tests passed.' . PHP_EOL);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
