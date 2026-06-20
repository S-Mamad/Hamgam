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

$clientId = 'f60d5037-b7ac-404a-9e3a-a263fd9f8054';
$clientSecret = 'vZHXE1Wx15g0RMVacaTN4KbKJ1mCk3jjkx3ZoKDj';
$mobile = '09000000001';
$code = '00000';
$jar = __DIR__ . '/../storage/drdr_probe_cookie.txt';

$urls = [
    'https://drdr.ir/api/v3/oauth/token',
    'https://drdr.ir/api/v3/oauth/token/',
    'https://drdr.ir/oauth/token',
    'https://drdr.ir/oauth/token/',
];

$payload = [
    'grant_type' => 'otp',
    'client_id' => $clientId,
    'client_secret' => $clientSecret,
    'mobile' => $mobile,
    'otp_code' => $code,
    'scope' => '*',
];

foreach ($urls as $url) {
    $response = HttpClient::request('POST', $url, $headers, $payload, 'json', $jar);
    echo $url . ' -> ' . $response['status'] . ' :: ' . substr((string) $response['raw'], 0, 220) . PHP_EOL;
}
