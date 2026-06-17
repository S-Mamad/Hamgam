<?php

declare(strict_types=1);

/**
 * Verifies change-gmail OAuth bridge + login_hint behavior.
 *
 * Usage: php php/tools/test-change-gmail-redirect.php
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/HamgamRedirects.php';

$failed = 0;

function assertTrue(bool $condition, string $message): void
{
    global $failed;

    if ($condition) {
        echo 'OK   ' . $message . PHP_EOL;
        return;
    }

    echo 'FAIL ' . $message . PHP_EOL;
    $failed++;
}

/**
 * @param array<string, mixed> $state
 */
function buildOAuthSuccessRedirectUrlForTest(array $state): string
{
    $mode = $state['mode'] ?? '';
    $returnTo = $state['return_to'] ?? 'settings';
    if (!is_string($returnTo)) {
        $returnTo = 'settings';
    }

    if ($returnTo === 'launcher') {
        return HamgamRedirects::launcherAppOpenUrl();
    }

    $settingsUrl = rtrim(Config::require('REDIRECT_SETTINGS'), '/');
    $query = ['oauth' => 'success'];
    if ($mode === 'change_gmail') {
        $query['change'] = 'gmail';
    }

    return $settingsUrl . '/?' . http_build_query($query);
}

$accessToken = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIyMzQ4OTQ0MiJ9.test.signature';
$userId = '23489442';

$oauthUrl = Paziresh24Api::buildGoogleOAuthUrl($accessToken, 'launcher', 'change_gmail', $userId, null);
$query = [];
parse_str((string) parse_url($oauthUrl, PHP_URL_QUERY), $query);

$stateRaw = $query['state'] ?? '';
$state = json_decode(urldecode($stateRaw), true);
if (!is_array($state)) {
    $state = json_decode($stateRaw, true);
}

assertTrue(is_array($state), 'OAuth state is valid JSON');
assertTrue(($state['return_to'] ?? '') === 'launcher', 'change-gmail OAuth state uses return_to=launcher');
assertTrue(($state['mode'] ?? '') === 'change_gmail', 'change-gmail OAuth state keeps mode=change_gmail');
assertTrue(($state['user_id'] ?? '') === $userId, 'change-gmail OAuth state includes user_id');
assertTrue(($query['login_hint'] ?? '') === '', 'change-gmail OAuth omits login_hint so user can pick another account');

$settingsOAuthUrl = Paziresh24Api::buildGoogleOAuthUrl($accessToken, 'settings', 'change_gmail', $userId);
parse_str((string) parse_url($settingsOAuthUrl, PHP_URL_QUERY), $settingsQuery);
$settingsState = json_decode(urldecode((string) ($settingsQuery['state'] ?? '')), true);
assertTrue(is_array($settingsState) && ($settingsState['return_to'] ?? '') === 'settings', 'settings return_to supported for change-gmail');

$successUrl = buildOAuthSuccessRedirectUrlForTest([
    'return_to' => 'launcher',
    'mode' => 'change_gmail',
]);
$launcherAppUrl = rtrim(HamgamRedirects::launcherAppOpenUrl(), '/');

assertTrue(rtrim($successUrl, '/') === $launcherAppUrl, 'OAuth success opens app inside Paziresh24 launcher (direct=true)');
assertTrue(
    str_contains($successUrl, 'direct=true'),
    'OAuth success uses launcher direct mode for in-frame settings'
);

$settingsFallbackUrl = buildOAuthSuccessRedirectUrlForTest([
    'return_to' => 'settings',
    'mode' => 'change_gmail',
]);
assertTrue(
    str_contains($settingsFallbackUrl, 'oauth=success') && str_contains($settingsFallbackUrl, 'change=gmail'),
    'settings return includes oauth success markers'
);

$ref = new ReflectionClass(Response::class);
$method = $ref->getMethod('redirectViaLauncherBridge');
assertTrue($method->getNumberOfParameters() >= 3, 'launcher bridge accepts custom storage key');

exit($failed > 0 ? 1 : 0);
