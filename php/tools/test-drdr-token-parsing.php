<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/DrDrAuthService.php';

// Simulate token extraction from likely DrDr oauth/token success bodies.
$bodies = [
    'flat_result' => [
        'ok' => true,
        'result' => [
            'access_token' => 'abc123',
            'refresh_token' => 'ref456',
            'expires_in' => 3600,
            'token_type' => 'Bearer',
        ],
        'code' => 200,
    ],
    'code_2001' => [
        'ok' => true,
        'result' => [
            'access_token' => 'abc123',
            'token' => 'abc123',
        ],
        'code' => 2001,
    ],
    'token_field_only' => [
        'ok' => true,
        'result' => [
            'token' => 'tok789',
            'expiresIn' => 3600,
        ],
    ],
    'nested_payload' => [
        'ok' => true,
        'result' => [
            'payload' => [
                'accessToken' => 'nested-token',
            ],
        ],
    ],
    'top_level_payload_token' => [
        'ok' => true,
        'code' => 2001,
        'payload' => [
            'token' => 'top-token',
        ],
    ],
    'deep_data' => [
        'ok' => true,
        'result' => [
            'data' => [
                'access_token' => 'deep-token',
            ],
        ],
    ],
];

foreach ($bodies as $name => $body) {
    $reflection = new ReflectionClass(DrDrAuthService::class);
    $isOk = $reflection->getMethod('isSuccessfulDrDrBody');
    $isOk->setAccessible(true);
    $unwrap = $reflection->getMethod('unwrapDrDrPayload');
    $unwrap->setAccessible(true);
    $extract = $reflection->getMethod('extractAccessToken');
    $extract->setAccessible(true);

    $successful = $isOk->invoke(null, $body);
    $payload = $unwrap->invoke(null, $body);
    $token = $extract->invoke(null, $payload);

    echo $name . ': successful=' . ($successful ? 'yes' : 'no') . ' token=' . ($token ?? 'null') . PHP_EOL;
}

$reflection = new ReflectionClass(DrDrAuthService::class);
$extractOAuth = $reflection->getMethod('extractTokensFromOAuthBody');
$extractOAuth->setAccessible(true);

foreach ($bodies as $name => $body) {
    try {
        $parsed = $extractOAuth->invoke(null, $body);
        echo $name . ' oauth_extract=' . ($parsed['access_token'] ?? 'null') . PHP_EOL;
    } catch (Throwable $e) {
        echo $name . ' oauth_extract=ERROR:' . $e->getMessage() . PHP_EOL;
    }
}
