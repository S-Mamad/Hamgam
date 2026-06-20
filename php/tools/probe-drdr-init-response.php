<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/HttpClient.php';

$headers = [
    'Content-Type' => 'application/json',
    'Accept' => 'application/json',
    'client-id' => 'f60d5037-b7ac-404a-9e3a-a263fd9f8054',
    'Origin' => 'https://drdr.ir',
    'Referer' => 'https://drdr.ir/login/?f=true',
];

$jar = __DIR__ . '/../storage/drdr_probe_cookie.txt';
@unlink($jar);

foreach (['09111111111', '09123456789'] as $mobile) {
    $response = HttpClient::request(
        'POST',
        'https://drdr.ir/api/v3/auth/login/mobile/init',
        $headers,
        ['mobile' => $mobile],
        'json',
        $jar
    );
    echo "mobile={$mobile} status={$response['status']} body={$response['raw']}" . PHP_EOL;
    echo 'cookies:' . PHP_EOL . (is_file($jar) ? file_get_contents($jar) : '(none)') . PHP_EOL . '---' . PHP_EOL;
}
