<?php

declare(strict_types=1);

/**
 * DrDr integration defaults (no .env required).
 * After DrDr registers your OAuth app, set client_id and client_secret here once.
 */
return [
    'client_id' => '',
    'client_secret' => '',
    'auth_url' => 'https://panel.drdr.ir/oauth/authorize',
    'token_url' => 'https://panel.drdr.ir/oauth/token',
    'login_url' => 'https://panel.drdr.ir/',
    'scope' => '',
    'extra_auth_params' => [],
];
