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

$mobile = '09351925900';
$jar = __DIR__ . '/../storage/drdr_probe_retry.txt';
@unlink($jar);

HttpClient::request('POST', 'https://drdr.ir/api/v3/auth/login/mobile/init', $headers, ['mobile' => $mobile], 'json', $jar);

$payload = static fn (string $code): array => [
    'grant_type' => 'otp',
    'client_id' => 'f60d5037-b7ac-404a-9e3a-a263fd9f8054',
    'client_secret' => 'vZHXE1Wx15g0RMVacaTN4KbKJ1mCk3jjkx3ZoKDj',
    'mobile' => $mobile,
    'otp_code' => $code,
    'scope' => '*',
];

foreach (['111111', '111111', '222222'] as $i => $code) {
    $v = HttpClient::request('POST', 'https://drdr.ir/api/v3/oauth/token/', $headers, $payload($code), 'json', $jar);
    echo "attempt {$i} code {$code}: {$v['status']} {$v['raw']}\n";
}

// verify without init (stale session)
@unlink($jar);
$v = HttpClient::request('POST', 'https://drdr.ir/api/v3/oauth/token/', $headers, $payload('111111'), 'json');
echo "no init: {$v['status']} {$v['raw']}\n";
