<?php

declare(strict_types=1);

/**
 * E2E: backfill-style vacation create → update → delete (local code, real APIs).
 *
 * CLI: php -c dev/php.ini php/tools/test-backfill-lifecycle-e2e.php
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

function e2eAssert(string $name, bool $ok, mixed $detail = null): void
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

function vacationPostResult(string $token, string $centerId, int $from, int $to): array
{
    $result = Paziresh24VacationApi::createVacationResult($token, $centerId, $from, $to);

    return [
        'http' => $result['http_status'],
        'success' => $result['success'],
        'book_conflict' => $result['book_conflict'],
    ];
}

function vacationSlotTaken(string $token, string $centerId, int $from, int $to): bool
{
    $r = vacationPostResult($token, $centerId, $from, $to);

    return $r['book_conflict'];
}

function vacationSlotFree(string $token, string $centerId, int $from, int $to): bool
{
    $r = vacationPostResult($token, $centerId, $from, $to);

    return $r['success'];
}

function cleanupVacation(string $token, string $centerId, int $from, int $to): void
{
    Paziresh24VacationApi::deleteVacation($token, $centerId, $from, $to);
}

$userId = '23489442';
$refreshToken = '1//0cEllj-LXNWLeCgYIARAAGAwSNwF-L9IrrOV5VGjrKcWxWITEkG_62M9KW9ca5AGe7gqH2eqsflvQ-YLhrDsNrrRPVzLduAaL8sg';
$hamdastToken = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzY29wZSI6WyJwcm92aWRlci5hcHBvaW50bWVudC5yZWFkIiwicHJvdmlkZXIuYXBwb2ludG1lbnQud2ViaG9vayIsInByb3ZpZGVyLm1hbmFnZW1lbnQucmVhZCIsInByb3ZpZGVyLm1hbmFnZW1lbnQud3JpdGUiLCJwcm92aWRlci5wcm9maWxlLnJlYWQiXSwiaXNzIjoiaGFtZGFzdCIsInN1YiI6IjIzNDg5NDQyIiwiYXVkIjoiZnppeGpheTRpNThkZGFjIiwiaWF0IjoxNzgxNDM5MjM2fQ.rSpaevPNorVA8Hwkks8sxQwp_Z2LbQEVpVCfFOXSKLc';
$centerId = 'e5d0fa25-a8e1-40db-a957-97aa0af1c0ee';

$googleEventId = null;
$fromTs = 0;
$toTs = 0;
$newFromTs = 0;
$newToTs = 0;
$stamp = '';

echo '=== Backfill lifecycle E2E ===' . PHP_EOL;

try {
    // Seed local sqlite token row for tracking
    GoogleTokensRepository::upsertHamdastAccessToken($userId, $hamdastToken);
    $pdo = Database::connection();
    $pdo->prepare(
        'UPDATE google_tokens SET
            google_refresh_token = :rt,
            center_id = :center,
            auto_vacation = 1,
            cancel_appointment_on_event_delete = 1,
            cancel_conflicting_appointments = 1
         WHERE paziresh24_user_id = :uid'
    )->execute([
        'rt' => $refreshToken,
        'center' => $centerId,
        'uid' => $userId,
    ]);

    $tokenRow = GoogleTokensRepository::findByUserId($userId);
    e2eAssert('local token row ready', $tokenRow !== null);

    $googleTokenData = GoogleCalendar::refreshAccessToken($refreshToken);
    $googleAccess = is_array($googleTokenData) ? ($googleTokenData['access_token'] ?? '') : '';
    e2eAssert('Google token refresh', is_string($googleAccess) && $googleAccess !== '');

    $vacationCenter = Paziresh24VacationApi::resolveVacationCenter($hamdastToken);
    $vacationCenters = $vacationCenter !== null
        ? [$vacationCenter]
        : [[
            'medical_center_id' => $centerId,
            'user_center_id' => null,
            'name' => 'Fallback test center',
        ]];
    e2eAssert('Paziresh24 center resolve', $vacationCenter !== null || $centerId !== '', 'using center_id=' . $centerId);

    $testDay = (new DateTimeImmutable('+4 days 16:30:00', new DateTimeZone('Asia/Tehran')));
    $testEnd = $testDay->modify('+90 minutes');
    $stamp = date('His');

    $created = GoogleCalendar::createEventReturningBody($googleAccess, [
        'summary' => 'Backfill E2E test ' . $stamp,
        'description' => 'Auto test - safe to delete',
        'start' => [
            'dateTime' => $testDay->format('Y-m-d\TH:i:s'),
            'timeZone' => 'Asia/Tehran',
        ],
        'end' => [
            'dateTime' => $testEnd->format('Y-m-d\TH:i:s'),
            'timeZone' => 'Asia/Tehran',
        ],
    ]);
    e2eAssert('Create future Google event', is_array($created));

    $googleEventId = is_string($created['id'] ?? null) ? $created['id'] : '';
    $parsed = GoogleEventParser::parseEvent($created);
    e2eAssert('Parse created event', $parsed !== null && $googleEventId !== '');

    $fromTs = $parsed['start_ts'];
    $toTs = $parsed['end_ts'];

    // --- Step 1: simulate 30-day backfill create ---
    $createResult = VacationSyncService::syncSingleEvent(
        $userId,
        $tokenRow ?? [],
        $created,
        true,
        $vacationCenters,
        $hamdastToken
    );
    e2eAssert('Backfill sync creates vacation', $createResult === 'created', 'result=' . $createResult);

    $tracked = GoogleVacationRepository::findProcessedEvent($userId, $googleEventId);
    e2eAssert('Event tracked in DB after backfill', $tracked !== null);

    e2eAssert(
        'Paziresh24 vacation registered after backfill',
        $createResult === 'created' && $tracked !== null
    );

    // --- Step 2: doctor edits event time (+1 hour) ---
    $newDay = $testDay->modify('+1 hour');
    $newEnd = $testEnd->modify('+1 hour');
    $newFromTs = $parsed['start_ts'] + 3600;
    $newToTs = $parsed['end_ts'] + 3600;

    $updatedEvent = array_merge($created, [
        'start' => [
            'dateTime' => $newDay->format('Y-m-d\TH:i:s'),
            'timeZone' => 'Asia/Tehran',
        ],
        'end' => [
            'dateTime' => $newEnd->format('Y-m-d\TH:i:s'),
            'timeZone' => 'Asia/Tehran',
        ],
        'updated' => gmdate('Y-m-d\TH:i:s\Z'),
    ]);

    $updateResult = VacationSyncService::syncSingleEvent(
        $userId,
        $tokenRow ?? [],
        $updatedEvent,
        true,
        $vacationCenters,
        $hamdastToken
    );
    e2eAssert('Edit sync updates vacation', $updateResult === 'updated', 'result=' . $updateResult);

    $trackedAfterUpdate = GoogleVacationRepository::findProcessedEvent($userId, $googleEventId);

    e2eAssert(
        'Old slot free after edit',
        vacationSlotFree($hamdastToken, $centerId, $fromTs, $toTs),
        vacationPostResult($hamdastToken, $centerId, $fromTs, $toTs)
    );
    e2eAssert(
        'New slot registered after edit',
        $updateResult === 'updated'
            && $trackedAfterUpdate !== null
            && (int) ($trackedAfterUpdate['vacation_from'] ?? 0) === $newFromTs,
        vacationPostResult($hamdastToken, $centerId, $newFromTs, $newToTs)
    );

    e2eAssert(
        'DB timestamps updated after edit',
        $trackedAfterUpdate !== null
            && (int) ($trackedAfterUpdate['vacation_from'] ?? 0) === $newFromTs
            && (int) ($trackedAfterUpdate['vacation_to'] ?? 0) === $newToTs
    );

    // --- Step 3: doctor deletes event ---
    $deletedPayload = [
        'id' => $googleEventId,
        'status' => 'cancelled',
    ];

    VacationSyncService::syncSingleEvent(
        $userId,
        $tokenRow ?? [],
        $deletedPayload,
        true,
        $vacationCenters,
        $hamdastToken
    );

    e2eAssert(
        'Tracking removed after delete',
        !GoogleVacationRepository::hasProcessedEvent($userId, $googleEventId)
    );
    e2eAssert(
        'Paziresh24 vacation removed after delete',
        vacationSlotFree($hamdastToken, $centerId, $newFromTs, $newToTs),
        vacationPostResult($hamdastToken, $centerId, $newFromTs, $newToTs)
    );

    // --- Step 4: legacy case — appointment event with book_id had vacation tracked ---
    $legacyEventId = 'legacy-backfill-' . $stamp;
    $legacyFrom = $fromTs + 7200;
    $legacyTo = $toTs + 7200;
    $fakeBookId = 'bc9437f4-67d5-11f1-8fe5-b6c09fdc72a4';

    GoogleVacationRepository::recordProcessedEvent(
        $userId,
        $legacyEventId,
        'Patient Name Only',
        $legacyFrom,
        $legacyTo,
        null,
        $centerId
    );
    cleanupVacation($hamdastToken, $centerId, $legacyFrom, $legacyTo);
    Paziresh24VacationApi::createVacation($hamdastToken, $centerId, $legacyFrom, $legacyTo);

    $legacyDeleted = [
        'id' => $legacyEventId,
        'status' => 'cancelled',
        'summary' => 'Patient Name Only',
        'description' => 'hamgam_book_id: ' . $fakeBookId,
    ];

    VacationSyncService::syncSingleEvent(
        $userId,
        $tokenRow ?? [],
        $legacyDeleted,
        true,
        $vacationCenters,
        $hamdastToken
    );

    e2eAssert(
        'Legacy appointment+vacation: tracking cleared on delete',
        !GoogleVacationRepository::hasProcessedEvent($userId, $legacyEventId)
    );
    e2eAssert(
        'Legacy appointment+vacation: Paziresh24 vacation removed',
        vacationSlotFree($hamdastToken, $centerId, $legacyFrom, $legacyTo),
        vacationPostResult($hamdastToken, $centerId, $legacyFrom, $legacyTo)
    );

    // --- Step 5: backfill must skip appointment events with book_id ---
    $appointmentEvent = [
        'id' => 'appt-skip-' . $stamp,
        'summary' => 'Only Patient Name',
        'status' => 'confirmed',
        'description' => 'hamgam_book_id: ' . $fakeBookId,
        'start' => [
            'dateTime' => $testDay->modify('+2 hours')->format('Y-m-d\TH:i:s'),
            'timeZone' => 'Asia/Tehran',
        ],
        'end' => [
            'dateTime' => $testEnd->modify('+2 hours')->format('Y-m-d\TH:i:s'),
            'timeZone' => 'Asia/Tehran',
        ],
    ];
    $skipResult = VacationSyncService::syncSingleEvent(
        $userId,
        $tokenRow ?? [],
        $appointmentEvent,
        true,
        $vacationCenters,
        $hamdastToken
    );
    e2eAssert(
        'Backfill skips appointment events (book_id)',
        $skipResult === 'skipped' && !GoogleVacationRepository::hasProcessedEvent($userId, 'appt-skip-' . $stamp),
        'result=' . $skipResult
    );
} catch (Throwable $e) {
    e2eAssert('E2E runner', false, $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
} finally {
    if ($googleEventId !== null && $googleEventId !== '' && isset($googleAccess) && is_string($googleAccess)) {
        GoogleCalendar::deleteEvent($googleAccess, $googleEventId);
    }
    if ($newFromTs > 0 && $newToTs > 0) {
        cleanupVacation($hamdastToken, $centerId, $newFromTs, $newToTs);
    }
    if ($fromTs > 0 && $toTs > 0) {
        cleanupVacation($hamdastToken, $centerId, $fromTs, $toTs);
    }
    $legacyFromCleanup = ($fromTs > 0) ? $fromTs + 7200 : 0;
    $legacyToCleanup = ($toTs > 0) ? $toTs + 7200 : 0;
    if ($legacyFromCleanup > 0 && $legacyToCleanup > 0) {
        cleanupVacation($hamdastToken, $centerId, $legacyFromCleanup, $legacyToCleanup);
    }
    GoogleVacationRepository::removeProcessedEvent($userId, 'appt-skip-' . ($stamp ?? ''));
}

echo PHP_EOL . "=== E2E Results: {$passed} passed, {$failed} failed ===" . PHP_EOL;
exit($failed > 0 ? 1 : 0);
