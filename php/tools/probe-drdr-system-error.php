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

$base = [
    'grant_type' => 'otp',
    'client_id' => 'f60d5037-b7ac-404a-9e3a-a263fd9f8054',
    'client_secret' => 'vZHXE1Wx15g0RMVacaTN4KbKJ1mCk3jjkx3ZoKDj',
    'scope' => '*',
];

$cases = [
    'no_init' => array_merge($base, ['mobile' => '09111111111', 'otp_code' => '123456']),
    'after_init' => null,
    'short_code' => null,
    'long_wait' => null,
];

HttpClient::request('POST', 'https://drdr.ir/api/v3/auth/login/mobile/init', $headers, ['mobile' => '09111111111'], 'json');
$cases['after_init'] = array_merge($base, ['mobile' => '09111111111', 'otp_code' => '123456']);
$cases['short_code'] = array_merge($base, ['mobile' => '09111111111', 'otp_code' => '12']);

foreach ($cases as $name => $payload) {
    if ($payload === null) {
        continue;
    }
    $response = HttpClient::request('POST', 'https://drdr.ir/api/v3/oauth/token/', $headers, $payload, 'json');
    echo $name . ': HTTP ' . $response['status'] . ' => ' . $response['raw'] . PHP_EOL;
}
