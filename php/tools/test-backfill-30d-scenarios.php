<?php

declare(strict_types=1);

/**
 * Comprehensive 30-day backfill scenarios:
 * create via runFutureEventsBackfill → edit → delete (webhook-style payloads).
 *
 * CLI: php -c dev/php.ini php/tools/test-backfill-30d-scenarios.php
 */

require_once __DIR__ . '/../includes/Config.php';
require_once __DIR__ . '/../includes/HttpClient.php';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/Paziresh24Api.php';
require_once __DIR__ . '/../includes/GoogleTokensRepository.php';
require_once __DIR__ . '/../includes/GoogleCalendar.php';
require_once __DIR__ . '/../includes/vacation-bootstrap.php';

Config::load(__DIR__ . '/../.env.local');
date_default_timezone_set('Asia/Tehran');
ini_set('display_errors', '1');
error_reporting(E_ALL);

hamgam_load_vacation_modules();
require_once __DIR__ . '/../google-vacation/VacationSyncService.php';

$passed = 0;
$failed = 0;

function scenarioAssert(string $name, bool $ok, mixed $detail = null): void
{
    global $passed, $failed;

    if ($ok) {
        $passed++;
        echo 'PASS ' . $name . PHP_EOL;
    } else {
        $failed++;
        echo 'FAIL ' . $name . PHP_EOL;
    }

    if ($detail !== null) {
        $line = is_string($detail) ? $detail : json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        echo '  ' . $line . PHP_EOL;
    }
}

function vacationProbe(string $token, string $centerId, int $from, int $to): array
{
    $result = Paziresh24VacationApi::createVacationResult($token, $centerId, $from, $to);

    return [
        'http' => $result['http_status'],
        'success' => $result['success'],
        'book_conflict' => $result['book_conflict'],
    ];
}

function slotFree(string $token, string $centerId, int $from, int $to): bool
{
    return vacationProbe($token, $centerId, $from, $to)['success'];
}

function slotTaken(string $token, string $centerId, int $from, int $to): bool
{
    return vacationProbe($token, $centerId, $from, $to)['book_conflict'];
}

function cleanupVacation(string $token, string $centerId, int $from, int $to): void
{
    Paziresh24VacationApi::deleteVacation($token, $centerId, $from, $to);
}

function simulateWebhookSync(
    string $userId,
    array $tokenRow,
    array $event,
    array $vacationCenters,
    string $hamdastToken
): string {
    return VacationSyncService::syncSingleEvent(
        $userId,
        $tokenRow,
        $event,
        true,
        $vacationCenters,
        $hamdastToken
    );
}

$userId = '23489442';
$refreshToken = '1//0cEllj-LXNWLeCgYIARAAGAwSNwF-L9IrrOV5VGjrKcWxWITEkG_62M9KW9ca5AGe7gqH2eqsflvQ-YLhrDsNrrRPVzLduAaL8sg';
$hamdastToken = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzY29wZSI6WyJwcm92aWRlci5hcHBvaW50bWVudC5yZWFkIiwicHJvdmlkZXIuYXBwb2ludG1lbnQud2ViaG9vayIsInByb3ZpZGVyLm1hbmFnZW1lbnQucmVhZCIsInByb3ZpZGVyLm1hbmFnZW1lbnQud3JpdGUiLCJwcm92aWRlci5wcm9maWxlLnJlYWQiXSwiaXNzIjoiaGFtZGFzdCIsInN1YiI6IjIzNDg5NDQyIiwiYXVkIjoiZnppeGpheTRpNThkZGFjIiwiaWF0IjoxNzgxNDM5MjM2fQ.rSpaevPNorVA8Hwkks8sxQwp_Z2LbQEVpVCfFOXSKLc';
$centerId = 'e5d0fa25-a8e1-40db-a957-97aa0af1c0ee';

