<?php

declare(strict_types=1);

final class BrowserExtensionPrivacyController
{
    /** @param array<string, mixed> $config */
    public static function dispatch(array $config): never
    {
        $contact = trim((string)($config['browser_extension_privacy_contact'] ?? ''));
        require dirname(__DIR__, 2) . '/templates/browser_extension_privacy.php';
        exit;
    }
}
