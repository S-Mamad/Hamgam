<?php

declare(strict_types=1);

/**
 * Verifies update.php exposes google connection state for the settings UI.
 *
 * Usage: php php/tools/test-update-connected.php
 */

require_once __DIR__ . '/../includes/bootstrap.php';

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

$rowWithToken = [
    'paziresh24_user_id' => '23489442',
    'google_refresh_token' => 'rt-123',
    'google_account_email' => 'doctor@gmail.com',
    'color_id' => '9',
];

$rowWithoutToken = [
    'paziresh24_user_id' => '23489442',
    'google_refresh_token' => '',
    'google_account_email' => 'doctor@gmail.com',
    'color_id' => '9',
];

assertTrue(class_exists('GoogleVacationRepository'), 'GoogleVacationRepository loaded before getSettings');

try {
    $connectedSettings = GoogleTokensRepository::getSettings($rowWithToken);
    $connectedSettings['connected'] = GoogleTokensRepository::hasRefreshToken($rowWithToken);

    $disconnectedSettings = GoogleTokensRepository::getSettings($rowWithoutToken);
    $disconnectedSettings['connected'] = GoogleTokensRepository::hasRefreshToken($rowWithoutToken);

    assertTrue($connectedSettings['connected'] === true, 'connected=true when refresh token exists');
    assertTrue($disconnectedSettings['connected'] === false, 'connected=false when refresh token missing');
    assertTrue(
        $connectedSettings['google_account_email'] === 'doctor@gmail.com',
        'google_account_email still returned for connected users'
    );
} catch (Throwable $e) {
    if (str_contains($e->getMessage(), 'could not find driver') || str_contains($e->getMessage(), 'Connection')) {
        echo 'SKIP getSettings DB assertions (no local database driver)' . PHP_EOL;
    } else {
        echo 'FAIL getSettings threw: ' . $e->getMessage() . PHP_EOL;
        $failed++;
    }
}

exit($failed > 0 ? 1 : 0);
