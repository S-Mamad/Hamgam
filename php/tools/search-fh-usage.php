<?php

declare(strict_types=1);

$raw = file_get_contents(__DIR__ . '/login-chunk.js');
if ($raw === false) {
    exit(1);
}

$pos = 0;
while (($pos = strpos($raw, 'FH', $pos)) !== false) {
    $snippet = substr($raw, max(0, $pos - 60), 500);
    if (str_contains($snippet, 'setToken') || str_contains($snippet, 'verify') || str_contains($snippet, 'otp') || str_contains($snippet, 'phoneNumber')) {
        echo "=== @ {$pos} ===\n{$snippet}\n\n---\n\n";
    }
    $pos += 2;
    if ($pos > 300000) {
        break;
    }
}
