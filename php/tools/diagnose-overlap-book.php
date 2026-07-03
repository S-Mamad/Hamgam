<?php

declare(strict_types=1);

/**
 * Diagnose overlap detection + reschedule readiness for a book_id.
 * CLI: php -c dev/php.ini php/tools/diagnose-overlap-book.php USER_ID BOOK_ID [vacation_from] [vacation_to]
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/vacation-bootstrap.php';

hamgam_load_vacation_modules();
require_once __DIR__ . '/../google-vacation/VacationSyncService.php';

$userId = GoogleTokensRepository::normalizeUserId($argv[1] ?? '');
$bookId = trim($argv[2] ?? '');
$vacationFrom = isset($argv[3]) ? (int) $argv[3] : 0;
$vacationTo = isset($argv[4]) ? (int) $argv[4] : 0;

if ($userId === '' || $bookId === '') {
    fwrite(STDERR, "Usage: diagnose-overlap-book.php USER_ID BOOK_ID [vacation_from] [vacation_to]\n");
    exit(1);
}

$tokenRow = GoogleTokensRepository::findByUserId($userId);
if ($tokenRow === null) {
    fwrite(STDERR, "user not found\n");
    exit(1);
}

$hamdast = (string) ($tokenRow['hamdast_access_token'] ?? '');
$refresh = (string) ($tokenRow['google_refresh_token'] ?? '');
$googleData = GoogleCalendar::refreshAccessToken($refresh);
$googleAccess = is_array($googleData) ? (string) ($googleData['access_token'] ?? '') : '';

$appointment = GoogleCalendar::getAppointment($bookId, $hamdast);
$moveRange = Paziresh24AppointmentApi::resolveMoveRange($hamdast, $bookId, 0, 0);
$calendarEvents = GoogleCalendar::findEventsByBookId($googleAccess, $bookId);

if ($vacationFrom <= 0 && $moveRange['from'] > 0) {
    $vacationFrom = $moveRange['from'];
    $vacationTo = $moveRange['to'];
}

$centers = json_decode((string) ($tokenRow['vacation_sync_centers'] ?? ''), true);
$centerIds = is_array($centers['center_ids'] ?? null) ? $centers['center_ids'] : [(string) ($tokenRow['center_id'] ?? '')];

$syncRef = new ReflectionClass(VacationSyncService::class);
$resolveRange = $syncRef->getMethod('resolveOverlappingAppointmentRange');
$resolveRange->setAccessible(true);
$findOverlapping = $syncRef->getMethod('findOverlappingAppointments');
$findOverlapping->setAccessible(true);

$overlapViaApi = $resolveRange->invoke(null, $bookId, $hamdast, $vacationFrom, $vacationTo, null);
$overlapViaCalendar = null;
if ($calendarEvents !== []) {
    $overlapViaCalendar = $resolveRange->invoke(
        null,
        $bookId,
        $hamdast,
        $vacationFrom,
        $vacationTo,
        $calendarEvents[0]
    );
}

$centerResults = [];
foreach ($centerIds as $centerId) {
    if (!is_string($centerId) || trim($centerId) === '') {
        continue;
    }
    $vacationCenter = [
        'medical_center_id' => trim($centerId),
        'user_center_id' => null,
        'name' => '',
    ];
    $targets = $findOverlapping->invoke(
        null,
        $userId,
        $googleAccess,
        $hamdast,
        $vacationFrom,
        $vacationTo,
        $vacationCenter
    );
    $centerResults[$centerId] = $targets;
}

echo json_encode([
    'user_id' => $userId,
    'book_id' => $bookId,
    'has_appointment_write' => Paziresh24AppointmentApi::hasAppointmentWriteScope($hamdast),
    'cancel_conflicting' => GoogleTokensRepository::isCancelConflictingAppointmentsEnabled($tokenRow),
    'appointment' => $appointment,
    'move_range' => $moveRange,
    'calendar_events' => array_map(static function (array $event): array {
        $parsed = GoogleEventParser::parseEvent($event);
        return [
            'id' => $event['id'] ?? null,
            'start_ts' => $parsed['start_ts'] ?? null,
            'end_ts' => $parsed['end_ts'] ?? null,
            'book_id' => GoogleEventParser::extractBookId($event),
            'center_id' => $event['extendedProperties']['private']['hamgam_center_id'] ?? null,
        ];
    }, $calendarEvents),
    'vacation_window' => ['from' => $vacationFrom, 'to' => $vacationTo],
    'overlap_via_api_only' => $overlapViaApi,
    'overlap_via_calendar' => $overlapViaCalendar,
    'find_overlapping_by_center' => $centerResults,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
