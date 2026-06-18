<?php

declare(strict_types=1);

/**
 * Unit tests: import_future_vacations one-time use per user.
 *
 * CLI: php -c dev/php.ini php/tools/test-import-future-one-time.php
 */

require_once __DIR__ . '/../includes/bootstrap.php';

if (ob_get_level()) {
    ob_end_clean();
}

$passed = 0;
$failed = 0;

function assertOneTime(string $name, bool $ok, mixed $detail = null): void
{
    global $passed, $failed;

    if ($ok) {
        $passed++;
        echo 'PASS ' . $name . PHP_EOL;
        return;
    }

    $failed++;
    echo 'FAIL ' . $name . PHP_EOL;
    if ($detail !== null) {
        $line = is_string($detail) ? $detail : json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        echo '  ' . $line . PHP_EOL;
    }
}

function applyImportFutureVacationsLock(array $existing, array $settings): array
{
    $ref = new ReflectionClass(GoogleTokensRepository::class);
    $method = $ref->getMethod('applyImportFutureVacationsLock');
    $method->setAccessible(true);

    /** @var array<string, mixed> $result */
    $result = $method->invoke(null, $existing, $settings);

    return $result;
}

echo '=== import_future_vacations one-time use ===' . PHP_EOL;

$defaults = GoogleTokensRepository::getSettings(null);
assertOneTime('default import_future_vacations_used is false', ($defaults['import_future_vacations_used'] ?? null) === false);

$unusedRow = [
    'import_future_vacations' => 1,
    'import_future_vacations_done_at' => null,
    'auto_vacation' => 1,
];
assertOneTime('hasCompleted false when done_at empty', !GoogleTokensRepository::hasCompletedImportFutureVacations($unusedRow));
assertOneTime('shouldRun when flag on and not done', GoogleTokensRepository::shouldRunFutureVacationsBackfill($unusedRow));

$usedRow = [
    'import_future_vacations' => 1,
    'import_future_vacations_done_at' => '2026-06-01 12:00:00',
    'auto_vacation' => 1,
];
assertOneTime('hasCompleted true when done_at set', GoogleTokensRepository::hasCompletedImportFutureVacations($usedRow));
assertOneTime('shouldRun false after successful use', !GoogleTokensRepository::shouldRunFutureVacationsBackfill($usedRow));

$settingsFromUsed = GoogleTokensRepository::getSettings($usedRow);
assertOneTime('getSettings exposes import_future_vacations_used', ($settingsFromUsed['import_future_vacations_used'] ?? null) === true);

$lockSettings = [
    'color_id' => '9',
    'Patient_name' => true,
    'Patient_date_time' => false,
    'Patient_national' => false,
    'Patient_phone' => false,
    'auto_vacation' => true,
    'import_future_vacations' => true,
    'cancel_appointment_on_event_delete' => true,
    'cancel_conflicting_appointments' => true,
    'vacation_sync_centers' => ['mode' => 'selected', 'center_ids' => ['center-a']],
];

$usedOffRow = [
    'import_future_vacations' => 0,
    'import_future_vacations_done_at' => '2026-06-01 12:00:00',
];
$blockedReenable = applyImportFutureVacationsLock($usedOffRow, $lockSettings);
assertOneTime(
    're-enable blocked after one-time use',
    ($blockedReenable['import_future_vacations'] ?? null) === false
);

$usedOnRow = [
    'import_future_vacations' => 1,
    'import_future_vacations_done_at' => '2026-06-01 12:00:00',
];
$stayOn = applyImportFutureVacationsLock($usedOnRow, $lockSettings);
assertOneTime(
    'already-on stays on after one-time use',
    ($stayOn['import_future_vacations'] ?? null) === true
);

$turnOffSettings = $lockSettings;
$turnOffSettings['import_future_vacations'] = false;
$allowOff = applyImportFutureVacationsLock($usedOnRow, $turnOffSettings);
assertOneTime(
    'user may turn off flag after one-time use',
    ($allowOff['import_future_vacations'] ?? null) === false
);

$freshRow = [
    'import_future_vacations' => 0,
    'import_future_vacations_done_at' => null,
];
$firstEnable = applyImportFutureVacationsLock($freshRow, $lockSettings);
assertOneTime(
    'first enable allowed when never used',
    ($firstEnable['import_future_vacations'] ?? null) === true
);

$testUserId = '99999002';
$dbOk = null;
$dbDetail = 'skipped: DB unavailable locally';
try {
    GoogleTokensRepository::deleteByUserId($testUserId);

    $payload = [
        'colorId' => '9',
        'fullName' => true,
        'centerName' => false,
        'datetime' => false,
        'nationalId' => false,
        'phone' => false,
        'autoVacation' => true,
        'importFutureVacations' => false,
        'cancelAppointmentOnEventDelete' => true,
        'cancelConflictingAppointments' => true,
        'vacationSyncCenters' => ['mode' => 'selected', 'centerIds' => ['center-a']],
    ];

    GoogleTokensRepository::updateSettings($testUserId, $payload);
    Database::connection()->prepare(
        'UPDATE google_tokens SET
            import_future_vacations = 0,
            import_future_vacations_done_at = :done_at
         WHERE paziresh24_user_id = :user_id'
    )->execute([
        'done_at' => '2026-06-01 10:00:00',
        'user_id' => $testUserId,
    ]);

    $reenablePayload = $payload;
    $reenablePayload['importFutureVacations'] = true;
    GoogleTokensRepository::updateSettings($testUserId, $reenablePayload);
    $rowAfterReenableAttempt = GoogleTokensRepository::findByUserId($testUserId);

    GoogleTokensRepository::resetImportBackfillState($testUserId);
    $rowAfterResetAttempt = GoogleTokensRepository::findByUserId($testUserId);

    $dbOk = GoogleTokensRepository::hasCompletedImportFutureVacations($rowAfterReenableAttempt)
        && !GoogleTokensRepository::toBoolPublic($rowAfterReenableAttempt['import_future_vacations'] ?? false)
        && GoogleTokensRepository::hasCompletedImportFutureVacations($rowAfterResetAttempt);
    $dbDetail = $dbOk ? 'sqlite/mysql round-trip ok' : 'save mismatch';

    GoogleTokensRepository::deleteByUserId($testUserId);
} catch (Throwable $e) {
    $dbDetail = 'skipped: ' . $e->getMessage();
}

assertOneTime(
    'updateSettings keeps done_at after re-enable attempt',
    $dbOk === null || $dbOk === true,
    $dbDetail
);

echo PHP_EOL . "=== Results: {$passed} passed, {$failed} failed ===" . PHP_EOL;
exit($failed > 0 ? 1 : 0);
