<?php

declare(strict_types=1);

if (!class_exists(ZipArchive::class)) {
    throw new RuntimeException('The zip PHP extension is required.');
}

$root = dirname(__DIR__);
$output = $argv[1] ?? ($root . '/build/LinkVault.zip');
$output = str_starts_with($output, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $output) === 1
    ? $output
    : $root . DIRECTORY_SEPARATOR . $output;

$commands = [
    [PHP_BINARY, $root . '/bin/build-assets.php'],
    [PHP_BINARY, $root . '/bin/check-migrations.php'],
];
foreach ($commands as $command) {
    $parts = array_map('escapeshellarg', $command);
    passthru(implode(' ', $parts), $exitCode);
    if ($exitCode !== 0) {
        throw new RuntimeException('Release prerequisite failed: ' . basename($command[1]));
    }
}

$includeDirectories = ['app', 'bin', 'browser-extension', 'deploy', 'docs', 'lib', 'migrations', 'public', 'templates'];
$includeFiles = ['BAOTA_DEPLOYMENT.md', 'README.md', 'composer.json', 'composer.lock', 'config.php'];
$entries = [];
foreach ($includeFiles as $relativePath) {
    if (is_file($root . '/' . $relativePath)) {
        $entries[$relativePath] = $root . '/' . $relativePath;
    }
}
foreach ($includeDirectories as $directory) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $absolutePath = $file->getPathname();
        $relativePath = str_replace('\\', '/', substr($absolutePath, strlen($root) + 1));
        $entries[$relativePath] = $absolutePath;
    }
}
ksort($entries, SORT_STRING);

$outputDirectory = dirname($output);
if (!is_dir($outputDirectory) && !mkdir($outputDirectory, 0775, true) && !is_dir($outputDirectory)) {
    throw new RuntimeException('Cannot create release output directory.');
}
$temporary = $output . '.tmp-' . getmypid();
$zip = new ZipArchive();
if ($zip->open($temporary, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    throw new RuntimeException('Cannot create release ZIP.');
}
foreach ($entries as $relativePath => $absolutePath) {
    if (!$zip->addFile($absolutePath, 'LinkVault/' . $relativePath)) {
        throw new RuntimeException('Cannot add release file: ' . $relativePath);
    }
    $zip->setMtimeName('LinkVault/' . $relativePath, 946684800);
}
if (!$zip->close()) {
    throw new RuntimeException('Cannot finalize release ZIP.');
}
if (is_file($output) && !unlink($output)) {
    throw new RuntimeException('Cannot replace existing release ZIP.');
}
if (!rename($temporary, $output)) {
    @unlink($temporary);
    throw new RuntimeException('Cannot publish release ZIP.');
}

fwrite(STDOUT, 'Created ' . $output . ' with ' . count($entries) . ' files.' . PHP_EOL);
