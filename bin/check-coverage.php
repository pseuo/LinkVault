<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$minimum = 20.0;
$arguments = array_values(array_filter($_SERVER['argv'] ?? [], 'is_string'));
foreach (array_slice($arguments, 1) as $argument) {
    if (preg_match('/^--min=(\d+(?:\.\d+)?)$/', $argument, $matches) === 1) {
        $minimum = (float)$matches[1];
    } else {
        fwrite(STDERR, "Usage: php bin/check-coverage.php [--min=PERCENT]\n");
        exit(2);
    }
}
if (!extension_loaded('xdebug')) {
    fwrite(STDERR, "Xdebug is required to measure coverage.\n");
    exit(2);
}

$output = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'linkvault-coverage-' . bin2hex(random_bytes(8)) . '.json';
$prepend = $root . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'coverage_prepend.php';
$tests = [
    'tests/access_controls.php',
    'tests/api_token_usage.php',
    'tests/retention_batches.php',
    'tests/operations_integrity.php',
    'tests/prometheus_metrics.php',
    'tests/domains_lifecycle.php',
    'tests/workflow_extensions.php',
    'tests/smoke.php',
];

putenv('LINKVAULT_COVERAGE_OUTPUT=' . $output);
putenv('LINKVAULT_COVERAGE_PREPEND=' . $prepend);
try {
    foreach ($tests as $test) {
        $command = [
            PHP_BINARY,
            '-d',
            'xdebug.mode=coverage',
            '-d',
            'auto_prepend_file=' . $prepend,
            $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $test),
        ];
        $process = proc_open($command, [STDIN, STDOUT, STDERR], $pipes, $root, null, ['bypass_shell' => true]);
        if (!is_resource($process) || proc_close($process) !== 0) {
            fwrite(STDERR, "Coverage test failed: {$test}\n");
            exit(1);
        }
    }

    $coverage = is_file($output) ? json_decode((string)file_get_contents($output), true) : null;
    if (!is_array($coverage)) {
        fwrite(STDERR, "Coverage data was not produced.\n");
        exit(1);
    }
    $executable = 0;
    $covered = 0;
    foreach ($coverage as $lines) {
        foreach ((array)$lines as $state) {
            if ((int)$state === -2) {
                continue;
            }
            $executable++;
            if ((int)$state === 1) {
                $covered++;
            }
        }
    }
    $percent = $executable > 0 ? $covered * 100 / $executable : 0.0;
    fwrite(STDOUT, sprintf(
        "Core line coverage: %.2f%% (%d/%d), required %.2f%%.\n",
        $percent,
        $covered,
        $executable,
        $minimum
    ));
    if ($percent + 0.00001 < $minimum) {
        fwrite(STDERR, "Core coverage is below the baseline.\n");
        exit(1);
    }
} finally {
    putenv('LINKVAULT_COVERAGE_OUTPUT');
    putenv('LINKVAULT_COVERAGE_PREPEND');
    if (is_file($output)) {
        @unlink($output);
    }
}
