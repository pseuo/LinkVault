<?php

declare(strict_types=1);

final class ShortDomainService
{
    public const THEMES = ['graphite', 'indigo', 'emerald', 'crimson'];

    public function __construct(private readonly PDO $pdo, private readonly array $config)
    {
    }

    public function create(
        string $hostname,
        string $brandName,
        string $brandTagline,
        string $theme,
        ?string $brandColor = null,
        ?string $logoUrl = null,
        ?string $faviconUrl = null,
        ?string $invalidPageTitle = null,
        ?string $invalidPageMessage = null
    ): int {
        $hostname = $this->normalizeHostname($hostname);
        $this->validateBrand($brandName, $brandTagline, $theme);
        $brandColor ??= '#18181b';
        $logoUrl ??= '';
        $faviconUrl ??= '';
        $invalidPageTitle ??= '链接不可用';
        $invalidPageMessage ??= '此链接已失效或不存在。';
        $this->validateAdvancedBrand($brandColor, $logoUrl, $faviconUrl, $invalidPageTitle, $invalidPageMessage);
        $canonicalHost = configured_base_url($this->config)['host'] ?? null;
        if ($canonicalHost !== null && hash_equals((string)$canonicalHost, $hostname)) {
            throw new InvalidArgumentException('The canonical hostname cannot be added as a short domain.');
        }
        $now = utc_timestamp();
        $statement = $this->pdo->prepare(<<<'SQL'
            INSERT INTO short_domains (
                hostname, verification_token, brand_name, brand_tagline, brand_theme,
                brand_color, logo_url, favicon_url, invalid_page_title, invalid_page_message,
                created_at, updated_at
            ) VALUES (
                :hostname, :verification_token, :brand_name, :brand_tagline, :brand_theme,
                :brand_color, :logo_url, :favicon_url, :invalid_page_title, :invalid_page_message,
                :created_at, :updated_at
            )
        SQL);
        with_sqlite_retry(fn () => $statement->execute([
            'hostname' => $hostname,
            'verification_token' => bin2hex(random_bytes(24)),
            'brand_name' => trim($brandName),
            'brand_tagline' => trim($brandTagline),
            'brand_theme' => $theme,
            'brand_color' => strtoupper($brandColor),
            'logo_url' => trim($logoUrl),
            'favicon_url' => trim($faviconUrl),
            'invalid_page_title' => trim($invalidPageTitle),
            'invalid_page_message' => trim($invalidPageMessage),
            'created_at' => $now,
            'updated_at' => $now,
        ]));
        return (int)$this->pdo->lastInsertId();
    }

    public function verify(int $id): bool
    {
        $domain = $this->find($id);
        if ($domain === null) {
            return false;
        }
        $records = dns_get_record('_linkvault-challenge.' . (string)$domain['hostname'], DNS_TXT);
        $expected = 'linkvault-verification=' . (string)$domain['verification_token'];
        $verified = false;
        foreach (is_array($records) ? $records : [] as $record) {
            $value = (string)($record['txt'] ?? '');
            if ($value !== '' && hash_equals($expected, $value)) {
                $verified = true;
                break;
            }
        }
        if (!$verified) {
            return false;
        }
        $now = utc_timestamp();
        $statement = $this->pdo->prepare(<<<'SQL'
            UPDATE short_domains
            SET verified_at = :verified_at, is_enabled = 1, updated_at = :updated_at
            WHERE id = :id AND verification_token = :verification_token
        SQL);
        with_sqlite_retry(fn () => $statement->execute([
            'verified_at' => $now,
            'updated_at' => $now,
            'id' => $id,
            'verification_token' => $domain['verification_token'],
        ]));
        return $statement->rowCount() === 1;
    }

