<?php

declare(strict_types=1);

/**
 * Launcher button flow expectations (connected users must open app, not disconnect).
 *
 * Usage: php php/tools/test-button-flow.php
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

echo '=== Launcher button flow ===' . PHP_EOL;

$buttonSource = (string) file_get_contents(dirname(__DIR__) . '/hamgam/button.php');

assertTrue(
    str_contains($buttonSource, 'disconnectRequested'),
    'button.php supports explicit disconnect query param'
);
assertTrue(
    str_contains($buttonSource, 'opening app for connected user'),
    'button.php opens app when user is already connected'
);
assertTrue(
    str_contains($buttonSource, 'disconnectGoogleConnection'),
    'button.php disconnect preserves settings via disconnectGoogleConnection'
);
assertTrue(
    !preg_match('/disconnectRequested[\s\S]{0,500}purgeUserRecords/s', $buttonSource),
    'button.php disconnect does not purge all user records'
);

$oauthSource = (string) file_get_contents(dirname(__DIR__) . '/hamgam/google-oauth.php');

assertTrue(
    str_contains($oauthSource, 'function runOAuthSuccessBackgroundWork')
    && str_contains($oauthSource, 'Paziresh24Api::upsertWidget($userId)'),
    'google-oauth registers widget in background after redirect'
);
assertTrue(
    str_contains($oauthSource, 'launcherAppOpenUrl()'),
    'OAuth launcher failure opens app (direct=true) so errors are visible'
);

$scriptSource = (string) file_get_contents(dirname(__DIR__, 2) . '/script.js');

assertTrue(
    str_contains($scriptSource, 'provider.management.write')
    && !str_contains($scriptSource, 'provider.management.read'),
    'script.js requests management.write only (not extra management.read)'
);

exit($failed > 0 ? 1 : 0);
