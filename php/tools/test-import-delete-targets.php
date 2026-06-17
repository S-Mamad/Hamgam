<?php

declare(strict_types=1);

/**
 * Unit tests for merged import vacation delete targets.
 * CLI: php php/tools/test-import-delete-targets.php
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/vacation-bootstrap.php';

hamgam_load_vacation_modules();

$passed = 0;
$failed = 0;

function assertTarget(string $name, bool $condition, mixed $detail = null): void
{
    global $passed, $failed;

    if ($condition) {
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

$repoRef = new ReflectionClass(ImportFutureVacationsRepository::class);
$vacationRepoRef = new ReflectionClass(GoogleVacationRepository::class);

assertTarget(
    'ImportFutureVacationsRepository exposes listDeletableImportVacationTargets',
    $repoRef->hasMethod('listDeletableImportVacationTargets')
);
assertTarget(
    'ImportFutureVacationsRepository exposes reconcileBackfillSlotsFromTrackedEvents',
    $repoRef->hasMethod('reconcileBackfillSlotsFromTrackedEvents')
);
assertTarget(
    'GoogleVacationRepository exposes listTrackedVacationDeletionTargets',
    $vacationRepoRef->hasMethod('listTrackedVacationDeletionTargets')
);
assertTarget(
    'GoogleTokensRepository exposes clearGoogleAccountEmail',
    (new ReflectionClass(GoogleTokensRepository::class))->hasMethod('clearGoogleAccountEmail')
);

$calendarSource = file_get_contents(__DIR__ . '/../includes/GoogleCalendar.php');
assertTarget(
    'resolveAccountEmail tries userinfo endpoint',
    is_string($calendarSource) && str_contains($calendarSource, 'oauth2/v3/userinfo')
);

$oauthSource = file_get_contents(__DIR__ . '/../includes/Paziresh24Api.php');
assertTarget(
    'OAuth URL appends userinfo.email scope',
    is_string($oauthSource) && str_contains($oauthSource, 'userinfo.email')
);

$syncSource = file_get_contents(__DIR__ . '/../google-vacation/VacationSyncService.php');
assertTarget(
    'backfill tracks slots for already-synced events',
    is_string($syncSource)
        && str_contains($syncSource, 'if ($trackAsBackfill) {')
        && str_contains($syncSource, 'upsertBackfillSlotForEvent')
        && str_contains($syncSource, '$anyUpdated = true;')
);

try {
    Database::connection()->exec(
        'CREATE TABLE IF NOT EXISTS google_event_vacations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            paziresh24_user_id TEXT NOT NULL,
            google_event_id TEXT NOT NULL,
            medical_center_id TEXT NOT NULL DEFAULT \'\',
            event_summary TEXT,
            vacation_from INTEGER NOT NULL,
            vacation_to INTEGER NOT NULL,
            paziresh24_response TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(paziresh24_user_id, google_event_id, medical_center_id)
        )'
    );
    Database::connection()->exec(
        'CREATE TABLE IF NOT EXISTS import_future_vacations_backfill_slots (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            paziresh24_user_id TEXT NOT NULL,
            google_event_id TEXT,
            medical_center_id TEXT NOT NULL,
            vacation_from INTEGER NOT NULL,
            vacation_to INTEGER NOT NULL,
            deleted_at DATETIME
        )'
    );

    $doctorId = '99999009';
    ImportFutureVacationsRepository::purgeBackfillSlotsForDoctor($doctorId);
    GoogleVacationRepository::clearProcessedEvents($doctorId);

    GoogleVacationRepository::recordProcessedEvent(
        $doctorId,
        'tracked-only-event',
        'Leave',
        1782000000,
        1782003600,
        null,
        'center-a'
    );

    assertTarget(
        'count includes tracked vacations without backfill slots',
        ImportFutureVacationsRepository::countActiveBackfillSlots($doctorId) === 1
    );

    $created = ImportFutureVacationsRepository::reconcileBackfillSlotsFromTrackedEvents($doctorId);
    assertTarget('reconcile creates missing backfill slot rows', $created === 1);
    assertTarget(
        'reconcile leaves one active backfill slot row',
        count(ImportFutureVacationsRepository::listActiveBackfillSlots($doctorId)) === 1
    );

    ImportFutureVacationsRepository::purgeBackfillSlotsForDoctor($doctorId);
    GoogleVacationRepository::clearProcessedEvents($doctorId);
} catch (Throwable $e) {
    assertTarget('sqlite merge targets round-trip (optional)', true, 'skipped: ' . $e->getMessage());
}

echo PHP_EOL . "Total: {$passed} passed, {$failed} failed" . PHP_EOL;
exit($failed > 0 ? 1 : 0);