    public function updateBrand(
        int $id,
        string $brandName,
        string $brandTagline,
        string $theme,
        ?string $brandColor = null,
        ?string $logoUrl = null,
        ?string $faviconUrl = null,
        ?string $invalidPageTitle = null,
        ?string $invalidPageMessage = null
    ): bool
    {
        $this->validateBrand($brandName, $brandTagline, $theme);
        $current = $this->find($id);
        if ($current === null) {
            return false;
        }
        $brandColor ??= (string)$current['brand_color'];
        $logoUrl ??= (string)$current['logo_url'];
        $faviconUrl ??= (string)$current['favicon_url'];
        $invalidPageTitle ??= (string)$current['invalid_page_title'];
        $invalidPageMessage ??= (string)$current['invalid_page_message'];
        $this->validateAdvancedBrand($brandColor, $logoUrl, $faviconUrl, $invalidPageTitle, $invalidPageMessage);
        $statement = $this->pdo->prepare(<<<'SQL'
            UPDATE short_domains
            SET brand_name = :brand_name, brand_tagline = :brand_tagline,
                brand_theme = :brand_theme, brand_color = :brand_color,
                logo_url = :logo_url, favicon_url = :favicon_url,
                invalid_page_title = :invalid_page_title,
                invalid_page_message = :invalid_page_message, updated_at = :updated_at
            WHERE id = :id
        SQL);
        with_sqlite_retry(fn () => $statement->execute([
            'brand_name' => trim($brandName),
            'brand_tagline' => trim($brandTagline),
            'brand_theme' => $theme,
            'brand_color' => strtoupper($brandColor),
            'logo_url' => trim($logoUrl),
            'favicon_url' => trim($faviconUrl),
            'invalid_page_title' => trim($invalidPageTitle),
            'invalid_page_message' => trim($invalidPageMessage),
            'updated_at' => utc_timestamp(),
            'id' => $id,
        ]));
        return $statement->rowCount() === 1;
    }

    public function updateAppearance(
        int $id,
        string $brandColor,
        string $logoUrl,
        string $faviconUrl,
        string $invalidPageTitle,
        string $invalidPageMessage
    ): bool {
        $this->validateAdvancedBrand(
            $brandColor,
            $logoUrl,
            $faviconUrl,
            $invalidPageTitle,
            $invalidPageMessage
        );
        $statement = $this->pdo->prepare(<<<'SQL'
            UPDATE short_domains
            SET brand_color = :brand_color, logo_url = :logo_url, favicon_url = :favicon_url,
                invalid_page_title = :invalid_page_title,
                invalid_page_message = :invalid_page_message, updated_at = :updated_at
            WHERE id = :id
        SQL);
        with_sqlite_retry(fn () => $statement->execute([
            'brand_color' => strtoupper($brandColor),
            'logo_url' => trim($logoUrl),
            'favicon_url' => trim($faviconUrl),
            'invalid_page_title' => trim($invalidPageTitle),
            'invalid_page_message' => trim($invalidPageMessage),
            'updated_at' => utc_timestamp(),
            'id' => $id,
        ]));
        return $statement->rowCount() === 1;
    }

    public function setEnabled(int $id, bool $enabled): bool
    {
        $statement = $this->pdo->prepare(<<<'SQL'
            UPDATE short_domains SET is_enabled = :enabled, updated_at = :updated_at
            WHERE id = :id AND verified_at IS NOT NULL
        SQL);
        with_sqlite_retry(fn () => $statement->execute([
            'enabled' => $enabled ? 1 : 0,
            'updated_at' => utc_timestamp(),
            'id' => $id,
        ]));
        return $statement->rowCount() === 1;
    }

