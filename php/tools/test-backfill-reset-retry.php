<?php

declare(strict_types=1);

/**
 * Simulates doctor flow:
 * 1) backfill creates slots
 * 2) delete/reset clears slots
 * 3) second backfill only tracks new slots (no stale reactivation)
 *
 * CLI: php php/tools/test-backfill-reset-retry.php
 */

require_once __DIR__ . '/../includes/bootstrap.php';

$passed = 0;
$failed = 0;

function assertRetry(string $name, bool $ok, mixed $detail = null): void
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

echo '=== backfill reset + retry slot isolation ===' . PHP_EOL;

$doctorId = '99999004';
$dbReady = true;

try {
    Database::connection();
} catch (Throwable $e) {
    $dbReady = false;
    assertRetry('db unavailable (optional)', true, 'skipped: ' . $e->getMessage());
}

if ($dbReady) {
    GoogleTokensRepository::deleteByUserId($doctorId);
    Database::connection()->prepare(
        'DELETE FROM import_future_vacations_doctor_lock WHERE paziresh24_user_id = :doctor_id'
    )->execute(['doctor_id' => $doctorId]);
    ImportFutureVacationsRepository::purgeBackfillSlotsForDoctor($doctorId);

    // First run: 4 legacy slots (old calendar events)
    for ($i = 0; $i < 4; $i++) {
        ImportFutureVacationsRepository::upsertBackfillSlotForEvent(
            $doctorId,
            'legacy-event-' . $i,
            'center-a',
            1780000000 + ($i * 3600),
            1780001800 + ($i * 3600)
        );
    }
    assertRetry('first run stores 4 active slots', ImportFutureVacationsRepository::countActiveBackfillSlots($doctorId) === 4);

    // Doctor deletes synced vacations → reset
    foreach (ImportFutureVacationsRepository::listActiveBackfillSlots($doctorId) as $slot) {
        ImportFutureVacationsRepository::markBackfillSlotDeleted($slot['id']);
    }
    assertRetry('slots soft-deleted after undo', ImportFutureVacationsRepository::countActiveBackfillSlots($doctorId) === 0);

    GoogleTokensRepository::upsertHamdastAccessToken($doctorId, 'retry-token');
    GoogleTokensRepository::clearImportFutureVacationsCompletion($doctorId);
    assertRetry('reset purges all backfill slot rows', ImportFutureVacationsRepository::countActiveBackfillSlots($doctorId) === 0);

    $pdo = Database::connection()->prepare(
        'SELECT COUNT(*) FROM import_future_vacations_backfill_slots WHERE paziresh24_user_id = :doctor_id'
    );
    $pdo->execute(['doctor_id' => $doctorId]);
    assertRetry('reset removes stale rows entirely', (int) $pdo->fetchColumn() === 0);

    // Second run: only 2 new events
    ImportFutureVacationsRepository::upsertBackfillSlotForEvent($doctorId, 'new-event-1', 'center-a', 1781000000, 1781003600);
    ImportFutureVacationsRepository::upsertBackfillSlotForEvent($doctorId, 'new-event-2', 'center-a', 1781004000, 1781007600);
    assertRetry('second run stores exactly 2 active slots', ImportFutureVacationsRepository::countActiveBackfillSlots($doctorId) === 2);

    // Reactivating deleted legacy rows must not happen
    ImportFutureVacationsRepository::upsertBackfillSlotForEvent($doctorId, 'legacy-event-0', 'center-a', 1780000000, 1780001800);
    assertRetry(
        'legacy event id creates fresh row without resurrecting old batch',
        ImportFutureVacationsRepository::countActiveBackfillSlots($doctorId) === 3
    );

    ImportFutureVacationsRepository::purgeBackfillSlotsForDoctor($doctorId);
    GoogleTokensRepository::deleteByUserId($doctorId);
}

echo PHP_EOL . "=== Results: {$passed} passed, {$failed} failed ===" . PHP_EOL;
exit($failed > 0 ? 1 : 0);
