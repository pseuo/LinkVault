<?php

declare(strict_types=1);

final class AdminSecurityService
{
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    private const RECOVERY_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    private const ENCRYPTION_AAD = 'linkvault-admin-totp:v1';

    public function __construct(private readonly PDO $pdo, private readonly array $config)
    {
    }

    public function isAvailable(): bool
    {
        $key = (string)($this->config['security_key'] ?? '');
        return strlen($key) >= 32
            && function_exists('openssl_encrypt')
            && function_exists('openssl_decrypt')
            && in_array('aes-256-gcm', openssl_get_cipher_methods(), true);
    }

    public function isEnabled(): bool
    {
        return (bool)$this->pdo->query('SELECT 1 FROM admin_security WHERE id = 1')->fetchColumn();
    }

    public function status(): array
    {
        $security = $this->pdo->query(<<<'SQL'
            SELECT totp_enabled_at, updated_at FROM admin_security WHERE id = 1
        SQL)->fetch();

        return [
            'available' => $this->isAvailable(),
            'enabled' => (bool)$security,
            'enabled_at' => $security ? (string)$security['totp_enabled_at'] : null,
            'updated_at' => $security ? (string)$security['updated_at'] : null,
            'recovery_codes_remaining' => $security
                ? (int)$this->pdo->query('SELECT COUNT(*) FROM admin_recovery_codes WHERE used_at IS NULL')->fetchColumn()
                : 0,
        ];
    }

    public function generateSecret(): string
    {
        if (!$this->isAvailable()) {
            throw new RuntimeException('TOTP encryption is not configured.');
        }
        if ($this->isEnabled()) {
            throw new LogicException('TOTP is already enabled.');
        }
        return $this->base32Encode(random_bytes(20));
    }

