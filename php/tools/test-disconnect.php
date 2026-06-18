<?php

declare(strict_types=1);

/**
 * Disconnect must preserve user settings row (by doctor id), only remove Google credentials.
 *
 * Usage: php php/tools/test-disconnect.php
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

$doctorId = 'disconnect-test-' . bin2hex(random_bytes(4));

GoogleTokensRepository::upsertHamdastAccessToken($doctorId, 'hamdast-token-test');
assertTrue(GoogleTokensRepository::updateSettings($doctorId, [
    'colorId' => '5',
    'fullName' => true,
    'centerName' => false,
    'datetime' => true,
    'nationalId' => false,
    'phone' => false,
    'autoVacation' => true,
    'importFutureVacations' => false,
    'cancelAppointmentOnEventDelete' => true,
    'cancelConflictingAppointments' => false,
    'vacationSyncCenters' => ['mode' => 'selected', 'centerIds' => ['c-1']],
]), 'seed settings row');

$pdo = Database::connection();
$stmt = $pdo->prepare(
    'UPDATE google_tokens SET
        google_refresh_token = :rt,
        google_access_token = :at,
        google_account_email = :email,
        google_channel_id = :channel,
        google_resource_id = :resource
     WHERE paziresh24_user_id = :user_id'
);
$stmt->execute([
    'user_id' => $doctorId,
    'rt' => 'rt-test',
    'at' => 'at-test',
    'email' => 'doctor@example.com',
    'channel' => 'channel-test',
    'resource' => 'resource-test',
]);

$rowBefore = GoogleTokensRepository::findByUserId($doctorId);
assertTrue(GoogleTokensRepository::hasRefreshToken($rowBefore), 'doctor has refresh token before disconnect');

$watch = GoogleTokensRepository::watchCleanupCredentials($rowBefore);
assertTrue(is_array($watch) && $watch['channel_id'] === 'channel-test', 'watch cleanup credentials extracted');

assertTrue(GoogleTokensRepository::disconnectGoogleConnection($doctorId), 'disconnectGoogleConnection succeeds');

$rowAfter = GoogleTokensRepository::findByUserId($doctorId);
assertTrue($rowAfter !== null, 'settings row survives disconnect');
assertTrue(!GoogleTokensRepository::hasRefreshToken($rowAfter), 'refresh token cleared after disconnect');

$afterSettings = GoogleTokensRepository::getSettings($rowAfter);
assertTrue($afterSettings['color_id'] === '5', 'color_id preserved after disconnect');
assertTrue($afterSettings['Patient_date_time'] === true, 'Patient_date_time preserved after disconnect');
assertTrue($afterSettings['auto_vacation'] === true, 'auto_vacation preserved after disconnect');
assertTrue($afterSettings['google_account_email'] === null, 'google_account_email cleared after disconnect');
assertTrue(
    $afterSettings['vacation_sync_centers']['center_ids'] === ['c-1'],
    'vacation_sync_centers preserved after disconnect'
);

$repo = new ReflectionClass(GoogleTokensRepository::class);
assertTrue($repo->hasMethod('disconnectGoogleConnection'), 'repository exposes disconnectGoogleConnection');
assertTrue($repo->hasMethod('watchCleanupCredentials'), 'repository exposes watchCleanupCredentials');

$buttonSource = (string) file_get_contents(dirname(__DIR__) . '/hamgam/button.php');
assertTrue(
    str_contains($buttonSource, 'disconnectGoogleConnection'),
    'button.php disconnect uses disconnectGoogleConnection'
);
assertTrue(
    !preg_match('/disconnectRequested[\s\S]{0,400}purgeUserRecords/s', $buttonSource),
    'button.php disconnect does not purge user records'
);

assertTrue(is_file(dirname(__DIR__) . '/hamgam/disconnect.php'), 'disconnect.php endpoint exists');

GoogleTokensRepository::purgeUserRecords($doctorId);

exit($failed > 0 ? 1 : 0);
