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

$mobile = $argv[1] ?? '09351925900';
$code = $argv[2] ?? '000000';
$jar = __DIR__ . '/../storage/drdr_probe_live_test.txt';
@unlink($jar);

echo "=== init #1 ===\n";
$r1 = HttpClient::request('POST', 'https://drdr.ir/api/v3/auth/login/mobile/init', $headers, ['mobile' => $mobile], 'json', $jar);
echo $r1['status'] . ' ' . $r1['raw'] . "\n\n";

echo "=== init #2 (immediate) ===\n";
$r2 = HttpClient::request('POST', 'https://drdr.ir/api/v3/auth/login/mobile/init', $headers, ['mobile' => $mobile], 'json', $jar);
echo $r2['status'] . ' ' . $r2['raw'] . "\n\n";

$payload = [
    'grant_type' => 'otp',
    'client_id' => 'f60d5037-b7ac-404a-9e3a-a263fd9f8054',
    'client_secret' => 'vZHXE1Wx15g0RMVacaTN4KbKJ1mCk3jjkx3ZoKDj',
    'mobile' => $mobile,
    'otp_code' => $code,
    'scope' => '*',
];

echo "=== verify after double init ===\n";
$v = HttpClient::request('POST', 'https://drdr.ir/api/v3/oauth/token/', $headers, $payload, 'json', $jar);
echo $v['status'] . ' ' . $v['raw'] . "\n";