    public function deleteUnused(int $id): string
    {
        return with_sqlite_retry(function () use ($id): string {
            $this->pdo->exec('BEGIN IMMEDIATE');
            try {
                $exists = $this->pdo->prepare('SELECT 1 FROM short_domains WHERE id = :id');
                $exists->execute(['id' => $id]);
                if (!$exists->fetchColumn()) {
                    $this->pdo->rollBack();
                    return 'not_found';
                }
                $delete = $this->pdo->prepare(<<<'SQL'
                    DELETE FROM short_domains
                    WHERE id = :id
                      AND NOT EXISTS (SELECT 1 FROM links WHERE short_domain_id = :id)
                SQL);
                $delete->execute(['id' => $id]);
                if ($delete->rowCount() !== 1) {
                    $this->pdo->rollBack();
                    return 'in_use';
                }
                $this->pdo->commit();
                return 'deleted';
            } catch (Throwable $exception) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $exception;
            }
        });
    }

    public function find(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM short_domains WHERE id = :id');
        $statement->execute(['id' => $id]);
        $domain = $statement->fetch();
        return $domain ?: null;
    }

    public function selectable(int $id): ?array
    {
        $statement = $this->pdo->prepare(<<<'SQL'
            SELECT * FROM short_domains
            WHERE id = :id AND verified_at IS NOT NULL AND is_enabled = 1
        SQL);
        $statement->execute(['id' => $id]);
        $domain = $statement->fetch();
        return $domain ?: null;
    }

    public function all(): array
    {
        return $this->pdo->query(<<<'SQL'
            SELECT d.*,
                   (SELECT COUNT(*) FROM links l WHERE l.short_domain_id = d.id) AS link_count
            FROM short_domains d ORDER BY d.hostname ASC
        SQL)->fetchAll();
    }

    public function enabled(): array
    {
        return $this->pdo->query(<<<'SQL'
            SELECT * FROM short_domains
            WHERE verified_at IS NOT NULL AND is_enabled = 1 ORDER BY hostname ASC
        SQL)->fetchAll();
    }

    private function normalizeHostname(string $hostname): string
    {
        $hostname = strtolower(rtrim(trim($hostname), '.'));
        if ($hostname === '' || strlen($hostname) > 253 || filter_var($hostname, FILTER_VALIDATE_IP)
            || !filter_var($hostname, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
            || !str_contains($hostname, '.')) {
            throw new InvalidArgumentException('Invalid short domain hostname.');
        }
        return $hostname;
    }

    private function validateBrand(string $brandName, string $brandTagline, string $theme): void
    {
        $brandName = trim($brandName);
        $brandTagline = trim($brandTagline);
        if ($brandName === '' || text_length($brandName) > 60 || text_length($brandTagline) > 160
            || !in_array($theme, self::THEMES, true)
            || preg_match('/[\x00-\x1F\x7F]/u', $brandName . $brandTagline) === 1) {
            throw new InvalidArgumentException('Invalid short domain branding.');
        }
    }

    private function validateAdvancedBrand(
        string $brandColor,
        string $logoUrl,
        string $faviconUrl,
        string $invalidPageTitle,
        string $invalidPageMessage
    ): void {
        if (preg_match('/^#[0-9A-Fa-f]{6}$/D', $brandColor) !== 1
            || $this->contrastRatio($brandColor, '#FFFFFF') < 3.0
            || $this->contrastRatio($brandColor, '#F7F7F5') < 3.0
            || !$this->validBrandAssetUrl($logoUrl) || !$this->validBrandAssetUrl($faviconUrl)
            || trim($invalidPageTitle) === '' || text_length(trim($invalidPageTitle)) > 80
            || trim($invalidPageMessage) === '' || text_length(trim($invalidPageMessage)) > 500
            || preg_match('/[\x00-\x1F\x7F]/u', $invalidPageTitle . $invalidPageMessage) === 1) {
            throw new InvalidArgumentException('Invalid advanced short domain branding.');
        }
    }

    private function contrastRatio(string $first, string $second): float
    {
        $luminance = static function (string $color): float {
            $channels = [
                hexdec(substr($color, 1, 2)) / 255,
                hexdec(substr($color, 3, 2)) / 255,
                hexdec(substr($color, 5, 2)) / 255,
            ];
            $channels = array_map(
                static fn (float $channel): float => $channel <= 0.04045
                    ? $channel / 12.92
                    : (($channel + 0.055) / 1.055) ** 2.4,
                $channels
            );
            return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
        };
        $firstLuminance = $luminance($first);
        $secondLuminance = $luminance($second);
        return (max($firstLuminance, $secondLuminance) + 0.05)
            / (min($firstLuminance, $secondLuminance) + 0.05);
    }

    private function validBrandAssetUrl(string $url): bool
    {
        $url = trim($url);
        if ($url === '') {
            return true;
        }
        if (strlen($url) > 2048 || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $parts = parse_url($url);
        return is_array($parts) && strtolower((string)($parts['scheme'] ?? '')) === 'https'
            && (string)($parts['host'] ?? '') !== '' && !isset($parts['user']) && !isset($parts['pass']);
    }
}
