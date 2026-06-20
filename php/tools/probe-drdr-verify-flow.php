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

$mobile = '09000000001';
$code = '12345';
$jar = __DIR__ . '/../storage/drdr_probe_cookie.txt';
@unlink($jar);

$payload = [
    'grant_type' => 'otp',
    'client_id' => 'f60d5037-b7ac-404a-9e3a-a263fd9f8054',
    'client_secret' => 'vZHXE1Wx15g0RMVacaTN4KbKJ1mCk3jjkx3ZoKDj',
    'mobile' => $mobile,
    'otp_code' => $code,
    'scope' => '*',
];

$r1 = HttpClient::request('POST', 'https://drdr.ir/api/v3/oauth/token/', $headers, $payload, 'json', $jar);
echo 'without init: ' . $r1['status'] . ' ' . $r1['raw'] . PHP_EOL;

HttpClient::request('POST', 'https://drdr.ir/api/v3/auth/login/mobile/init', $headers, ['mobile' => $mobile], 'json', $jar);
$r2 = HttpClient::request('POST', 'https://drdr.ir/api/v3/oauth/token/', $headers, $payload, 'json', $jar);
echo 'with init: ' . $r2['status'] . ' ' . $r2['raw'] . PHP_EOL;

$init = HttpClient::request('POST', 'https://drdr.ir/api/v3/auth/login/mobile/init', $headers, ['mobile' => $mobile], 'json', $jar);
echo 'init body: ' . ($init['raw'] ?? '') . PHP_EOL;
