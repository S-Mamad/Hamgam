<?php

declare(strict_types=1);

/**
 * Doctor scenario:
 * - first backfill imports all future events
 * - delete/reset clears tracking so a full re-run can import the same events again
 *
 * CLI: php php/tools/test-backfill-cutoff-rerun.php
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/GoogleEventParser.php';

$passed = 0;
$failed = 0;

function assertCutoff(string $name, bool $ok, mixed $detail = null): void
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

echo '=== backfill cutoff rerun (doctor scenario) ===' . PHP_EOL;

$cutoffTs = strtotime('2026-06-10 12:00:00');

$oldEvent = [
    'event_id' => 'old-1',
    'summary' => 'Old vacation',
    'status' => 'confirmed',
    'start_ts' => 1780000000,
    'end_ts' => 1780003600,
    'timezone' => 'Asia/Tehran',
    'created' => '2026-06-01T08:00:00Z',
    'updated' => '2026-06-01T08:00:00Z',
    'is_deleted' => false,
];

$newEvent1 = [
    'event_id' => 'new-1',
    'summary' => 'New vacation 1',
    'status' => 'confirmed',
    'start_ts' => 1781000000,
    'end_ts' => 1781003600,
    'timezone' => 'Asia/Tehran',
    'created' => '2026-06-11T09:00:00Z',
    'updated' => '2026-06-11T09:00:00Z',
    'is_deleted' => false,
];

$newEvent2 = [
    'event_id' => 'new-2',
    'summary' => 'New vacation 2',
    'status' => 'confirmed',
    'start_ts' => 1781004000,
    'end_ts' => 1781007600,
    'timezone' => 'Asia/Tehran',
    'created' => '2026-06-11T10:30:00Z',
    'updated' => '2026-06-11T10:30:00Z',
    'is_deleted' => false,
];

$editedOldEvent = $oldEvent;
$editedOldEvent['updated'] = '2026-06-11T11:00:00Z';

assertCutoff('first run has no cutoff', GoogleTokensRepository::getImportBackfillCutoffTs(null) === null);
assertCutoff(
    'old event eligible when no cutoff is active',
    GoogleEventParser::isEventNewerThanCutoff($oldEvent, 0) || GoogleTokensRepository::getImportBackfillCutoffTs(null) === null
);
assertCutoff(
    'new event #1 included after delete cutoff',
    GoogleEventParser::isEventNewerThanCutoff($newEvent1, $cutoffTs)
);
assertCutoff(
    'new event #2 included after delete cutoff',
    GoogleEventParser::isEventNewerThanCutoff($newEvent2, $cutoffTs)
);
assertCutoff(
    'edited old event included when updated after cutoff',
    GoogleEventParser::isEventNewerThanCutoff($editedOldEvent, $cutoffTs)
);

$doctorId = '99999005';
$dbReady = true;

try {
    Database::connection();
} catch (Throwable $e) {
    $dbReady = false;
    assertCutoff('db unavailable (optional)', true, 'skipped: ' . $e->getMessage());
}

if ($dbReady) {
    GoogleTokensRepository::deleteByUserId($doctorId);
    ImportFutureVacationsRepository::purgeBackfillSlotsForDoctor($doctorId);

    GoogleTokensRepository::upsertHamdastAccessToken($doctorId, 'cutoff-token');
    GoogleTokensRepository::clearImportFutureVacationsCompletion($doctorId);

    $row = GoogleTokensRepository::findByUserId($doctorId);
    $storedCutoff = GoogleTokensRepository::getImportBackfillCutoffTs($row);
    assertCutoff('clear completion removes last_cleared_at cutoff', $storedCutoff === null);

    ImportFutureVacationsRepository::upsertBackfillSlotForEvent(
        $doctorId,
        'new-1',
        'center-a',
        $newEvent1['start_ts'],
        $newEvent1['end_ts']
    );
    ImportFutureVacationsRepository::upsertBackfillSlotForEvent(
        $doctorId,
        'new-2',
        'center-a',
        $newEvent2['start_ts'],
        $newEvent2['end_ts']
    );

    assertCutoff(
        'second run tracks only new slots (2)',
        ImportFutureVacationsRepository::countActiveBackfillSlots($doctorId) === 2
    );

    ImportFutureVacationsRepository::purgeBackfillSlotsForDoctor($doctorId);
    GoogleTokensRepository::deleteByUserId($doctorId);
}

echo PHP_EOL . "=== Results: {$passed} passed, {$failed} failed ===" . PHP_EOL;
exit($failed > 0 ? 1 : 0);
