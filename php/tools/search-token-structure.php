<?php

declare(strict_types=1);

$chunks = glob(__DIR__ . '/*.js') ?: [];
$needles = ['oauth/token', 'setTokenAndUserName', 'setToken(', '.token', 'refresh_token', 'expires_in'];

foreach ($chunks as $file) {
    $raw = file_get_contents($file);
    if ($raw === false) {
        continue;
    }
    foreach ($needles as $needle) {
        if (!str_contains($raw, $needle)) {
            continue;
        }
        $pos = 0;
        $count = 0;
        while (($pos = strpos($raw, $needle, $pos)) !== false && $count < 2) {
            if ($needle === 'setToken(' || $needle === 'setTokenAndUserName') {
                echo basename($file) . " {$needle} @ {$pos}\n";
                echo substr($raw, max(0, $pos - 100), 350) . "\n---\n";
            }
            $pos += strlen($needle);
            $count++;
        }
    }
}
