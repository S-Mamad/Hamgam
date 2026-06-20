<?php

declare(strict_types=1);

$raw = file_get_contents(__DIR__ . '/login-chunk.js');
if ($raw === false) {
    exit(1);
}

foreach (['verifyOtp', 'initOtp', 'phoneNumber', 'setTokenAndUserName', 'FH:', 'd:', 'E:'] as $needle) {
    $pos = stripos($raw, $needle);
    while ($pos !== false) {
        $snippet = substr($raw, max(0, $pos - 100), 400);
        if (str_contains($snippet, 'verifyOtp') || str_contains($snippet, 'setTokenAndUserName') || str_contains($snippet, 'phoneNumber')) {
            echo "=== {$needle} @ {$pos} ===\n{$snippet}\n\n---\n\n";
        }
        $pos = stripos($raw, $needle, $pos + strlen($needle));
        if ($pos > 120000) {
            break;
        }
    }
}
