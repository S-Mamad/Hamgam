<?php

declare(strict_types=1);

$raw = file_get_contents(__DIR__ . '/login-chunk.js');
if ($raw === false) {
    exit(1);
}

foreach (['FH(', 'initOtp', 'verifyOtp', 'setTokenAndUserName(', 'setToken(', 'errorMessage'] as $needle) {
    $pos = 0;
    $count = 0;
    while (($pos = strpos($raw, $needle, $pos)) !== false && $count < 8) {
        echo substr($raw, max(0, $pos - 120), 360) . "\n---\n";
        $pos += strlen($needle);
        $count++;
    }
}
