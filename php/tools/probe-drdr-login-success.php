<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/HttpClient.php';

$response = HttpClient::request(
    'GET',
    'https://drdr.ir/_next/static/chunks/pages/login-663b0321725a5472.js',
    ['Accept' => '*/*']
);

$raw = (string) ($response['raw'] ?? '');
file_put_contents(__DIR__ . '/login-chunk-fresh.js', $raw);

foreach (['97801:', 'code:2001', 'code:200', 'access_token', 'bearer', 'token_type', 'setTokenAndUserName(', 'onSuccess', 'FH(e)', 'mutationFn'] as $needle) {
    $pos = strpos($raw, $needle);
    echo "=== {$needle} ===\n";
    if ($pos === false) {
        echo "not found\n\n";
        continue;
    }

    $count = 0;
    while ($pos !== false && $count < 3) {
        echo substr($raw, max(0, $pos - 100), 400) . "\n---\n";
        $pos = strpos($raw, $needle, $pos + strlen($needle));
        $count++;
    }
    echo "\n";
}
