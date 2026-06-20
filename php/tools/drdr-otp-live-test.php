<?php

declare(strict_types=1);

/**
 * Live DrDr OTP test from server CLI (no Paziresh24 token required).
 *
 * Usage:
 *   php -c dev/php.ini php/tools/drdr-otp-live-test.php 09123456789
 *
 * Sends OTP to the mobile, then prompts for the SMS code and prints the full DrDr response.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/DrDrAuthService.php';

$mobileArg = $argv[1] ?? '';
$mobile = DrDrAuthService::normalizeMobile($mobileArg);
if ($mobile === null) {
    fwrite(STDERR, "Usage: php drdr-otp-live-test.php 09XXXXXXXXX\n");
    exit(1);
}

echo "DrDr OTP live test\n";
echo "Mobile: {$mobile}\n\n";

$configPath = __DIR__ . '/../config/providers/drdr.php';
$config = is_file($configPath) ? require $configPath : [];
if (!is_array($config)) {
    $config = [];
}

$clientId = trim((string) ($config['api_client_id'] ?? 'f60d5037-b7ac-404a-9e3a-a263fd9f8054'));
$clientSecret = trim((string) ($config['api_client_secret'] ?? 'vZHXE1Wx15g0RMVacaTN4KbKJ1mCk3jjkx3ZoKDj'));
$initUrl = trim((string) ($config['send_otp_url'] ?? 'https://drdr.ir/api/v3/auth/login/mobile/init'));
$tokenUrl = trim((string) ($config['oauth_token_url'] ?? 'https://drdr.ir/api/v3/oauth/token/'));

$headers = [
    'Content-Type' => 'application/json',
    'Accept' => 'application/json',
    'client-id' => $clientId,
    'Origin' => 'https://drdr.ir',
    'Referer' => 'https://drdr.ir/login/?f=true',
];

$jar = __DIR__ . '/../storage/drdr_probe_live_' . preg_replace('/\D/', '', $mobile) . '.txt';
@unlink($jar);

echo "1) Sending OTP via init...\n";
$init = HttpClient::request('POST', $initUrl, $headers, ['mobile' => $mobile], 'json', $jar);
echo '   HTTP ' . $init['status'] . "\n";
echo '   Body: ' . ($init['raw'] ?? '') . "\n\n";

if ($init['status'] < 200 || $init['status'] >= 300) {
    exit(1);
}

echo '2) Enter SMS code: ';
$codeRaw = trim((string) fgets(STDIN));
$code = DrDrAuthService::normalizeOtpCode($codeRaw);
if ($code === null) {
    fwrite(STDERR, "Invalid code format\n");
    exit(1);
}

echo "\n3) Verifying via oauth/token...\n";
$verify = HttpClient::request(
    'POST',
    $tokenUrl,
    $headers,
    [
        'grant_type' => 'otp',
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'mobile' => $mobile,
        'otp_code' => $code,
        'scope' => '*',
    ],
    'json',
    $jar
);

echo '   HTTP ' . $verify['status'] . "\n";
echo '   Body: ' . ($verify['raw'] ?? '') . "\n\n";

if (!is_array($verify['body'])) {
    exit(1);
}

$result = $verify['body']['result'] ?? $verify['body']['payload'] ?? $verify['body']['data'] ?? $verify['body'];
$tokenKeys = [];
if (is_array($result)) {
    foreach (['access_token', 'accessToken', 'token', 'refresh_token', 'refreshToken', 'expires_in', 'expiresIn'] as $key) {
        if (array_key_exists($key, $result)) {
            $tokenKeys[] = $key;
        }
    }
}

if ($tokenKeys !== []) {
    echo 'SUCCESS: token fields found in result: ' . implode(', ', $tokenKeys) . "\n";
    exit(0);
}

echo "FAIL: no access token fields in response.\n";
exit(1);