    public function provisioningUri(string $secret, string $account): string
    {
        $issuer = trim((string)($this->config['totp_issuer'] ?? 'LinkVault'));
        $issuer = $issuer === '' ? 'LinkVault' : limit_text($issuer, 60);
        $account = trim($account) === '' ? 'admin' : limit_text(trim($account), 120);
        return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account)
            . '?secret=' . rawurlencode($secret)
            . '&issuer=' . rawurlencode($issuer)
            . '&algorithm=SHA1&digits=6&period=30';
    }

    /** @return list<string> */
    public function enable(string $secret, string $code): array
    {
        if (!$this->isAvailable()) {
            throw new RuntimeException('TOTP encryption is not configured.');
        }
        $secret = strtoupper(trim($secret));
        if (preg_match('/^[A-Z2-7]{32}$/', $secret) !== 1) {
            throw new InvalidArgumentException('Invalid TOTP secret.');
        }
        $counter = $this->matchingTotpCounter($secret, $code, -1);
        if ($counter === null) {
            throw new InvalidArgumentException('Invalid TOTP code.');
        }
        $encrypted = $this->encryptSecret($secret);
        $recoveryCodes = $this->generateRecoveryCodes();

        with_sqlite_retry(function () use ($encrypted, $counter, $recoveryCodes): void {
            $now = utc_timestamp();
            $this->pdo->exec('BEGIN IMMEDIATE');
            try {
                if ($this->pdo->query('SELECT 1 FROM admin_security WHERE id = 1')->fetchColumn()) {
                    throw new LogicException('TOTP is already enabled.');
                }
                $insert = $this->pdo->prepare(<<<'SQL'
                    INSERT INTO admin_security (
                        id, totp_secret_encrypted, totp_enabled_at, totp_last_counter, updated_at
                    ) VALUES (1, :secret, :enabled_at, :last_counter, :updated_at)
                SQL);
                $insert->execute([
                    'secret' => $encrypted,
                    'enabled_at' => $now,
                    'last_counter' => $counter,
                    'updated_at' => $now,
                ]);
                $this->replaceRecoveryCodes($recoveryCodes, $now);
                $this->pdo->commit();
            } catch (Throwable $exception) {
                $this->rollback();
                throw $exception;
            }
        });

        return $recoveryCodes;
    }

    public function verifyLogin(string $credential): ?string
    {
        $credential = trim($credential);
        if ($credential === '') {
            return null;
        }

        return with_sqlite_retry(function () use ($credential): ?string {
            $this->pdo->exec('BEGIN IMMEDIATE');
            try {
                $security = $this->pdo->query(<<<'SQL'
                    SELECT totp_secret_encrypted, totp_last_counter
                    FROM admin_security WHERE id = 1
                SQL)->fetch();
                if (!$security) {
                    $this->pdo->commit();
                    return null;
                }

                $recoveryCode = $this->normalizeRecoveryCode($credential);
                if ($recoveryCode !== null) {
                    $consume = $this->pdo->prepare(<<<'SQL'
                        UPDATE admin_recovery_codes SET used_at = :used_at
                        WHERE code_hash = :code_hash AND used_at IS NULL
                    SQL);
                    $consume->execute([
                        'used_at' => utc_timestamp(),
                        'code_hash' => hash('sha256', $recoveryCode),
                    ]);
                    if ($consume->rowCount() === 1) {
                        $this->pdo->commit();
                        return 'recovery';
                    }
                    $this->pdo->commit();
                    return null;
                }

                $secret = $this->decryptSecret((string)$security['totp_secret_encrypted']);
                $counter = $this->matchingTotpCounter($secret, $credential, (int)$security['totp_last_counter']);
                if ($counter === null) {
                    $this->pdo->commit();
                    return null;
                }
                $update = $this->pdo->prepare(<<<'SQL'
                    UPDATE admin_security
                    SET totp_last_counter = :counter, updated_at = :updated_at
                    WHERE id = 1 AND totp_last_counter < :counter
                SQL);
                $update->execute([
                    'counter' => $counter,
                    'updated_at' => utc_timestamp(),
                ]);
                if ($update->rowCount() !== 1) {
                    $this->pdo->rollBack();
                    return null;
                }
                $this->pdo->commit();
                return 'totp';
            } catch (Throwable $exception) {
                $this->rollback();
                throw $exception;
            }
        });
    }

    public function disable(string $credential): bool
    {
        if ($this->verifyLogin($credential) === null) {
            return false;
        }
        return (bool)with_sqlite_retry(function (): bool {
            $this->pdo->exec('BEGIN IMMEDIATE');
            try {
                $this->pdo->exec('DELETE FROM admin_recovery_codes');
                $deleted = $this->pdo->exec('DELETE FROM admin_security WHERE id = 1') === 1;
                $this->pdo->commit();
                return $deleted;
            } catch (Throwable $exception) {
                $this->rollback();
                throw $exception;
            }
        });
    }

    /** @return list<string>|null */
    public function regenerateRecoveryCodes(string $credential): ?array
    {
        if ($this->verifyLogin($credential) === null) {
            return null;
        }
        $codes = $this->generateRecoveryCodes();
        with_sqlite_retry(function () use ($codes): void {
            $this->pdo->exec('BEGIN IMMEDIATE');
            try {
                if (!$this->pdo->query('SELECT 1 FROM admin_security WHERE id = 1')->fetchColumn()) {
                    throw new LogicException('TOTP is not enabled.');
                }
                $this->replaceRecoveryCodes($codes, utc_timestamp());
                $this->pdo->commit();
            } catch (Throwable $exception) {
                $this->rollback();
                throw $exception;
            }
        });
        return $codes;
    }

    /** @param list<string> $codes */
    private function replaceRecoveryCodes(array $codes, string $now): void
    {
        $this->pdo->exec('DELETE FROM admin_recovery_codes');
        $insert = $this->pdo->prepare(<<<'SQL'
            INSERT INTO admin_recovery_codes (code_hash, created_at)
            VALUES (:code_hash, :created_at)
        SQL);
        foreach ($codes as $code) {
            $normalized = $this->normalizeRecoveryCode($code);
            if ($normalized === null) {
                throw new RuntimeException('Cannot normalize a generated recovery code.');
            }
            $insert->execute([
                'code_hash' => hash('sha256', $normalized),
                'created_at' => $now,
            ]);
        }
    }

    /** @return list<string> */
    private function generateRecoveryCodes(): array
    {
        $codes = [];
        while (count($codes) < 10) {
            $raw = '';
            for ($index = 0; $index < 12; $index++) {
                $raw .= self::RECOVERY_ALPHABET[random_int(0, strlen(self::RECOVERY_ALPHABET) - 1)];
            }
            $formatted = implode('-', str_split($raw, 4));
            $codes[$formatted] = true;
        }
        return array_keys($codes);
    }

    private function normalizeRecoveryCode(string $code): ?string
    {
        $normalized = strtoupper((string)preg_replace('/[\s-]+/', '', trim($code)));
        return preg_match('/^[' . self::RECOVERY_ALPHABET . ']{12}$/', $normalized) === 1
            ? $normalized : null;
    }

    private function matchingTotpCounter(string $secret, string $code, int $minimumCounter): ?int
    {
        $code = preg_replace('/\s+/', '', trim($code));
        if (!is_string($code) || preg_match('/^\d{6}$/', $code) !== 1) {
            return null;
        }
        $binarySecret = $this->base32Decode($secret);
        $currentCounter = intdiv(time(), 30);
        foreach ([-1, 0, 1] as $offset) {
            $counter = $currentCounter + $offset;
            if ($counter <= $minimumCounter) {
                continue;
            }
            $counterBytes = pack('N2', intdiv($counter, 4294967296), $counter % 4294967296);
            $digest = hash_hmac('sha1', $counterBytes, $binarySecret, true);
            $position = ord($digest[19]) & 0x0f;
            $value = unpack('N', substr($digest, $position, 4));
            $otp = str_pad((string)(((int)($value[1] ?? 0) & 0x7fffffff) % 1000000), 6, '0', STR_PAD_LEFT);
            if (hash_equals($otp, $code)) {
                return $counter;
            }
        }
        return null;
    }

    private function encryptSecret(string $secret): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $secret,
            'aes-256-gcm',
            $this->encryptionKey(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            self::ENCRYPTION_AAD,
            16
        );
        if (!is_string($ciphertext) || strlen($tag) !== 16) {
            throw new RuntimeException('Cannot encrypt the TOTP secret.');
        }
        return 'v1.' . $this->base64UrlEncode($iv) . '.' . $this->base64UrlEncode($tag)
            . '.' . $this->base64UrlEncode($ciphertext);
    }

    private function decryptSecret(string $encrypted): string
    {
        if (!$this->isAvailable()) {
            throw new RuntimeException('TOTP encryption key is unavailable. Use a recovery code.');
        }
        $parts = explode('.', $encrypted);
        if (count($parts) !== 4 || $parts[0] !== 'v1') {
            throw new RuntimeException('Stored TOTP secret has an invalid format.');
        }
        $iv = $this->base64UrlDecode($parts[1]);
        $tag = $this->base64UrlDecode($parts[2]);
        $ciphertext = $this->base64UrlDecode($parts[3]);
        if (strlen($iv) !== 12 || strlen($tag) !== 16 || $ciphertext === '') {
            throw new RuntimeException('Stored TOTP secret has invalid encrypted data.');
        }
        $secret = openssl_decrypt(
            $ciphertext,
            'aes-256-gcm',
            $this->encryptionKey(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            self::ENCRYPTION_AAD
        );
        if (!is_string($secret) || preg_match('/^[A-Z2-7]{32}$/', $secret) !== 1) {
            throw new RuntimeException('Cannot decrypt the TOTP secret. Use a recovery code.');
        }
        return $secret;
    }

    private function encryptionKey(): string
    {
        return hash('sha256', (string)($this->config['security_key'] ?? ''), true);
    }

    private function base32Encode(string $binary): string
    {
        $buffer = 0;
        $bits = 0;
        $encoded = '';
        foreach (unpack('C*', $binary) ?: [] as $byte) {
            $buffer = ($buffer << 8) | $byte;
            $bits += 8;
            while ($bits >= 5) {
                $bits -= 5;
                $encoded .= self::BASE32_ALPHABET[($buffer >> $bits) & 31];
            }
            $buffer &= (1 << $bits) - 1;
        }
        if ($bits > 0) {
            $encoded .= self::BASE32_ALPHABET[($buffer << (5 - $bits)) & 31];
        }
        return $encoded;
    }

    private function base32Decode(string $encoded): string
    {
        $buffer = 0;
        $bits = 0;
        $decoded = '';
        foreach (str_split(strtoupper($encoded)) as $character) {
            $value = strpos(self::BASE32_ALPHABET, $character);
            if ($value === false) {
                throw new InvalidArgumentException('Invalid Base32 data.');
            }
            $buffer = ($buffer << 5) | $value;
            $bits += 5;
            if ($bits >= 8) {
                $bits -= 8;
                $decoded .= chr(($buffer >> $bits) & 255);
                $buffer &= (1 << $bits) - 1;
            }
        }
        return $decoded;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        $padding = (4 - strlen($value) % 4) % 4;
        $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', $padding), true);
        if (!is_string($decoded)) {
            throw new RuntimeException('Invalid encrypted data encoding.');
        }
        return $decoded;
    }

    private function rollback(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }
}
