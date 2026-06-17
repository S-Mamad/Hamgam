<?php

declare(strict_types=1);

/**
 * Unit tests: permanent doctor lock + backfill slot storage.
 *
 * CLI: php -c dev/php.ini php/tools/test-import-future-doctor-lock.php
 */

require_once __DIR__ . '/../includes/bootstrap.php';

if (ob_get_level()) {
    ob_end_clean();
}

$passed = 0;
$failed = 0;

function assertDoctorLock(string $name, bool $ok, mixed $detail = null): void
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

echo '=== import_future_vacations doctor lock ===' . PHP_EOL;

$doctorId = '99999003';
$dbReady = true;
try {
    Database::connection();
} catch (Throwable $e) {
    $dbReady = false;
    assertDoctorLock('db unavailable (optional)', true, 'skipped: ' . $e->getMessage());
}

if ($dbReady) {
    GoogleTokensRepository::deleteByUserId($doctorId);
    Database::connection()->prepare(
        'DELETE FROM import_future_vacations_doctor_lock WHERE paziresh24_user_id = :doctor_id'
    )->execute(['doctor_id' => $doctorId]);
    Database::connection()->prepare(
        'DELETE FROM import_future_vacations_backfill_slots WHERE paziresh24_user_id = :doctor_id'
    )->execute(['doctor_id' => $doctorId]);

    assertDoctorLock('doctor not locked initially', !ImportFutureVacationsRepository::isDoctorLocked($doctorId));

    ImportFutureVacationsRepository::lockDoctor($doctorId);
    assertDoctorLock('doctor locked after lockDoctor', ImportFutureVacationsRepository::isDoctorLocked($doctorId));

    GoogleTokensRepository::upsertHamdastAccessToken($doctorId, 'test-token');
    $row = GoogleTokensRepository::findByUserId($doctorId);
    assertDoctorLock(
        'hasCompleted true from permanent lock without done_at',
        GoogleTokensRepository::hasCompletedImportFutureVacations($row)
    );
    assertDoctorLock(
        'shouldRun backfill false when doctor locked',
        !GoogleTokensRepository::shouldRunFutureVacationsBackfill($row)
    );

    GoogleTokensRepository::purgeUserRecords($doctorId);
    assertDoctorLock(
        'doctor lock survives purgeUserRecords',
        ImportFutureVacationsRepository::isDoctorLocked($doctorId)
    );

    GoogleTokensRepository::upsertHamdastAccessToken($doctorId, 'test-token-2');
    $freshRow = GoogleTokensRepository::findByUserId($doctorId);
    assertDoctorLock(
        'reconnect still blocked after purge',
        GoogleTokensRepository::hasCompletedImportFutureVacations($freshRow)
    );

    ImportFutureVacationsRepository::recordBackfillSlot($doctorId, 'center-a', 1780000000, 1780003600);
    ImportFutureVacationsRepository::recordBackfillSlot($doctorId, 'center-a', 1780000000, 1780003600);
    $slots = ImportFutureVacationsRepository::listActiveBackfillSlots($doctorId);
    assertDoctorLock('backfill slot stored once (idempotent)', count($slots) === 1);
    assertDoctorLock(
        'undo available when active slots exist',
        ImportFutureVacationsRepository::hasActiveBackfillSlots($doctorId)
    );

    ImportFutureVacationsRepository::upsertBackfillSlotForEvent(
        $doctorId,
        'event-backfill-1',
        'center-a',
        1780100000,
        1780103600
    );
    assertDoctorLock(
        'active slot detectable by event id',
        ImportFutureVacationsRepository::hasActiveBackfillSlotForEvent($doctorId, 'event-backfill-1', 'center-a')
    );
    ImportFutureVacationsRepository::upsertBackfillSlotForEvent(
        $doctorId,
        'event-backfill-1',
        'center-a',
        1780200000,
        1780203600
    );
    $updatedEventSlots = ImportFutureVacationsRepository::listActiveBackfillSlots($doctorId);
    $updatedEventSlot = null;
    foreach ($updatedEventSlots as $candidate) {
        if (($candidate['google_event_id'] ?? '') === 'event-backfill-1') {
            $updatedEventSlot = $candidate;
            break;
        }
    }
    assertDoctorLock(
        'upsertBackfillSlotForEvent updates existing slot timestamps',
        is_array($updatedEventSlot)
            && (int) ($updatedEventSlot['vacation_from'] ?? 0) === 1780200000
            && (int) ($updatedEventSlot['vacation_to'] ?? 0) === 1780203600
    );

    ImportFutureVacationsRepository::markBackfillSlotDeleted($slots[0]['id']);
    ImportFutureVacationsRepository::markBackfillSlotsDeletedByEvent($doctorId, 'event-backfill-1', 'center-a');
    assertDoctorLock(
        'no active slots after mark deleted',
        !ImportFutureVacationsRepository::hasActiveBackfillSlots($doctorId)
    );

    GoogleTokensRepository::clearImportFutureVacationsCompletion($doctorId);
    $afterResetRow = GoogleTokensRepository::findByUserId($doctorId);
    assertDoctorLock(
        'doctor unlocked after clear completion',
        !ImportFutureVacationsRepository::isDoctorLocked($doctorId)
    );
    assertDoctorLock(
        'backfill slots purged after clear completion',
        ImportFutureVacationsRepository::countActiveBackfillSlots($doctorId) === 0
    );
    assertDoctorLock(
        'backfill may run again after clear completion',
        !GoogleTokensRepository::hasCompletedImportFutureVacations($afterResetRow)
    );

}
if ($dbReady) {
    try {
        Database::connection()->prepare(
            'DELETE FROM import_future_vacations_doctor_lock WHERE paziresh24_user_id = :doctor_id'
        )->execute(['doctor_id' => $doctorId]);
        GoogleTokensRepository::deleteByUserId($doctorId);
    } catch (Throwable $cleanupError) {
        assertDoctorLock('cleanup after tests', false, $cleanupError->getMessage());
    }
}

echo PHP_EOL . "=== Results: {$passed} passed, {$failed} failed ===" . PHP_EOL;
exit($failed > 0 ? 1 : 0);
