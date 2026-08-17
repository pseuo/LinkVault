<?php

declare(strict_types=1);

require_once __DIR__ . '/TargetHealthService.php';

interface WebhookTransport
{
    /**
     * @param list<string> $headers
     * @return array{ok: bool, status: int, primary_ip: string, effective_url: string}
     */
    public function post(
        string $url,
        string $host,
        int $port,
        string $pinnedIp,
        string $payload,
        array $headers
    ): array;
}

final class WebhookCurlTransport implements WebhookTransport
{
    #[\Override]
    public function post(
        string $url,
        string $host,
        int $port,
        string $pinnedIp,
        string $payload,
        array $headers
    ): array {
        if (!extension_loaded('curl')) {
            throw new RuntimeException('The curl extension is required for webhook delivery.');
        }
        $handle = curl_init();
        if ($handle === false) {
            throw new RuntimeException('Cannot initialize webhook delivery.');
        }

        $responseBytes = 0;
        $responseTooLarge = false;
        $resolveHost = str_contains($host, ':') ? '[' . $host . ']' : $host;
        $resolveAddress = str_contains($pinnedIp, ':') ? '[' . $pinnedIp . ']' : $pinnedIp;
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_CONNECTTIMEOUT_MS => 3000,
            CURLOPT_TIMEOUT_MS => 10000,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROXY => '',
            CURLOPT_HTTPAUTH => CURLAUTH_NONE,
            CURLOPT_UNRESTRICTED_AUTH => false,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RESOLVE => [$resolveHost . ':' . $port . ':' . $resolveAddress],
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (
                &$responseBytes,
                &$responseTooLarge
            ): int {
                $length = strlen($chunk);
                $responseBytes += $length;
                if ($responseBytes > 65536) {
                    $responseTooLarge = true;
                    return 0;
                }
                return $length;
            },
        ];
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
        }

        try {
            if (!curl_setopt_array($handle, $options)) {
                throw new RuntimeException('Cannot configure webhook delivery.');
            }
            $ok = curl_exec($handle) !== false;
            $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $primaryIp = (string)curl_getinfo($handle, CURLINFO_PRIMARY_IP);
            $effectiveUrl = (string)curl_getinfo($handle, CURLINFO_EFFECTIVE_URL);
        } finally {
            curl_close($handle);
        }
        if ($responseTooLarge) {
            throw new RuntimeException('Webhook response exceeded the size limit.');
        }

        return [
            'ok' => $ok,
            'status' => $status,
            'primary_ip' => $primaryIp,
            'effective_url' => $effectiveUrl,
        ];
    }
}

final class WebhookPolicyViolation extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct('Webhook configuration violates policy: ' . $reason . '.');
    }
}

final class WebhookClient
{
    private readonly TargetHealthResolver $resolver;
    private readonly WebhookTransport $transport;

    public function __construct(?TargetHealthResolver $resolver = null, ?WebhookTransport $transport = null)
    {
        $this->resolver = $resolver ?? new TargetHealthDnsResolver();
        $this->transport = $transport ?? new WebhookCurlTransport();
    }

    public static function assertConfiguration(string $url, string $bearerToken = ''): void
    {
        $target = self::parseUrl($url);
        self::assertBearerToken($bearerToken);
        if (filter_var($target['host'], FILTER_VALIDATE_IP)
            && !TargetHealthService::isGloballyRoutableIp($target['host'])) {
            throw new WebhookPolicyViolation('private_address');
        }
    }

