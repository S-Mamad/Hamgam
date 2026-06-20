<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/HttpClient.php';

$main = HttpClient::request('GET', 'https://drdr.ir/_next/static/chunks/main-7579c922262acd6c.js', ['Accept' => '*/*']);
$raw = (string) ($main['raw'] ?? '');

foreach (['97801:', '$G=function', '$G=(', '$G:()=>', 'payload:e', '.payload=', 'access_token', 'accessToken'] as $needle) {
    $pos = 0;
    $count = 0;
    while (($pos = strpos($raw, $needle, $pos)) !== false && $count < 2) {
        echo "=== {$needle} @ {$pos} ===\n";
        echo substr($raw, max(0, $pos - 100), 450) . "\n---\n";
        $pos += max(1, strlen($needle));
        $count++;
    }
}
