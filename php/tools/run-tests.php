<?php

declare(strict_types=1);

/**
 * Run CLI-style test-*.php scripts in this directory.
 * Usage: php php/tools/run-tests.php
 */

$toolsDir = __DIR__;
$self = basename(__FILE__);
$files = glob($toolsDir . DIRECTORY_SEPARATOR . 'test-*.php') ?: [];
sort($files);

$passed = 0;
$failed = 0;
$skipped = 0;

echo '=== Hamgam test runner ===' . PHP_EOL;

foreach ($files as $file) {
    if (basename($file) === $self) {
        continue;
    }

    $name = basename($file);
    $source = (string) file_get_contents($file);

    $isCliRunner = str_contains($source, 'exit($failed')
        || str_contains($source, 'exit(1)')
        || str_contains($source, 'e2eAssert(')
        || str_contains($source, 'assertFix(');

    if (!$isCliRunner) {
        echo 'SKIP ' . $name . ' (HTTP endpoint — run via curl/browser)' . PHP_EOL;
        $skipped++;
        continue;
    }

    $phpIni = is_file(dirname($toolsDir, 2) . '/dev/php.ini')
        ? '-c ' . escapeshellarg(dirname($toolsDir, 2) . '/dev/php.ini') . ' '
        : '';

    $cmd = 'php ' . $phpIni . escapeshellarg($file) . ' 2>&1';
    passthru($cmd, $exitCode);

    if ($exitCode === 0) {
        echo 'PASS ' . $name . PHP_EOL;
        $passed++;
    } else {
        echo 'FAIL ' . $name . ' (exit ' . $exitCode . ')' . PHP_EOL;
        $failed++;
    }
}

echo PHP_EOL . "Results: {$passed} passed, {$failed} failed, {$skipped} skipped" . PHP_EOL;
exit($failed > 0 ? 1 : 0);
