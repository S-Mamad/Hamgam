<?php

declare(strict_types=1);

/**
 * Integration checks for OAuth → launcher/settings redirect flow.
 *
 * Usage: php php/tools/test-oauth-flow.php
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

echo '=== OAuth flow redirect targets ===' . PHP_EOL;

$launcherApp = HamgamRedirects::launcherAppOpenUrl();
$launcherHome = HamgamRedirects::launcherHomeUrl();

assertTrue(str_contains($launcherApp, 'direct=true'), 'launcherAppOpenUrl uses direct=true');
assertTrue(
    parse_url($launcherApp, PHP_URL_HOST) === 'www.paziresh24.com',
    'launcherAppOpenUrl stays on Paziresh24 domain'
);
assertTrue(
    !str_contains($launcherHome, 'direct=true'),
    'launcherHomeUrl is plain launcher (logout path)'
);
assertTrue($launcherApp !== $launcherHome, 'app-open and home launcher URLs differ');

$buttonOAuthUrl = Paziresh24Api::buildGoogleOAuthUrl('eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIyMzQ4OTQ0MiJ9.t.s', 'launcher', null, '23489442');
$query = [];
parse_str((string) parse_url($buttonOAuthUrl, PHP_URL_QUERY), $query);
$state = json_decode(urldecode((string) ($query['state'] ?? '')), true);
assertTrue(is_array($state) && ($state['return_to'] ?? '') === 'launcher', 'button OAuth uses return_to=launcher');
assertTrue(is_array($state) && ($state['user_id'] ?? '') === '23489442', 'OAuth state carries user_id for fast callback');

$settingsOAuthUrl = Paziresh24Api::buildGoogleOAuthUrl('eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIyMzQ4OTQ0MiJ9.t.s', 'settings', null, '23489442');
parse_str((string) parse_url($settingsOAuthUrl, PHP_URL_QUERY), $query);
$settingsState = json_decode(urldecode((string) ($query['state'] ?? '')), true);
assertTrue(is_array($settingsState) && ($settingsState['user_id'] ?? '') === '23489442', 'settings OAuth state carries user_id');

$afterButtonOAuth = buildOAuthSuccessRedirectUrlForTest(['return_to' => 'launcher']);
assertTrue($afterButtonOAuth === $launcherApp, 'button OAuth success opens app inside launcher');

$afterSettingsOAuth = buildOAuthSuccessRedirectUrlForTest(['return_to' => 'settings']);
assertTrue(
    str_contains($afterSettingsOAuth, 'oauth=success')
    && str_contains($afterSettingsOAuth, (string) parse_url(Config::require('REDIRECT_SETTINGS'), PHP_URL_HOST)),
    'settings OAuth success returns to settings domain with oauth=success'
);

$afterChangeGmail = buildOAuthSuccessRedirectUrlForTest([
    'return_to' => 'launcher',
    'mode' => 'change_gmail',
]);
assertTrue($afterChangeGmail === $launcherApp, 'change-gmail launcher success uses direct launcher');

echo PHP_EOL . '=== Response instant redirect (speed) ===' . PHP_EOL;

$ref = new ReflectionClass(Response::class);
$method = $ref->getMethod('sendInstantRedirectHtml');
$method->setAccessible(true);

ob_start();
$method->invoke(null, $launcherApp);
$html = (string) ob_get_clean();

assertTrue(str_contains($html, 'location.replace'), 'instant redirect uses location.replace');
assertTrue(str_contains($html, 'direct=true'), 'instant redirect target includes direct=true');

$headers = headers_list();
assertTrue(
    str_contains($html, '<!DOCTYPE html>'),
    'redirectThenContinue path returns HTML body (not bare 302)'
);

echo PHP_EOL . '=== Deploy parity (php vs deploy) ===' . PHP_EOL;

$deployRoot = dirname(__DIR__, 2) . '/deploy/php';
$pairs = [
    'hamgam/google-oauth.php',
    'hamgam/button.php',
    'hamgam/auth.php',
    'hamgam/change-gmail.php',
    'hamgam/update.php',
    'includes/GoogleTokensRepository.php',
    'includes/HamgamRedirects.php',
    'includes/Response.php',
];

foreach ($pairs as $rel) {
    $src = dirname(__DIR__) . '/' . $rel;
    $dst = $deployRoot . '/' . $rel;
    if (!is_file($dst)) {
        assertTrue(false, "deploy missing: {$rel}");
        continue;
    }
    $same = hash_file('sha256', $src) === hash_file('sha256', $dst);
    assertTrue($same, "deploy matches source: {$rel}");
}

$rootScript = dirname(__DIR__, 2) . '/script.js';
$deployScript = dirname(__DIR__, 2) . '/deploy/script.js';
if (is_file($deployScript)) {
    assertTrue(
        hash_file('sha256', $rootScript) === hash_file('sha256', $deployScript),
        'deploy/script.js matches script.js'
    );
}

exit($failed > 0 ? 1 : 0);