$stamp = date('His');
$googleAccess = '';
$eventAId = '';
$eventBId = '';
$eventAFrom = 0;
$eventATo = 0;
$eventAEditFrom = 0;
$eventAEditTo = 0;
$allDayId = '';
$allDayFrom = 0;
$allDayTo = 0;
$allDayEditFrom = 0;
$allDayEditTo = 0;

echo '=== 30-day backfill scenarios ===' . PHP_EOL;

try {
    GoogleTokensRepository::upsertHamdastAccessToken($userId, $hamdastToken);
    $centersJson = json_encode(['mode' => 'selected', 'center_ids' => [$centerId]], JSON_UNESCAPED_UNICODE);

    $pdo = Database::connection();
    $pdo->prepare(
        'UPDATE google_tokens SET
            google_refresh_token = :rt,
            center_id = :center,
            auto_vacation = 1,
            import_future_vacations = 1,
            import_future_vacations_done_at = NULL,
            import_future_vacations_window_end = NULL,
            cancel_appointment_on_event_delete = 1,
            cancel_conflicting_appointments = 1,
            vacation_sync_centers = :centers
         WHERE paziresh24_user_id = :uid'
    )->execute([
        'rt' => $refreshToken,
        'center' => $centerId,
        'centers' => $centersJson,
        'uid' => $userId,
    ]);

    $tokenRow = GoogleTokensRepository::findByUserId($userId);
    scenarioAssert('token row seeded for backfill', $tokenRow !== null);

    $googleTokenData = GoogleCalendar::refreshAccessToken($refreshToken);
    $googleAccess = is_array($googleTokenData) ? ($googleTokenData['access_token'] ?? '') : '';
    scenarioAssert('Google access token', is_string($googleAccess) && $googleAccess !== '');

    $vacationCenter = Paziresh24VacationApi::resolveVacationCenter($hamdastToken);
    $vacationCenters = $vacationCenter !== null
        ? [$vacationCenter]
        : [[
            'medical_center_id' => $centerId,
            'user_center_id' => null,
            'name' => 'Fallback center',
        ]];
    $activeCenterId = $vacationCenters[0]['medical_center_id'];

    $dayA = (new DateTimeImmutable('+' . (5 + ((int) $stamp % 3)) . ' days ' . (10 + ((int) $stamp % 5)) . ':15:00', new DateTimeZone('Asia/Tehran')));
    $endA = $dayA->modify('+60 minutes');

    $createdA = GoogleCalendar::createEventReturningBody($googleAccess, [
        'summary' => '30d backfill A ' . $stamp,
        'description' => 'scenario test',
        'start' => ['dateTime' => $dayA->format('Y-m-d\TH:i:s'), 'timeZone' => 'Asia/Tehran'],
        'end' => ['dateTime' => $endA->format('Y-m-d\TH:i:s'), 'timeZone' => 'Asia/Tehran'],
    ]);
    scenarioAssert('create personal event A in 30d window', is_array($createdA));

    $eventAId = is_string($createdA['id'] ?? null) ? $createdA['id'] : '';
    $parsedA = GoogleEventParser::parseEvent($createdA);
    scenarioAssert('parse event A', $parsedA !== null && $eventAId !== '');
    $eventAFrom = $parsedA['start_ts'];
    $eventATo = $parsedA['end_ts'];
    $eventASummary = '30d backfill A ' . $stamp;

    GoogleVacationRepository::removeProcessedEvent($userId, $eventAId);

    $backfill1 = VacationSyncService::runFutureEventsBackfill($userId, $hamdastToken, true);
    scenarioAssert(
        'runFutureEventsBackfill imports event A',
        ($backfill1['ran'] ?? false) === true && ($backfill1['imported'] ?? 0) >= 1,
        $backfill1
    );

    $trackedA = GoogleVacationRepository::findProcessedEvent($userId, $eventAId);
    scenarioAssert('event A tracked after backfill', $trackedA !== null);
    $eventAFrom = (int) ($trackedA['vacation_from'] ?? $eventAFrom);
    $eventATo = (int) ($trackedA['vacation_to'] ?? $eventATo);
    $probeCenterId = is_string($trackedA['medical_center_id'] ?? null) && $trackedA['medical_center_id'] !== ''
        ? (string) $trackedA['medical_center_id']
        : $activeCenterId;
    scenarioAssert(
        'backfill stored vacation timestamps for event A',
        $trackedA !== null && $eventAFrom > 0 && $eventATo > $eventAFrom
    );

    $backfill2 = VacationSyncService::runFutureEventsBackfill($userId, $hamdastToken, true);
    scenarioAssert(
        're-backfill keeps single tracking row for event A',
        ($backfill2['ran'] ?? false) === true
            && GoogleVacationRepository::hasProcessedEvent($userId, $eventAId)
            && ($backfill2['imported'] ?? -1) === 0,
        $backfill2
    );

    $editDayA = $dayA->modify('+2 hours');
    $editEndA = $endA->modify('+2 hours');
    $eventAEditFrom = $eventAFrom + 7200;
    $eventAEditTo = $eventATo + 7200;

    $editedA = array_merge($createdA, [
        'summary' => '30d backfill A edited ' . $stamp,
        'start' => ['dateTime' => $editDayA->format('Y-m-d\TH:i:s'), 'timeZone' => 'Asia/Tehran'],
        'end' => ['dateTime' => $editEndA->format('Y-m-d\TH:i:s'), 'timeZone' => 'Asia/Tehran'],
        'updated' => gmdate('Y-m-d\TH:i:s\Z'),
    ]);

    $editResult = simulateWebhookSync($userId, $tokenRow ?? [], $editedA, $vacationCenters, $hamdastToken);
    scenarioAssert('webhook edit updates vacation time', $editResult === 'updated', 'result=' . $editResult);
    $trackedAfterEdit = GoogleVacationRepository::findProcessedEvent($userId, $eventAId);
    scenarioAssert(
        'new slot tracked in DB after edit',
        $trackedAfterEdit !== null
            && (int) ($trackedAfterEdit['vacation_from'] ?? 0) === $eventAEditFrom
            && (int) ($trackedAfterEdit['vacation_to'] ?? 0) === $eventAEditTo
    );

    $summaryOnly = array_merge($editedA, [
        'summary' => '30d backfill A summary only ' . $stamp,
        'updated' => gmdate('Y-m-d\TH:i:s\Z', time() + 5),
    ]);
    $summaryResult = simulateWebhookSync($userId, $tokenRow ?? [], $summaryOnly, $vacationCenters, $hamdastToken);
    $trackedSummary = GoogleVacationRepository::findProcessedEvent($userId, $eventAId);
    scenarioAssert(
        'summary-only webhook keeps tracking and updates summary',
        $summaryResult === 'skipped'
            && $trackedSummary !== null
            && ($trackedSummary['event_summary'] ?? '') === '30d backfill A summary only ' . $stamp,
        'result=' . $summaryResult
    );

    $deleteCancelled = simulateWebhookSync(
        $userId,
        $tokenRow ?? [],
        ['id' => $eventAId, 'status' => 'cancelled'],
        $vacationCenters,
        $hamdastToken
    );
    scenarioAssert('cancelled status removes tracking', !GoogleVacationRepository::hasProcessedEvent($userId, $eventAId));
    scenarioAssert(
        'cancelled status removes Paziresh24 vacation',
        $deleteCancelled === 'skipped' && slotFree($hamdastToken, $probeCenterId, $eventAEditFrom, $eventAEditTo),
        'result=' . $deleteCancelled
    );

    $dayB = (new DateTimeImmutable('+6 days 14:00:00', new DateTimeZone('Asia/Tehran')));
    $endB = $dayB->modify('+45 minutes');
    $createdB = GoogleCalendar::createEventReturningBody($googleAccess, [
        'summary' => '30d backfill B ' . $stamp,
        'start' => ['dateTime' => $dayB->format('Y-m-d\TH:i:s'), 'timeZone' => 'Asia/Tehran'],
        'end' => ['dateTime' => $endB->format('Y-m-d\TH:i:s'), 'timeZone' => 'Asia/Tehran'],
    ]);
    $eventBId = is_string($createdB['id'] ?? null) ? $createdB['id'] : '';
    $parsedB = GoogleEventParser::parseEvent($createdB);
    scenarioAssert('create personal event B', $parsedB !== null && $eventBId !== '');

    GoogleVacationRepository::removeProcessedEvent($userId, $eventBId);
    $createB = simulateWebhookSync($userId, $tokenRow ?? [], $createdB, $vacationCenters, $hamdastToken);
    scenarioAssert('manual sync creates vacation B', $createB === 'created', 'result=' . $createB);

    $bFrom = $parsedB['start_ts'];
    $bTo = $parsedB['end_ts'];
    $trackedB = GoogleVacationRepository::findProcessedEvent($userId, $eventBId);
    $probeCenterB = is_string($trackedB['medical_center_id'] ?? null) && $trackedB['medical_center_id'] !== ''
        ? (string) $trackedB['medical_center_id']
        : $activeCenterId;

    $deleteTombstone = simulateWebhookSync(
        $userId,
        $tokenRow ?? [],
        ['id' => $eventBId, 'deleted' => true],
        $vacationCenters,
        $hamdastToken
    );
    scenarioAssert('deleted:true tombstone removes tracking', !GoogleVacationRepository::hasProcessedEvent($userId, $eventBId));
    scenarioAssert(
        'deleted:true tombstone sync completes',
        $deleteTombstone === 'skipped',
        'result=' . $deleteTombstone
    );

    $allDayStart = (new DateTimeImmutable('+7 days', new DateTimeZone('Asia/Tehran')))->format('Y-m-d');
    $allDayEnd = (new DateTimeImmutable('+8 days', new DateTimeZone('Asia/Tehran')))->format('Y-m-d');
    $createdAllDay = GoogleCalendar::createEventReturningBody($googleAccess, [
        'summary' => '30d all-day ' . $stamp,
        'start' => ['date' => $allDayStart],
        'end' => ['date' => $allDayEnd],
    ]);
    $allDayId = is_string($createdAllDay['id'] ?? null) ? $createdAllDay['id'] : '';
    $parsedAllDay = GoogleEventParser::parseEvent($createdAllDay);
    scenarioAssert('create all-day event in 30d window', $parsedAllDay !== null && $allDayId !== '');
    $allDayFrom = $parsedAllDay['start_ts'];
    $allDayTo = $parsedAllDay['end_ts'];

    GoogleVacationRepository::removeProcessedEvent($userId, $allDayId);
    $allDayCreate = simulateWebhookSync($userId, $tokenRow ?? [], $createdAllDay, $vacationCenters, $hamdastToken);
    scenarioAssert('all-day event creates vacation', $allDayCreate === 'created', 'result=' . $allDayCreate);
    $trackedAllDay = GoogleVacationRepository::findProcessedEvent($userId, $allDayId);
    $probeCenterAllDay = is_string($trackedAllDay['medical_center_id'] ?? null) && $trackedAllDay['medical_center_id'] !== ''
        ? (string) $trackedAllDay['medical_center_id']
        : $activeCenterId;

    scenarioAssert(
        'all-day vacation tracked in DB',
        $trackedAllDay !== null
            && (int) ($trackedAllDay['vacation_from'] ?? 0) === $allDayFrom
            && (int) ($trackedAllDay['vacation_to'] ?? 0) === $allDayTo
    );

    $allDayEditEnd = (new DateTimeImmutable('+9 days', new DateTimeZone('Asia/Tehran')))->format('Y-m-d');
    $allDayEdited = array_merge($createdAllDay, [
        'end' => ['date' => $allDayEditEnd],
        'updated' => gmdate('Y-m-d\TH:i:s\Z'),
    ]);
    $parsedAllDayEdit = GoogleEventParser::parseEvent($allDayEdited);
    $allDayEditFrom = $parsedAllDayEdit['start_ts'] ?? 0;
    $allDayEditTo = $parsedAllDayEdit['end_ts'] ?? 0;

    $allDayUpdate = simulateWebhookSync($userId, $tokenRow ?? [], $allDayEdited, $vacationCenters, $hamdastToken);
    scenarioAssert('all-day edit updates vacation', $allDayUpdate === 'updated', 'result=' . $allDayUpdate);
    $trackedAllDayAfterEdit = GoogleVacationRepository::findProcessedEvent($userId, $allDayId);
    scenarioAssert(
        'all-day DB timestamps updated after edit',
        $trackedAllDayAfterEdit !== null
            && (int) ($trackedAllDayAfterEdit['vacation_from'] ?? 0) === $allDayEditFrom
            && (int) ($trackedAllDayAfterEdit['vacation_to'] ?? 0) === $allDayEditTo
    );

    simulateWebhookSync(
        $userId,
        $tokenRow ?? [],
        ['id' => $allDayId, 'deleted' => true],
        $vacationCenters,
        $hamdastToken
    );
    scenarioAssert('all-day delete removes tracking', !GoogleVacationRepository::hasProcessedEvent($userId, $allDayId));
    scenarioAssert(
        'all-day delete frees Paziresh24 slot',
        slotFree($hamdastToken, $probeCenterAllDay, $allDayEditFrom, $allDayEditTo)
    );

    $hamgamAppt = [
        'id' => 'appt-30d-' . $stamp,
        'summary' => 'نوبت پذیرش 24',
        'status' => 'confirmed',
        'description' => 'hamgam_book_id: bc9437f4-67d5-11f1-8fe5-b6c09fdc72a4',
        'start' => ['dateTime' => $dayB->format('Y-m-d\TH:i:s'), 'timeZone' => 'Asia/Tehran'],
        'end' => ['dateTime' => $endB->format('Y-m-d\TH:i:s'), 'timeZone' => 'Asia/Tehran'],
    ];
    $apptSkip = simulateWebhookSync($userId, $tokenRow ?? [], $hamgamAppt, $vacationCenters, $hamdastToken);
    scenarioAssert(
        'hamgam appointment never becomes vacation during 30d sync',
        $apptSkip === 'skipped' && !GoogleVacationRepository::hasProcessedEvent($userId, 'appt-30d-' . $stamp),
        'result=' . $apptSkip
    );
} catch (Throwable $e) {
    scenarioAssert('scenario runner', false, $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
} finally {
    if ($googleAccess !== '' && $eventAId !== '') {
        GoogleCalendar::deleteEvent($googleAccess, $eventAId);
    }
    if ($googleAccess !== '' && $eventBId !== '') {
        GoogleCalendar::deleteEvent($googleAccess, $eventBId);
    }
    if ($googleAccess !== '' && $allDayId !== '') {
        GoogleCalendar::deleteEvent($googleAccess, $allDayId);
    }

    foreach (
        [
            [$eventAFrom, $eventATo],
            [$eventAEditFrom, $eventAEditTo],
            [$allDayFrom, $allDayTo],
            [$allDayEditFrom, $allDayEditTo],
        ] as [$from, $to]
    ) {
        if ($from > 0 && $to > $from) {
            cleanupVacation($hamdastToken, $centerId, $from, $to);
        }
    }

    if ($eventAId !== '') {
        GoogleVacationRepository::removeProcessedEvent($userId, $eventAId);
    }
    if ($eventBId !== '') {
        GoogleVacationRepository::removeProcessedEvent($userId, $eventBId);
    }
    if ($allDayId !== '') {
        GoogleVacationRepository::removeProcessedEvent($userId, $allDayId);
    }
    GoogleVacationRepository::removeProcessedEvent($userId, 'appt-30d-' . $stamp);
}

echo PHP_EOL . "=== Scenario results: {$passed} passed, {$failed} failed ===" . PHP_EOL;
exit($failed > 0 ? 1 : 0);
