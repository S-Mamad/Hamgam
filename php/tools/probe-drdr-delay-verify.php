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
$jar = __DIR__ . '/../storage/drdr_probe_delay.txt';
@unlink($jar);

echo "init...\n";
HttpClient::request('POST', 'https://drdr.ir/api/v3/auth/login/mobile/init', $headers, ['mobile' => $mobile], 'json', $jar);

$delays = [0, 30, 60, 120, 180, 240];
foreach ($delays as $delay) {
    if ($delay > 0) {
        echo "sleep {$delay}s...\n";
        sleep($delay);
    }
    $v = HttpClient::request(
        'POST',
        'https://drdr.ir/api/v3/oauth/token/',
        $headers,
        [
            'grant_type' => 'otp',
            'client_id' => 'f60d5037-b7ac-404a-9e3a-a263fd9f8054',
            'client_secret' => 'vZHXE1Wx15g0RMVacaTN4KbKJ1mCk3jjkx3ZoKDj',
            'mobile' => $mobile,
            'otp_code' => '123456',
            'scope' => '*',
        ],
        'json',
        $jar
    );
    echo "after {$delay}s: {$v['status']} {$v['raw']}\n";
    if (str_contains((string) $v['raw'], 'سیستم')) {
        echo "FOUND SYSTEM ERROR\n";
        break;
    }
}
