<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/DrDrAuthService.php';

// Smoke test for OAuth token body parsing via reflection-free public verify path is hard;
// instead validate config loads and oauth URL is set.
$configPath = __DIR__ . '/../config/providers/drdr.php';
$config = require $configPath;

assert(is_array($config));
assert(($config['oauth_token_url'] ?? '') === 'https://drdr.ir/api/v3/oauth/token/');
assert(($config['api_client_secret'] ?? '') !== '');

echo "drdr oauth config ok\n";
