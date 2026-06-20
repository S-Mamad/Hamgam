<?php

declare(strict_types=1);

$raw = file_get_contents(__DIR__ . '/login-chunk-fresh.js');
if ($raw === false) {
    $raw = file_get_contents(__DIR__ . '/login-chunk.js');
}

foreach (['verifyOtp', 'setTokenAndUserName', 'FH:', 'phoneNumber', 'oauth/token', 'N=e=>(0,l.n)', 'mutationFn:async e=>(0,c.FH)'] as $needle) {
    $pos = 0;
    $count = 0;
    while (($pos = strpos($raw, $needle, $pos)) !== false && $count < 5) {
        echo "=== {$needle} @ {$pos} ===\n";
        echo substr($raw, max(0, $pos - 120), 500) . "\n\n";
        $pos += strlen($needle);
        $count++;
    }
}
