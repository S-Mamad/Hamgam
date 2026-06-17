<?php

declare(strict_types=1);

/**
 * auth.php loads getSettings() which needs GoogleVacationRepository via bootstrap.
 *
 * Usage: php php/tools/test-auth-bootstrap.php
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

echo '=== auth bootstrap dependencies ===' . PHP_EOL;

assertTrue(class_exists('GoogleVacationRepository'), 'GoogleVacationRepository is loaded from bootstrap');

$row = [
    'paziresh24_user_id' => '99999999',
    'color_id' => '9',
    'Patient_name' => 1,
    'Patient_date_time' => 0,
    'Patient_national' => 0,
    'Patient_phone' => 0,
    'auto_vacation' => 0,
    'import_future_vacations' => 0,
    'google_refresh_token' => 'rt-test',
    'google_account_email' => 'test@example.com',
];

try {
    $settings = GoogleTokensRepository::getSettings($row);
    assertTrue(is_array($settings), 'getSettings returns array for connected user row');
    assertTrue(
        array_key_exists('import_future_backfill_slot_count', $settings),
        'getSettings includes backfill slot count'
    );
} catch (Throwable $e) {
    if (str_contains($e->getMessage(), 'could not find driver') || str_contains($e->getMessage(), 'Connection')) {
        echo 'SKIP getSettings DB call (no local database driver)' . PHP_EOL;
    } else {
        echo 'FAIL getSettings threw: ' . $e->getMessage() . PHP_EOL;
        $failed++;
    }
}

exit($failed > 0 ? 1 : 0);
