<?php

declare(strict_types=1);

$raw = file_get_contents(__DIR__ . '/login-chunk.js');
if ($raw === false) {
    exit(1);
}

foreach (['FH', 'verifyOtp', 'setTokenAndUserName', 'phoneNumber', 'otp', 'onSuccess'] as $needle) {
    $pos = 0;
    $count = 0;
    while (($pos = stripos($raw, $needle, $pos)) !== false && $count < 15) {
        $snippet = substr($raw, max(0, $pos - 150), 500);
        if (stripos($snippet, 'otp') !== false || stripos($snippet, 'FH') !== false || stripos($snippet, 'setToken') !== false) {
            echo "=== {$needle} @ {$pos} ===\n{$snippet}\n\n";
            $count++;
        }
        $pos += strlen($needle);
    }
}
