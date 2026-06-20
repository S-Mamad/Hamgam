<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/HttpClient.php';

$raw = file_get_contents(__DIR__ . '/login-chunk.js');
if ($raw === false) {
    exit(1);
}

$pos = stripos($raw, 'verifyOtp');
while ($pos !== false) {
    echo substr($raw, max(0, $pos - 80), 600) . "\n\n---\n\n";
    $pos = stripos($raw, 'verifyOtp', $pos + 8);
}

$pos = stripos($raw, 'grant_type');
while ($pos !== false) {
    echo substr($raw, max(0, $pos - 120), 500) . "\n\n---\n\n";
    $pos = stripos($raw, 'grant_type', $pos + 10);
    if ($pos > 20000) {
        break;
    }
}
