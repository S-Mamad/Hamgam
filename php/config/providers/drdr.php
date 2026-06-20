<?php

declare(strict_types=1);

/**
 * DrDr provider settings (no .env required).
 * client_id/client_secret are the public web OAuth credentials embedded in drdr.ir login JS.
 */
return [
    'api_client_id' => 'f60d5037-b7ac-404a-9e3a-a263fd9f8054',
    'api_client_secret' => 'vZHXE1Wx15g0RMVacaTN4KbKJ1mCk3jjkx3ZoKDj',
    'send_otp_url' => 'https://drdr.ir/api/v3/auth/login/mobile/init',
    'oauth_token_url' => 'https://drdr.ir/api/v3/oauth/token/',
    'oauth_scope' => '*',
    'client_id' => '',
    'client_secret' => '',
    'auth_url' => 'https://panel.drdr.ir/oauth/authorize',
    'token_url' => 'https://panel.drdr.ir/oauth/token',
    'login_url' => 'https://drdr.ir/login/?f=true',
    'scope' => '',
    'extra_auth_params' => [],
];
