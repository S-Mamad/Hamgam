<?php

declare(strict_types=1);

/**
 * DrDr provider settings (no .env required).
 * api_client_id is the public web client id used by drdr.ir login.
 */
return [
    'api_client_id' => 'f60d5037-b7ac-404a-9e3a-a263fd9f8054',
    'send_otp_url' => 'https://drdr.ir/api/v3/auth/login/mobile/init',
    'verify_otp_url' => 'https://drdr.ir/api/v3/auth/login/mobile/verify',
    'verify_otp_urls' => [
        'https://drdr.ir/api/v3/auth/login/mobile/verify',
        'https://drdr.ir/api/v3/auth/login/mobile/confirm',
        'https://drdr.ir/api/v3/auth/login/mobile/complete',
    ],
    'client_id' => '',
    'client_secret' => '',
    'auth_url' => 'https://panel.drdr.ir/oauth/authorize',
    'token_url' => 'https://panel.drdr.ir/oauth/token',
    'login_url' => 'https://drdr.ir/login/?f=true',
    'scope' => '',
    'extra_auth_params' => [],
];
