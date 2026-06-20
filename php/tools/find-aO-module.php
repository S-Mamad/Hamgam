<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/HttpClient.php';

$html = HttpClient::request('GET', 'https://drdr.ir/login/?f=true', ['Accept' => 'text/html']);
preg_match_all('#/_next/static/chunks/[^"\']+\.js#', (string) $html['raw'], $matches);
$paths = array_unique($matches[0] ?? []);

foreach ($paths as $path) {
    $response = HttpClient::request('GET', 'https://drdr.ir' . $path, ['Accept' => '*/*']);
    $raw = (string) ($response['raw'] ?? '');
    if (!str_contains($raw, '60043:')) {
        continue;
    }
    $pos = strpos($raw, '60043:');
    echo basename($path) . "\n";
    echo substr($raw, $pos, 1200) . "\n\n";
}
