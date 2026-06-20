<?php

declare(strict_types=1);

$raw = file_get_contents(__DIR__ . '/login-chunk-fresh.js');
if ($raw === false) {
    exit(1);
}

foreach (['setTokenAndUserName(', 'setToken(', '.token', 'FH)(', 'useVerify', 'verifyOtp', 'otpLogin', 'loginOtp', 'onSuccess:e=>', 'onSuccess:t=>', 'onSuccess:r=>', 'onSuccess:a=>', 'onSuccess:n=>', 'onSuccess:i=>', 'onSuccess:s=>', 'onSuccess:o=>'] as $needle) {
    $pos = 0;
    $count = 0;
    while (($pos = strpos($raw, $needle, $pos)) !== false && $count < 8) {
        $snippet = substr($raw, max(0, $pos - 80), 350);
        if (str_contains($snippet, 'FH') || str_contains($snippet, 'token') || str_contains($snippet, 'otp') || str_contains($snippet, 'verify')) {
            echo "=== {$needle} @ {$pos} ===\n{$snippet}\n\n";
            $count++;
        }
        $pos += strlen($needle);
    }
}
