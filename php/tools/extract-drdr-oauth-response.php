<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/HttpClient.php';

$response = HttpClient::request('GET', 'https://drdr.ir/_next/static/chunks/pages/login-663b0321725a5472.js', ['Accept' => '*/*']);
$raw = (string) ($response['raw'] ?? '');

foreach (['access_token', 'refresh_token', 'expires_in', 'payload', 'verifyOtp', '$G', '97801'] as $needle) {
    $pos = 0;
    $count = 0;
    while (($pos = stripos($raw, $needle, $pos)) !== false && $count < 3) {
        echo "=== {$needle} @ {$pos} ===\n";
        echo substr($raw, max(0, $pos - 60), 320) . "\n\n";
        $pos += strlen($needle);
        $count++;
    }
}
