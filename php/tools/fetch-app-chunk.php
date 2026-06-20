<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/HttpClient.php';

$app = HttpClient::request('GET', 'https://drdr.ir/_next/static/chunks/pages/_app-4f30ed9b29c59af0.js', ['Accept' => '*/*']);
$raw = (string) ($app['raw'] ?? '');
file_put_contents(__DIR__ . '/app-chunk-fresh.js', $raw);

foreach (['97801:', '$G:', '.payload', 'access_token', 'accessToken', 'function(e,t){return'] as $needle) {
    $pos = strpos($raw, $needle);
    echo "=== {$needle} ===\n";
    if ($pos === false) {
        echo "not found\n\n";
        continue;
    }

    $count = 0;
    while ($pos !== false && $count < 2) {
        echo substr($raw, max(0, $pos - 80), 500) . "\n---\n";
        $pos = strpos($raw, $needle, $pos + 10);
        $count++;
    }
    echo "\n";
}
