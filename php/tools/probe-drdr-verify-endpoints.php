<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/HttpClient.php';

$clientId = 'f60d5037-b7ac-404a-9e3a-a263fd9f8054';
$headers = [
    'Content-Type' => 'application/json',
    'Accept' => 'application/json',
    'client-id' => $clientId,
    'Origin' => 'https://drdr.ir',
    'Referer' => 'https://drdr.ir/login/?f=true',
];

$mobile = '09000000001';
$code = '00000';
$cookieJar = __DIR__ . '/../storage/drdr_probe_cookie.txt';
@mkdir(dirname($cookieJar), 0750, true);
@unlink($cookieJar);

HttpClient::request(
    'POST',
    'https://drdr.ir/api/v3/auth/login/mobile/init',
    $headers,
    ['mobile' => $mobile],
    'json',
    $cookieJar
);

$paths = [
    '/api/v3/auth/login/mobile/verify',
    '/api/v3/auth/login/mobile/login',
    '/api/v3/auth/login/mobile/confirm',
    '/api/v3/auth/login/mobile/check',
    '/api/v3/auth/login/mobile/submit',
    '/api/v3/auth/login/mobile/authenticate',
    '/api/v3/auth/login/mobile/token',
    '/api/v3/auth/login/mobile/otp',
    '/api/v3/auth/login/mobile/verify-otp',
    '/api/v3/auth/login/mobile/verifyCode',
    '/api/v3/auth/login/mobile/check-code',
    '/api/v3/auth/login/mobile/validate',
    '/api/v3/auth/login/mobile/complete',
    '/api/v3/auth/login/mobile/finish',
    '/api/v3/auth/login/mobile/sign-in',
    '/api/v3/auth/login/mobile/signin',
    '/api/v3/auth/login/mobile/auth',
    '/api/v3/auth/login/mobile/confirm-otp',
    '/api/v3/auth/login/mobile/check-otp',
    '/api/v3/auth/login/mobile/verifyOtp',
    '/api/v3/auth/login/mobile/verify_otp',
    '/api/v3/auth/login/mobile/otp/verify',
    '/api/v3/auth/login/mobile/otp/check',
    '/api/v3/auth/login/mobile/init/verify',
    '/api/v3/auth/login/mobile/init/confirm',
    '/api/v3/auth/login/mobile/init/check',
    '/api/v3/auth/login/mobile/init/login',
    '/api/v3/auth/login/mobile/init/token',
    '/api/v3/auth/login/mobile/init/otp',
    '/api/v3/auth/login/mobile/init/code',
    '/api/v3/auth/login/mobile/init/submit',
    '/api/v3/auth/login/mobile/init/authenticate',
    '/api/v3/auth/login/mobile/init/validate',
    '/api/v3/auth/login/mobile/init/complete',
    '/api/v3/auth/login/mobile/init/finish',
    '/api/v3/auth/login/mobile/init/sign-in',
    '/api/v3/auth/login/mobile/init/signin',
    '/api/v3/auth/login/mobile/init/auth',
    '/api/v3/auth/login/mobile/init/confirm-otp',
    '/api/v3/auth/login/mobile/init/check-otp',
    '/api/v3/auth/login/mobile/init/verifyOtp',
    '/api/v3/auth/login/mobile/init/verify_otp',
    '/api/v3/auth/login/mobile/init/otp/verify',
    '/api/v3/auth/login/mobile/init/otp/check',
];

$payload = ['mobile' => $mobile, 'code' => $code];

foreach ($paths as $path) {
    $url = 'https://drdr.ir' . $path;
    $response = HttpClient::request('POST', $url, $headers, $payload, 'json', $cookieJar);
    $status = $response['status'];
    if ($status === 404 || $status === 405) {
        echo "{$path} -> {$status}\n";
        continue;
    }

    $raw = substr((string) ($response['raw'] ?? ''), 0, 180);
    echo "{$path} -> {$status} :: {$raw}\n";
}
