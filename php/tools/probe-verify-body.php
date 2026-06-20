<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/HttpClient.php';

$headers = [
    'Content-Type' => 'application/json',
    'Accept' => 'application/json, text/plain, */*',
    'client-id' => 'f60d5037-b7ac-404a-9e3a-a263fd9f8054',
    'Origin' => 'https://drdr.ir',
    'Referer' => 'https://drdr.ir/',
];

$jar = __DIR__ . '/../storage/drdr_probe.txt';
@unlink($jar);

HttpClient::request('POST', 'https://drdr.ir/api/v3/auth/login/mobile/init/', $headers, ['mobile' => '09351925900'], 'json', $jar);
$verify = HttpClient::request(
    'POST',
    'https://drdr.ir/api/v3/oauth/token/',
    $headers,
    [
        'grant_type' => 'otp',
        'client_id' => 'f60d5037-b7ac-404a-9e3a-a263fd9f8054',
        'client_secret' => 'vZHXE1Wx15g0RMVacaTN4KbKJ1mCk3jjkx3ZoKDj',
        'mobile' => '09351925900',
        'otp_code' => '000000',
        'scope' => '*',
    ],
    'json',
    $jar
);

echo 'status=' . $verify['status'] . PHP_EOL;
echo 'raw=' . $verify['raw'] . PHP_EOL;

require_once __DIR__ . '/../includes/DrDrAuthService.php';

try {
    $tokens = DrDrAuthService::extractTokensFromOAuthBody($verify['body']);
    echo 'parsed=' . json_encode($tokens, JSON_UNESCAPED_UNICODE) . PHP_EOL;
} catch (Throwable $e) {
    echo 'parse_error=' . $e->getMessage() . PHP_EOL;
}
