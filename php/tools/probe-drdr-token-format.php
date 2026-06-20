<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/HttpClient.php';

$headers = [
    'Accept' => 'application/json',
    'client-id' => 'f60d5037-b7ac-404a-9e3a-a263fd9f8054',
    'Origin' => 'https://drdr.ir',
    'Referer' => 'https://drdr.ir/login/?f=true',
];

$mobile = '09111111111';
$code = '12345';

HttpClient::request(
    'POST',
    'https://drdr.ir/api/v3/auth/login/mobile/init',
    array_merge($headers, ['Content-Type' => 'application/json']),
    ['mobile' => $mobile],
    'json'
);

$payload = [
    'grant_type' => 'otp',
    'client_id' => 'f60d5037-b7ac-404a-9e3a-a263fd9f8054',
    'client_secret' => 'vZHXE1Wx15g0RMVacaTN4KbKJ1mCk3jjkx3ZoKDj',
    'mobile' => $mobile,
    'otp_code' => $code,
    'scope' => '*',
];

$json = HttpClient::request(
    'POST',
    'https://drdr.ir/api/v3/oauth/token/',
    array_merge($headers, ['Content-Type' => 'application/json']),
    $payload,
    'json'
);

$form = HttpClient::request(
    'POST',
    'https://drdr.ir/api/v3/oauth/token/',
    ['Content-Type' => 'application/x-www-form-urlencoded', 'Accept' => 'application/json', 'client-id' => 'f60d5037-b7ac-404a-9e3a-a263fd9f8054', 'Origin' => 'https://drdr.ir', 'Referer' => 'https://drdr.ir/login/?f=true'],
    $payload,
    'form'
);

echo 'json: ' . $json['status'] . ' ' . $json['raw'] . PHP_EOL;
echo 'form: ' . $form['status'] . ' ' . $form['raw'] . PHP_EOL;
