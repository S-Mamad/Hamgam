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

$payload = static fn (string $mobile, string $code): array => [
    'grant_type' => 'otp',
    'client_id' => 'f60d5037-b7ac-404a-9e3a-a263fd9f8054',
    'client_secret' => 'vZHXE1Wx15g0RMVacaTN4KbKJ1mCk3jjkx3ZoKDj',
    'mobile' => $mobile,
    'otp_code' => $code,
    'scope' => '*',
];

$jar = __DIR__ . '/../storage/drdr_probe_cookie.txt';
@unlink($jar);

HttpClient::request('POST', 'https://drdr.ir/api/v3/auth/login/mobile/init', $headers, ['mobile' => '09111111111'], 'json', $jar);
$r1 = HttpClient::request('POST', 'https://drdr.ir/api/v3/oauth/token/', $headers, $payload('09122222222', '12345'), 'json', $jar);
echo 'mismatch mobile: ' . $r1['status'] . ' ' . $r1['raw'] . PHP_EOL;

$r2 = HttpClient::request('POST', 'https://drdr.ir/api/v3/oauth/token/', $headers, $payload('09111111111', '12345'), 'json', $jar);
echo 'matching mobile wrong code: ' . $r2['status'] . ' ' . $r2['raw'] . PHP_EOL;

// verify without init at all
@unlink($jar);
$r3 = HttpClient::request('POST', 'https://drdr.ir/api/v3/oauth/token/', $headers, $payload('09111111111', '12345'), 'json', $jar);
echo 'no init: ' . $r3['status'] . ' ' . $r3['raw'] . PHP_EOL;

// init then wait - can't wait long in script
