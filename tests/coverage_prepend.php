<?php

declare(strict_types=1);

if (!extension_loaded('xdebug') || !function_exists('xdebug_start_code_coverage')) {
    fwrite(STDERR, "Xdebug coverage is required.\n");
    exit(1);
}

$coverageOutput = getenv('LINKVAULT_COVERAGE_OUTPUT');
if (!is_string($coverageOutput) || $coverageOutput === '') {
    fwrite(STDERR, "LINKVAULT_COVERAGE_OUTPUT is required.\n");
    exit(1);
}

$root = dirname(__DIR__);
xdebug_set_filter(XDEBUG_FILTER_CODE_COVERAGE, XDEBUG_PATH_INCLUDE, [
    $root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR,
    $root . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR,
    $root . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR,
]);
xdebug_start_code_coverage(XDEBUG_CC_UNUSED | XDEBUG_CC_DEAD_CODE);

register_shutdown_function(static function () use ($coverageOutput): void {
    $coverage = xdebug_get_code_coverage();
    $handle = @fopen($coverageOutput, 'c+b');
    if (!is_resource($handle) || !flock($handle, LOCK_EX)) {
        return;
    }
    try {
        $existingJson = stream_get_contents($handle);
        $existing = is_string($existingJson) && $existingJson !== '' ? json_decode($existingJson, true) : [];
        if (!is_array($existing)) {
            $existing = [];
        }
        foreach ($coverage as $file => $lines) {
            if (!is_array($lines)) {
                continue;
            }
            foreach ($lines as $line => $state) {
                $previous = $existing[$file][(string)$line] ?? null;
                $existing[$file][(string)$line] = $state === 1 || $previous === 1 ? 1 : (int)$state;
            }
        }
        $json = json_encode($existing, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, $json);
        fflush($handle);
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
});
