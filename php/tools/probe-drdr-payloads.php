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
$jar = __DIR__ . '/../storage/drdr_probe_cookie.txt';
@mkdir(dirname($jar), 0750, true);
@unlink($jar);

$mobile = '09000000001';
$code = '00000';

HttpClient::request('POST', 'https://drdr.ir/api/v3/auth/login/mobile/init', $headers, ['mobile' => $mobile], 'json', $jar);

$bodies = [
    'code' => ['mobile' => $mobile, 'code' => $code],
    'verificationCode' => ['mobile' => $mobile, 'verificationCode' => $code],
    'otp' => ['mobile' => $mobile, 'otp' => $code],
    'pin' => ['mobile' => $mobile, 'pin' => $code],
    'withAction' => ['mobile' => $mobile, 'code' => $code, 'action' => 'verify'],
    'withStep' => ['mobile' => $mobile, 'code' => $code, 'step' => 'verify'],
    'withType' => ['mobile' => $mobile, 'code' => $code, 'type' => 'verify'],
    'withMode' => ['mobile' => $mobile, 'code' => $code, 'mode' => 'verify'],
    'confirmCode' => ['mobile' => $mobile, 'confirmCode' => $code],
    'smsCode' => ['mobile' => $mobile, 'smsCode' => $code],
    'oneTimeCode' => ['mobile' => $mobile, 'oneTimeCode' => $code],
    'disposableCode' => ['mobile' => $mobile, 'disposableCode' => $code],
    'mobileOnlyCode' => ['mobile' => $mobile, 'mobileVerificationCode' => $code],
    'loginCode' => ['mobile' => $mobile, 'loginCode' => $code],
];

foreach ($bodies as $name => $body) {
    $response = HttpClient::request('POST', 'https://drdr.ir/api/v3/auth/login/mobile/init', $headers, $body, 'json', $jar);
    echo $name . ' status=' . $response['status'] . ' body=' . substr((string) $response['raw'], 0, 160) . PHP_EOL;
}

foreach (['PUT', 'PATCH'] as $method) {
    $response = HttpClient::request($method, 'https://drdr.ir/api/v3/auth/login/mobile/init', $headers, ['mobile' => $mobile, 'code' => $code], 'json', $jar);
    echo $method . ' status=' . $response['status'] . ' body=' . substr((string) $response['raw'], 0, 160) . PHP_EOL;
}

$otherPaths = [
    'https://drdr.ir/api/v3/auth/login/mobile',
    'https://drdr.ir/api/v3/auth/login',
    'https://drdr.ir/api/v3/auth/verify/mobile',
    'https://drdr.ir/api/v3/auth/verify/mobile/code',
    'https://drdr.ir/api/v3/auth/mobile/verify',
    'https://drdr.ir/api/v3/auth/mobile/login',
    'https://drdr.ir/api/v3/auth/otp/verify',
    'https://drdr.ir/api/v3/auth/otp/login',
    'https://drdr.ir/api/v3/login/mobile/verify',
    'https://drdr.ir/api/v3/login/mobile/code',
    'https://drdr.ir/api/v2/auth/login/mobile/verify',
    'https://drdr.ir/api/v4/auth/login/mobile/verify',
    'https://api.drdr.ir/v3/auth/login/mobile/verify',
    'https://api.drdr.ir/api/v3/auth/login/mobile/verify',
    'https://panel.drdr.ir/api/v3/auth/login/mobile/verify',
    'https://panel.drdr.ir/api/v3/auth/login/mobile/init',
];

foreach ($otherPaths as $url) {
    $response = HttpClient::request('POST', $url, $headers, ['mobile' => $mobile, 'code' => $code], 'json', $jar);
    if ($response['status'] === 404 || $response['status'] === 405) {
        echo basename(parse_url($url, PHP_URL_PATH) ?: $url) . ' @ ' . parse_url($url, PHP_URL_HOST) . ' -> ' . $response['status'] . PHP_EOL;
        continue;
    }
    echo $url . ' -> ' . $response['status'] . ' :: ' . substr((string) $response['raw'], 0, 160) . PHP_EOL;
}
