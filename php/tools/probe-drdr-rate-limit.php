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

$mobile = '09111111111';
$payload = [
    'grant_type' => 'otp',
    'client_id' => 'f60d5037-b7ac-404a-9e3a-a263fd9f8054',
    'client_secret' => 'vZHXE1Wx15g0RMVacaTN4KbKJ1mCk3jjkx3ZoKDj',
    'mobile' => $mobile,
    'otp_code' => '12345',
    'scope' => '*',
];

HttpClient::request('POST', 'https://drdr.ir/api/v3/auth/login/mobile/init', $headers, ['mobile' => $mobile], 'json');

for ($i = 0; $i < 8; $i++) {
    $response = HttpClient::request('POST', 'https://drdr.ir/api/v3/oauth/token/', $headers, $payload, 'json');
    echo $i . ': ' . $response['status'] . ' ' . $response['raw'] . PHP_EOL;
}
