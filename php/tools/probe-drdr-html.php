<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/HttpClient.php';

$html = HttpClient::request('GET', 'https://drdr.ir/login/?f=true', [
    'Accept' => 'text/html',
    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
]);

echo 'status=' . $html['status'] . ' len=' . strlen((string) $html['raw']) . PHP_EOL;

if (!is_string($html['raw'])) {
    exit(1);
}

preg_match_all('#(?:https://drdr\.ir)?(/[^"\']+\.(?:js|json))#', $html['raw'], $matches);
$paths = array_unique($matches[1] ?? []);
echo 'assets=' . count($paths) . PHP_EOL;

foreach ($paths as $path) {
    if (!str_contains($path, '_next') && !str_contains($path, 'chunk')) {
        continue;
    }
    echo $path . PHP_EOL;
}

preg_match_all('#/api/v3/[a-zA-Z0-9/_-]+#', $html['raw'], $apiMatches);
$apis = array_unique($apiMatches[0] ?? []);
echo 'api refs in html=' . count($apis) . PHP_EOL;
foreach ($apis as $api) {
    echo $api . PHP_EOL;
}