    public function postJson(string $url, string $payload, string $bearerToken = '', array $extraHeaders = []): int
    {
        $target = self::parseUrl($url);
        self::assertBearerToken($bearerToken);
        $addresses = $this->validatedAddresses($target['host']);
        $pinnedIp = $addresses[0];
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'Accept-Encoding: identity',
            'Content-Length: ' . strlen($payload),
            'User-Agent: LinkVault-Webhook/1.0',
        ];
        if ($bearerToken !== '') {
            $headers[] = 'Authorization: Bearer ' . $bearerToken;
        }
        foreach ($extraHeaders as $header) {
            if (!is_string($header) || strlen($header) > 512 || str_contains($header, "\r")
                || str_contains($header, "\n") || !str_starts_with($header, 'X-LinkVault-')) {
                throw new InvalidArgumentException('Invalid lifecycle webhook header.');
            }
            $headers[] = $header;
        }
        $response = $this->transport->post(
            $target['canonical'],
            $target['host'],
            $target['port'],
            $pinnedIp,
            $payload,
            $headers
        );
        if (empty($response['ok'])) {
            throw new RuntimeException('Webhook request failed.');
        }
        if (!self::sameIp((string)($response['primary_ip'] ?? ''), $pinnedIp)) {
            throw new RuntimeException('Webhook connection address did not match the validated address.');
        }
        if ((string)($response['effective_url'] ?? '') !== $target['canonical']) {
            throw new RuntimeException('Webhook effective URL changed unexpectedly.');
        }
        return max(0, (int)($response['status'] ?? 0));
    }

    /** @return array{canonical: string, host: string, port: int} */
    private static function parseUrl(string $url): array
    {
        if ($url === '' || strlen($url) > 2048
            || preg_match('/[^\x21-\x7e]|\\\\/', $url) === 1
            || !filter_var($url, FILTER_VALIDATE_URL)) {
            throw new WebhookPolicyViolation('invalid_url');
        }
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https') {
            throw new WebhookPolicyViolation('https_required');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new WebhookPolicyViolation('userinfo_forbidden');
        }
        if (isset($parts['fragment'])) {
            throw new WebhookPolicyViolation('fragment_forbidden');
        }
        $host = strtolower(rtrim((string)($parts['host'] ?? ''), '.'));
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }
        if ($host === '' || (!filter_var($host, FILTER_VALIDATE_IP)
            && !filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME))) {
            throw new WebhookPolicyViolation('invalid_host');
        }
        $port = isset($parts['port']) ? (int)$parts['port'] : 443;
        if ($port !== 443) {
            throw new WebhookPolicyViolation('unsafe_port');
        }
        $displayHost = str_contains($host, ':') ? '[' . $host . ']' : $host;
        $path = (string)($parts['path'] ?? '');
        $canonical = 'https://' . $displayHost . ($path === '' ? '/' : $path)
            . (isset($parts['query']) ? '?' . $parts['query'] : '');
        return ['canonical' => $canonical, 'host' => $host, 'port' => 443];
    }

    private static function assertBearerToken(string $token): void
    {
        if ($token !== '' && (strlen($token) > 4096
            || preg_match('/^[A-Za-z0-9._~+\/-]+=*$/D', $token) !== 1)) {
            throw new WebhookPolicyViolation('invalid_bearer_token');
        }
    }

    /** @return list<string> */
    private function validatedAddresses(string $host): array
    {
        $answers = $this->resolver->resolve($host);
        $normalized = [];
        $blocked = false;
        $allowed = false;
        foreach ($answers as $answer) {
            if (!is_string($answer) || !filter_var($answer, FILTER_VALIDATE_IP)) {
                throw new WebhookPolicyViolation('invalid_dns_answer');
            }
            $packed = @inet_pton($answer);
            $address = is_string($packed) ? @inet_ntop($packed) : false;
            if (!is_string($address)) {
                throw new WebhookPolicyViolation('invalid_dns_answer');
            }
            $normalized[$address] = true;
            if (TargetHealthService::isGloballyRoutableIp($address)) {
                $allowed = true;
            } else {
                $blocked = true;
            }
        }
        if (!$normalized) {
            throw new RuntimeException('Webhook DNS returned no A or AAAA answers.');
        }
        if ($blocked) {
            throw new WebhookPolicyViolation($allowed ? 'mixed_dns_blocked' : 'private_address');
        }
        return array_keys($normalized);
    }

    private static function sameIp(string $left, string $right): bool
    {
        $leftPacked = @inet_pton($left);
        $rightPacked = @inet_pton($right);
        return is_string($leftPacked) && is_string($rightPacked) && hash_equals($leftPacked, $rightPacked);
    }
}
