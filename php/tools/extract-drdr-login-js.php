<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/HttpClient.php';

$response = HttpClient::request('GET', 'https://drdr.ir/_next/static/chunks/pages/login-663b0321725a5472.js', ['Accept' => '*/*']);
$raw = (string) ($response['raw'] ?? '');
file_put_contents(__DIR__ . '/login-chunk.js', $raw);

$needles = ['login/mobile', 'auth/login', 'verify', 'init', 'verificationCode', 'accessToken', 'client-id', 'mobile/init'];
foreach ($needles as $needle) {
    $pos = 0;
    while (($pos = stripos($raw, $needle, $pos)) !== false) {
        $start = max(0, $pos - 120);
        $snippet = substr($raw, $start, 260);
        echo "---- {$needle} @ {$pos} ----\n{$snippet}\n\n";
        $pos += strlen($needle);
        if ($pos > 500000) {
            break;
        }
    }
}
