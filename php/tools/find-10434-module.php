<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/HttpClient.php';

$chunks = [
    '9621-005036f6f4283924.js',
    'login-663b0321725a5472.js',
];

foreach ($chunks as $chunk) {
    $url = str_starts_with($chunk, 'login')
        ? 'https://drdr.ir/_next/static/chunks/pages/' . $chunk
        : 'https://drdr.ir/_next/static/chunks/' . $chunk;
    $response = HttpClient::request('GET', $url, ['Accept' => '*/*']);
    $raw = (string) ($response['raw'] ?? '');
    if (!str_contains($raw, '10434:')) {
        continue;
    }
    $pos = strpos($raw, '10434:');
    echo $chunk . "\n" . substr($raw, $pos, 900) . "\n\n";
}
