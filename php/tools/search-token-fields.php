<?php

declare(strict_types=1);

$raw = file_get_contents(__DIR__ . '/login-chunk.js');
if ($raw === false) {
    exit(1);
}

foreach (['setTokenAndUserName', 'setToken', 'access_token', 'accessToken', 'refresh_token', 'expires_in', 'token_type'] as $needle) {
    $pos = 0;
    $count = 0;
    while (($pos = strpos($raw, $needle, $pos)) !== false && $count < 4) {
        echo "=== {$needle} @ {$pos} ===\n";
        echo substr($raw, max(0, $pos - 80), 260) . "\n\n";
        $pos += strlen($needle);
        $count++;
    }
}
