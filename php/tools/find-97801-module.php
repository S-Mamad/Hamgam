<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/HttpClient.php';

$html = HttpClient::request('GET', 'https://drdr.ir/login/?f=true', ['Accept' => 'text/html']);
preg_match_all('#/_next/static/chunks/[^"\']+\.js#', (string) $html['raw'], $matches);
foreach (array_unique($matches[0] ?? []) as $path) {
    $response = HttpClient::request('GET', 'https://drdr.ir' . $path, ['Accept' => '*/*']);
    $raw = (string) ($response['raw'] ?? '');
    if (!str_contains($raw, '97801:')) {
        continue;
    }
    $pos = strpos($raw, '97801:');
    echo basename($path) . "\n" . substr($raw, $pos, 2500) . "\n\n";
    break;
}
