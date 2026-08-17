<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$assetRoot = $root . '/public/assets';
$manifestPath = $assetRoot . '/manifest.json';
$logicalAssets = [
    'fonts/FiraCode-400.woff2',
    'fonts/FiraCode-500.woff2',
    'fonts/FiraCode-600.woff2',
    'fonts/NotoSansSC-400.woff2',
    'fonts/NotoSansSC-500.woff2',
    'fonts/NotoSansSC-700.woff2',
    'icon.svg',
    'qrcode.min.js',
    'theme-init.js',
    'app.js',
];

$manifest = [];
$generatedPaths = [];

foreach ($logicalAssets as $logicalPath) {
    $sourcePath = $assetRoot . '/' . $logicalPath;
    $contents = file_get_contents($sourcePath);
    if (!is_string($contents)) {
        throw new RuntimeException('Cannot read asset: ' . $logicalPath);
    }

    $fingerprintedPath = fingerprinted_asset_path($logicalPath, $contents);
    install_asset($assetRoot . '/' . $fingerprintedPath, $contents);
    $manifest['/assets/' . $logicalPath] = '/assets/' . $fingerprintedPath;
    $generatedPaths[] = '/assets/' . $fingerprintedPath;
}

foreach (['app.css', 'error.css'] as $logicalPath) {
    $sourcePath = $assetRoot . '/' . $logicalPath;
    $contents = file_get_contents($sourcePath);
    if (!is_string($contents)) {
        throw new RuntimeException('Cannot read asset: ' . $logicalPath);
    }

    $contents = preg_replace_callback(
        '#url\((["\']?)(fonts/[^)"\']+\.woff2)\1\)#',
        static function (array $matches) use ($manifest): string {
            $logicalUrl = '/assets/' . $matches[2];
            $fingerprintedUrl = $manifest[$logicalUrl] ?? null;
            if (!is_string($fingerprintedUrl)) {
                throw new RuntimeException('Missing font in asset manifest: ' . $logicalUrl);
            }
            return 'url(' . $matches[1] . substr($fingerprintedUrl, strlen('/assets/')) . $matches[1] . ')';
        },
        $contents
    );
    if (!is_string($contents)) {
        throw new RuntimeException('Cannot rewrite CSS asset: ' . $logicalPath);
    }

    $fingerprintedPath = fingerprinted_asset_path($logicalPath, $contents);
    install_asset($assetRoot . '/' . $fingerprintedPath, $contents);
    $manifest['/assets/' . $logicalPath] = '/assets/' . $fingerprintedPath;
    $generatedPaths[] = '/assets/' . $fingerprintedPath;
}

ksort($manifest);
$encodedManifest = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
$temporaryManifest = $manifestPath . '.tmp';
if (file_put_contents($temporaryManifest, $encodedManifest, LOCK_EX) === false
    || !rename($temporaryManifest, $manifestPath)) {
    @unlink($temporaryManifest);
    throw new RuntimeException('Cannot write asset manifest.');
}

foreach (glob($assetRoot . '/*.*.*') ?: [] as $candidate) {
    $relativePath = '/assets/' . basename($candidate);
    if (preg_match('/\.[0-9a-f]{12}\.(?:css|js|svg)$/D', $candidate) === 1
        && !in_array($relativePath, $generatedPaths, true)) {
        unlink($candidate);
    }
}
foreach (glob($assetRoot . '/fonts/*.*.woff2') ?: [] as $candidate) {
    $relativePath = '/assets/fonts/' . basename($candidate);
    if (preg_match('/\.[0-9a-f]{12}\.woff2$/D', $candidate) === 1
        && !in_array($relativePath, $generatedPaths, true)) {
        unlink($candidate);
    }
}

fwrite(STDOUT, 'Built ' . count($manifest) . ' fingerprinted assets.' . PHP_EOL);

function fingerprinted_asset_path(string $logicalPath, string $contents): string
{
    $extension = pathinfo($logicalPath, PATHINFO_EXTENSION);
    $base = substr($logicalPath, 0, -strlen($extension) - 1);
    return $base . '.' . substr(hash('sha256', $contents), 0, 12) . '.' . $extension;
}

function install_asset(string $destinationPath, string $contents): void
{
    $temporaryPath = $destinationPath . '.tmp-' . getmypid();
    if (file_put_contents($temporaryPath, $contents, LOCK_EX) === false) {
        throw new RuntimeException('Cannot write fingerprinted asset: ' . $destinationPath);
    }
    if (is_file($destinationPath) && !unlink($destinationPath)) {
        @unlink($temporaryPath);
        throw new RuntimeException('Cannot replace fingerprinted asset: ' . $destinationPath);
    }
    if (!rename($temporaryPath, $destinationPath)) {
        @unlink($temporaryPath);
        throw new RuntimeException('Cannot install fingerprinted asset: ' . $destinationPath);
    }
}
