<?php

declare(strict_types=1);

if (!class_exists(ZipArchive::class)) {
    throw new RuntimeException('The zip PHP extension is required.');
}

$archive = $argv[1] ?? dirname(__DIR__) . '/build/LinkVault.zip';
$zip = new ZipArchive();
if ($zip->open($archive) !== true) {
    throw new RuntimeException('Cannot open release ZIP: ' . $archive);
}

$errors = [];
$required = ['LinkVault/config.php', 'LinkVault/public/index.php', 'LinkVault/public/assets/manifest.json'];
$seen = [];
$forbiddenPaths = '#(?:^|/)(?:data|backups|restore-drill|tests|test-results|playwright-report|node_modules|vendor|\.git|\.github|\.phpstan-cache)(?:/|$)#i';
$sensitiveNames = '#(?:^|/)(?:\.env(?:\..*)?|id_(?:rsa|ed25519)|[^/]*\.(?:sqlite(?:-(?:wal|shm))?|db|log|pem|key|p12|pfx))$#i';

for ($index = 0; $index < $zip->numFiles; $index++) {
    $name = (string)$zip->getNameIndex($index);
    $seen[$name] = true;
    if ($name === '' || str_contains($name, '\\') || str_starts_with($name, '/')
        || preg_match('#(^|/)\.\.(?:/|$)#', $name) === 1) {
        $errors[] = 'Unsafe archive path: ' . $name;
        continue;
    }
    if (preg_match($forbiddenPaths, $name) === 1 || preg_match($sensitiveNames, $name) === 1) {
        $errors[] = 'Forbidden release entry: ' . $name;
        continue;
    }
    $contents = $zip->getFromIndex($index);
    if (is_string($contents) && preg_match('/-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/', $contents) === 1) {
        $errors[] = 'Private key material found in: ' . $name;
    }
}
$zip->close();

foreach ($required as $name) {
    if (!isset($seen[$name])) {
        $errors[] = 'Required release entry is missing: ' . $name;
    }
}
if ($errors !== []) {
    fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
    exit(1);
}

fwrite(STDOUT, 'Release scan passed for ' . count($seen) . ' entries.' . PHP_EOL);
