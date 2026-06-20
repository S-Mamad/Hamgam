<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/HttpClient.php';

$chunks = [
    'https://drdr.ir/_next/static/chunks/pages/login-663b0321725a5472.js',
    'https://drdr.ir/_next/static/chunks/main-7579c922262acd6c.js',
    'https://drdr.ir/_next/static/chunks/pages/_app-4f30ed9b29c59af0.js',
];

$patterns = [
    'login/mobile',
    'auth/login',
    'verify',
    'init',
    'verificationCode',
    'accessToken',
    'client-id',
];

foreach ($chunks as $url) {
    $response = HttpClient::request('GET', $url, ['Accept' => '*/*']);
    $raw = (string) ($response['raw'] ?? '');
    echo '=== ' . basename($url) . ' status=' . $response['status'] . ' len=' . strlen($raw) . PHP_EOL;

    foreach ($patterns as $pattern) {
        if (stripos($raw, $pattern) === false) {
            continue;
        }
        echo '  found: ' . $pattern . PHP_EOL;
    }

    if (preg_match_all('#/api/v3/[a-zA-Z0-9/_-]{3,80}#', $raw, $matches)) {
        $apis = array_unique($matches[0]);
        sort($apis);
        foreach ($apis as $api) {
            echo '  API: ' . $api . PHP_EOL;
        }
    }

    if (preg_match_all('#https://[^"\']+drdr[^"\']+#', $raw, $urlMatches)) {
        foreach (array_unique($urlMatches[0]) as $foundUrl) {
            if (str_contains($foundUrl, 'api') || str_contains($foundUrl, 'auth') || str_contains($foundUrl, 'login')) {
                echo '  URL: ' . $foundUrl . PHP_EOL;
            }
        }
    }
}
