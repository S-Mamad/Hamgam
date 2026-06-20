<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/HttpClient.php';

$chunks = [
    __DIR__ . '/login-chunk.js',
];

$app = HttpClient::request('GET', 'https://drdr.ir/_next/static/chunks/pages/_app-4f30ed9b29c59af0.js', ['Accept' => '*/*']);
file_put_contents(__DIR__ . '/app-chunk.js', (string) $app['raw']);
$chunks[] = __DIR__ . '/app-chunk.js';

foreach ($chunks as $file) {
    if (!is_file($file)) {
        continue;
    }
    $raw = file_get_contents($file);
    foreach (['oauth/token', 'api/v3', 'baseURL', 'BASE_URL', 'aO=function', 'aO=(', 'otp_code'] as $needle) {
        if (stripos($raw, $needle) !== false) {
            echo basename($file) . " contains {$needle}\n";
        }
    }
}

$raw = file_get_contents(__DIR__ . '/app-chunk.js');
$pos = stripos($raw, 'oauth/token');
while ($pos !== false && $pos < 500000) {
    echo substr($raw, max(0, $pos - 100), 400) . "\n---\n";
    $pos = stripos($raw, 'oauth/token', $pos + 10);
    if ($pos === false) {
        break;
    }
}

// find aO export in module 60043 area
if (preg_match('/60043:\([^)]+\)=>[^}]+aO[^}]{0,400}/', $raw, $m)) {
    echo "module hint: " . $m[0] . "\n";
}
