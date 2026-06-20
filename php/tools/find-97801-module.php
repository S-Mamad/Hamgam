<?php

declare(strict_types=1);

$files = [
    __DIR__ . '/app-chunk.js',
    __DIR__ . '/login-chunk.js',
];

foreach ($files as $file) {
    if (!is_file($file)) {
        continue;
    }

    $raw = file_get_contents($file);
    $pos = strpos($raw, '97801:');
    echo "=== " . basename($file) . " ===\n";
    if ($pos === false) {
        echo "module 97801 not found\n\n";
        continue;
    }

    echo substr($raw, $pos, 2500) . "\n\n";
}
