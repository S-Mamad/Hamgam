<?php

declare(strict_types=1);

$raw = file_get_contents(__DIR__ . '/login-chunk-fresh.js');
if ($raw === false) {
    exit(1);
}

// Find login form component usage of verify mutation N or FH
foreach (['setTokenAndUserName', 'setToken(', 'useMutation', 'phoneNumber', 'verificationCode', 'otpCode', 'submitOtp', 'handleSubmit', 'onSubmit'] as $needle) {
    $pos = 0;
    $count = 0;
    while (($pos = strpos($raw, $needle, $pos)) !== false && $count < 6) {
        $snippet = substr($raw, max(0, $pos - 60), 320);
        if (
            str_contains($snippet, 'token')
            || str_contains($snippet, 'otp')
            || str_contains($snippet, 'FH')
            || str_contains($snippet, 'phone')
            || str_contains($snippet, 'verify')
            || str_contains($snippet, 'login')
        ) {
            echo "=== {$needle} @ {$pos} ===\n{$snippet}\n\n";
            $count++;
        }
        $pos += strlen($needle);
    }
}
