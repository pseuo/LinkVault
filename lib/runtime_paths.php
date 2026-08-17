<?php

declare(strict_types=1);

function linkvault_normalized_path(string $path): string
{
    $path = str_replace('\\', '/', trim($path));
    $path = preg_replace('#/+#', '/', $path) ?? $path;
    return rtrim($path, '/');
}

function linkvault_path_is_within(string $path, string $root): bool
{
    $path = linkvault_normalized_path($path);
    $root = linkvault_normalized_path($root);
    if ($path === '' || $root === '') {
        return false;
    }
    $caseInsensitive = DIRECTORY_SEPARATOR === '\\';
    if ($caseInsensitive) {
        $path = strtolower($path);
        $root = strtolower($root);
    }
    return $path === $root || str_starts_with($path, $root . '/');
}
