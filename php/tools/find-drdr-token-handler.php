<?php

declare(strict_types=1);

$raw = file_get_contents(__DIR__ . '/login-chunk.js');
if ($raw === false) {
    exit(1);
}

$needles = [
    'setTokenAndUserName(',
    'setToken(',
    '.token',
    'FH(',
    'mutationFn:async e=>(0,c.FH)',
    'onSuccess:(',
    'phoneNumber',
    'username',
];

foreach ($needles as $needle) {
    $pos = 0;
    $count = 0;
    while (($pos = strpos($raw, $needle, $pos)) !== false && $count < 8) {
        $snippet = substr($raw, max(0, $pos - 100), 600);
        if (
            str_contains($snippet, 'FH')
            || str_contains($snippet, 'setToken')
            || str_contains($snippet, 'otp')
            || str_contains($snippet, 'phoneNumber')
            || str_contains($snippet, 'login')
        ) {
            echo "=== {$needle} @ {$pos} ===\n{$snippet}\n\n";
            $count++;
        }
        $pos += max(1, strlen($needle));
    }
}
