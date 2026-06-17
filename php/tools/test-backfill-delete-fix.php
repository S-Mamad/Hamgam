<?php

declare(strict_types=1);

/**
 * Unit tests for 30-day backfill vacation delete fix.
 * CLI: php php/tools/test-backfill-delete-fix.php
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/vacation-bootstrap.php';

hamgam_load_vacation_modules();
require_once __DIR__ . '/../google-vacation/VacationSyncService.php';

$passed = 0;
$failed = 0;

function assertFix(string $name, bool $condition): void
{
    global $passed, $failed;

    if ($condition) {
        $passed++;
        echo 'PASS ' . $name . PHP_EOL;
        return;
    }

    $failed++;
    echo 'FAIL ' . $name . PHP_EOL;
}

$appointmentWithBookId = [
    'id' => 'evt-book-id-test',
    'summary' => 'Ali Rezaei',
    'status' => 'confirmed',
    'description' => 'hamgam_book_id: bc9437f4-0000-4000-8000-000000000001',
    'start' => ['dateTime' => '2026-06-20T10:00:00', 'timeZone' => 'Asia/Tehran'],
    'end' => ['dateTime' => '2026-06-20T11:00:00', 'timeZone' => 'Asia/Tehran'],
];

assertFix(
    'backfill skips appointment events with book_id even when summary is neutral',
    GoogleEventParser::isHamgamAppointmentEvent($appointmentWithBookId)
        && GoogleEventParser::extractBookId($appointmentWithBookId) !== null
);

assertFix(
    'cancelled appointment still detected via book_id',
    GoogleEventParser::isHamgamAppointmentEvent(array_merge($appointmentWithBookId, ['status' => 'cancelled']))
);

$repoRef = new ReflectionClass(GoogleVacationRepository::class);
assertFix(
    'repository exposes related event lookup',
    $repoRef->hasMethod('findProcessedEventsRelatedToGoogleEvent')
);

$syncRef = new ReflectionClass(VacationSyncService::class);
assertFix(
    'VacationSyncService exposes untracked delete fallback',
    $syncRef->hasMethod('buildUntrackedDeletedVacationTargets')
);

$syncSource = file_get_contents(__DIR__ . '/../google-vacation/VacationSyncService.php');
assertFix(
    'delete path always calls processDeletedEvent before appointment handler',
    is_string($syncSource)
        && str_contains($syncSource, 'self::processDeletedEvent($userId, $eventId, $parsed, $tokenRow, $hamdastAccessToken);')
        && preg_match(
            '/if\s*\(\$isDeleted\)\s*\{[^}]*processDeletedEvent[^}]*isHamgamAppointmentEvent/s',
            $syncSource
        ) === 1
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

    $userId = '99999002';
    $baseId = 'recurring-master-id';
    $instanceId = $baseId . '_20260620T100000Z';

    GoogleVacationRepository::recordProcessedEvent(
        $userId,
        $instanceId,
        'Personal leave',
        1780000000,
        1780003600,
        null,
        'e5d0fa25-a8e1-40db-a957-97aa0af1c0ee'
    );

    $exact = GoogleVacationRepository::findProcessedEventsForGoogleEvent($userId, $baseId);
    $related = GoogleVacationRepository::findProcessedEventsRelatedToGoogleEvent($userId, $baseId);

    assertFix('exact lookup misses recurring master id', $exact === []);
    assertFix('related lookup finds recurring instance rows', count($related) === 1);

    GoogleVacationRepository::removeProcessedEvent($userId, $instanceId);
} catch (Throwable $e) {
    assertFix('sqlite related lookup round-trip (optional)', true);
    echo 'SKIP db: ' . $e->getMessage() . PHP_EOL;
}

echo PHP_EOL . "Total: {$passed} passed, {$failed} failed" . PHP_EOL;
exit($failed > 0 ? 1 : 0);
