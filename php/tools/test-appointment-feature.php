<?php

declare(strict_types=1);

/**
 * E2E test for appointment cancel + vacation conflict resolution.
 * Read-only status checks; destructive steps only with confirm=1.
 *
 * GET  ...?user_id=23489442&key=KEY                     — status only
 * GET  ...?user_id=23489442&key=KEY&action=conflict     — test BOOK_CONFLICT flow
 * GET  ...?user_id=23489442&key=KEY&action=delete_appt&book_id=UUID&confirm=1
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/vacation-bootstrap.php';

hamgam_load_vacation_modules();
require_once __DIR__ . '/../google-vacation/VacationSyncService.php';

header('Content-Type: application/json; charset=utf-8');

$expectedKey = Config::get('HAMDAST_API_KEY', '');
$providedKey = isset($_GET['key']) ? (string) $_GET['key'] : '';

if ($expectedKey === '' || !hash_equals($expectedKey, $providedKey)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

$userId = GoogleTokensRepository::normalizeUserId((string) ($_GET['user_id'] ?? ''));
$action = isset($_GET['action']) ? (string) $_GET['action'] : 'status';
$confirm = isset($_GET['confirm']) && (string) $_GET['confirm'] === '1';

if ($userId === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'user_id required'], JSON_UNESCAPED_UNICODE);
    exit;
}

$tokenRow = GoogleTokensRepository::findByUserId($userId);
if ($tokenRow === null) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'user not found'], JSON_UNESCAPED_UNICODE);
    exit;
}

$refreshToken = (string) ($tokenRow['google_refresh_token'] ?? '');
$hamdastAccessToken = (string) ($tokenRow['hamdast_access_token'] ?? '');
$channelId = (string) ($tokenRow['google_channel_id'] ?? '');
$resourceId = (string) ($tokenRow['google_resource_id'] ?? '');

if ($refreshToken === '' || $hamdastAccessToken === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing tokens'], JSON_UNESCAPED_UNICODE);
    exit;
}

$googleTokenData = GoogleCalendar::refreshAccessToken($refreshToken);
$googleAccessToken = is_array($googleTokenData) ? ($googleTokenData['access_token'] ?? '') : '';
if (!is_string($googleAccessToken) || $googleAccessToken === '') {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'google token refresh failed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$now = time();
$windowEnd = $now + (30 * 86400);
$events = GoogleCalendarWatch::listEventsInRange(
    $googleAccessToken,
    gmdate('Y-m-d\TH:i:s\Z', $now),
    gmdate('Y-m-d\TH:i:s\Z', $windowEnd)
);

$personal = [];
$appointments = [];

foreach ($events as $event) {
    if (!is_array($event)) {
        continue;
    }

    $parsed = GoogleEventParser::parseEvent($event);
    if ($parsed === null || $parsed['is_deleted']) {
        continue;
    }

    $bookId = GoogleEventParser::extractBookId($event);
    $row = [
        'id' => $parsed['event_id'],
        'summary' => $parsed['summary'],
        'from_iso' => date('Y-m-d H:i:s', $parsed['start_ts']),
        'to_iso' => date('Y-m-d H:i:s', $parsed['end_ts']),
        'book_id' => $bookId,
        'has_description_book_id' => is_string($event['description'] ?? null)
            && str_contains($event['description'], 'hamgam_book_id:'),
    ];

    if (GoogleEventParser::isHamgamAppointmentEvent($event)) {
        $appointments[] = $row;
    } else {
        $personal[] = $row;
    }
}

$ref = new ReflectionClass(VacationSyncService::class);
$hasNewCode = $ref->hasMethod('processDeletedAppointmentEvent');

$result = [
    'ok' => true,
    'user_id' => $userId,
    'new_feature_code_deployed' => $hasNewCode,
    'token_scopes' => Paziresh24AppointmentApi::decodeTokenScopes($hamdastAccessToken),
    'has_appointment_write_scope' => Paziresh24AppointmentApi::hasAppointmentWriteScope($hamdastAccessToken),
    'window_days' => 30,
    'calendar' => [
        'personal_events' => count($personal),
        'appointment_events' => count($appointments),
        'personal' => $personal,
        'appointments' => $appointments,
    ],
];

if ($action === 'status') {
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'conflict') {
    if (!$confirm) {
        http_response_code(400);
        echo json_encode([
            'ok' => false,
            'error' => 'add confirm=1 to run conflict test (creates vacation, may cancel appointments)',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $vacationCenter = Paziresh24VacationApi::resolveVacationCenter($hamdastAccessToken);
    if ($vacationCenter === null) {
        http_response_code(502);
        echo json_encode(['ok' => false, 'error' => 'center resolve failed'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $testStart = (new DateTimeImmutable('tomorrow 12:00:00', new DateTimeZone('Asia/Tehran')));
    $testEnd = $testStart->modify('+2 hours');

    $eventPayload = [
        'summary' => 'Conflict test ' . date('His'),
        'description' => 'hamgam conflict e2e',
        'start' => [
            'dateTime' => $testStart->format('Y-m-d\TH:i:s'),
            'timeZone' => 'Asia/Tehran',
        ],
        'end' => [
            'dateTime' => $testEnd->format('Y-m-d\TH:i:s'),
            'timeZone' => 'Asia/Tehran',
        ],
    ];

    $created = GoogleCalendar::createEventReturningBody($googleAccessToken, $eventPayload);
    if ($created === null) {
        http_response_code(502);
        echo json_encode(['ok' => false, 'error' => 'google event create failed'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    sleep(2);

    if ($channelId !== '' && $resourceId !== '') {
        VacationSyncService::handleNotification($channelId, $resourceId, 'exists');
    }

    sleep(2);

    $eventId = is_string($created['id'] ?? null) ? $created['id'] : '';
    $parsedCreated = GoogleEventParser::parseEvent($created);
    $tracked = $eventId !== '' ? GoogleVacationRepository::findProcessedEvent($userId, $eventId) : null;

    $result['conflict_test'] = [
        'created_event_id' => $eventId,
        'from' => $parsedCreated['start_ts'] ?? null,
        'to' => $parsedCreated['end_ts'] ?? null,
        'vacation_tracked' => $tracked !== null,
        'vacation_from' => $tracked['vacation_from'] ?? null,
        'vacation_to' => $tracked['vacation_to'] ?? null,
    ];
    $result['ok'] = $tracked !== null;

    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($action === 'delete_appt') {
    $bookId = isset($_GET['book_id']) ? trim((string) $_GET['book_id']) : '';
    if ($bookId === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'book_id required'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!$confirm) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'add confirm=1 to delete appointment via API'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $apptBefore = GoogleCalendar::getAppointment($bookId, $hamdastAccessToken);
    $deleteResp = Paziresh24AppointmentApi::deleteAppointment($hamdastAccessToken, $bookId);
    $apptAfter = GoogleCalendar::getAppointment($bookId, $hamdastAccessToken);

    $result['delete_appt_test'] = [
        'book_id' => $bookId,
        'before_exists' => $apptBefore !== null,
        'delete_ok' => $deleteResp !== null,
        'after_exists' => $apptAfter !== null,
        'api_response' => $deleteResp,
    ];
    $result['ok'] = $deleteResp !== null;

    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'unknown action'], JSON_UNESCAPED_UNICODE);
