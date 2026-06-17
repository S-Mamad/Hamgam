<?php

declare(strict_types=1);

/**
 * End-to-end vacation sync test (run on production server).
 *
 * Steps:
 *   1. List Google Calendar events for current month
 *   2. Create a test event in Google Calendar
 *   3. Trigger vacation sync (same path as webhook)
 *   4. Verify tracked row + Paziresh24 vacation API
 *
 * Usage:
 *   GET /php/tools/test-vacation-e2e.php?user_id=23489442&key=YOUR_APP_SECRET
 *   Optional: action=status  (read-only: list month + tracked vacations, no create)
 *   Optional: cleanup=1      (delete test event + vacation after verify)
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
$action = isset($_GET['action']) ? (string) $_GET['action'] : 'full';
$cleanup = isset($_GET['cleanup']) && (string) $_GET['cleanup'] === '1';

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
$centerId = isset($tokenRow['center_id']) && is_string($tokenRow['center_id'])
    ? trim($tokenRow['center_id'])
    : '';

if ($refreshToken === '' || $hamdastAccessToken === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing google or hamdast token'], JSON_UNESCAPED_UNICODE);
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
$monthStart = (new DateTimeImmutable('first day of this month 00:00:00', new DateTimeZone('Asia/Tehran')))->getTimestamp();
$monthEnd = (new DateTimeImmutable('last day of this month 23:59:59', new DateTimeZone('Asia/Tehran')))->getTimestamp();

$timeMin = gmdate('Y-m-d\TH:i:s\Z', $monthStart);
$timeMax = gmdate('Y-m-d\TH:i:s\Z', $monthEnd + 1);

$monthEvents = GoogleCalendarWatch::listEventsInRange($googleAccessToken, $timeMin, $timeMax);
$monthSummary = [];

foreach ($monthEvents as $event) {
    if (!is_array($event)) {
        continue;
    }

    $parsed = GoogleEventParser::parseEvent($event);
    if ($parsed === null) {
        continue;
    }

    $monthSummary[] = [
        'id' => $parsed['event_id'],
        'summary' => $parsed['summary'],
        'from' => $parsed['start_ts'],
        'to' => $parsed['end_ts'],
        'from_iso' => date('Y-m-d H:i:s', $parsed['start_ts']),
        'to_iso' => date('Y-m-d H:i:s', $parsed['end_ts']),
        'status' => $parsed['status'],
    ];
}

$trackedRows = Database::connection()->prepare(
    'SELECT google_event_id, event_summary, vacation_from, vacation_to, created_at
     FROM google_event_vacations
     WHERE paziresh24_user_id = :user_id
     ORDER BY id DESC
     LIMIT 20'
);
$trackedRows->execute(['user_id' => $userId]);
$tracked = $trackedRows->fetchAll() ?: [];

if ($action === 'status') {
    echo json_encode([
        'ok' => true,
        'user_id' => $userId,
        'google_account' => $tokenRow['google_account_email'] ?? null,
        'auto_vacation' => GoogleVacationRepository::isAutoVacationEnabled($tokenRow),
        'center_id' => $centerId,
        'month' => date('Y-m'),
        'month_events_count' => count($monthSummary),
        'month_events' => $monthSummary,
        'tracked_vacations' => $tracked,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$testMarker = 'تست مرخصی همگام E2E';
$testDay = (new DateTimeImmutable('tomorrow 10:00:00', new DateTimeZone('Asia/Tehran')));
$testEnd = $testDay->modify('+2 hours');

$testEventPayload = [
    'summary' => $testMarker . ' ' . date('Y-m-d H:i'),
    'description' => 'Auto-created by test-vacation-e2e.php — safe to delete',
    'start' => [
        'dateTime' => $testDay->format('Y-m-d\TH:i:s'),
        'timeZone' => 'Asia/Tehran',
    ],
    'end' => [
        'dateTime' => $testEnd->format('Y-m-d\TH:i:s'),
        'timeZone' => 'Asia/Tehran',
    ],
];

$createResult = GoogleCalendar::createEventReturningBody($googleAccessToken, $testEventPayload);
if ($createResult === null) {
    http_response_code(502);
    echo json_encode([
        'ok' => false,
        'error' => 'failed to create google calendar event',
        'month_events_count' => count($monthSummary),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$createdEventId = is_string($createResult['id'] ?? null) ? $createResult['id'] : '';
$parsedCreated = GoogleEventParser::parseEvent($createResult);

sleep(2);

if ($channelId !== '' && $resourceId !== '') {
    VacationSyncService::handleNotification($channelId, $resourceId, 'exists');
} else {
    $listResult = GoogleCalendarWatch::listChangedEvents($googleAccessToken, null);
    $resolvedCenter = Paziresh24VacationApi::resolveVacationCenter($hamdastAccessToken);
    $vacationCenters = $resolvedCenter !== null ? [$resolvedCenter] : [];
    $autoVacation = GoogleVacationRepository::isAutoVacationEnabled($tokenRow);

    foreach ($listResult['events'] as $event) {
        if (!is_array($event)) {
            continue;
        }

        $eventId = GoogleEventParser::extractEventId($event);
        if ($eventId !== $createdEventId) {
            continue;
        }

        VacationSyncService::syncSingleEvent(
            $userId,
            $tokenRow,
            $event,
            $autoVacation,
            $vacationCenters,
            $hamdastAccessToken
        );
    }

    if ($listResult['nextSyncToken'] !== null) {
        GoogleVacationRepository::saveSyncToken($userId, $listResult['nextSyncToken']);
    }
}

$trackedEvent = $createdEventId !== ''
    ? GoogleVacationRepository::findProcessedEvent($userId, $createdEventId)
    : null;

$vacationOk = $trackedEvent !== null
    && (int) ($trackedEvent['vacation_from'] ?? 0) > 0
    && (int) ($trackedEvent['vacation_to'] ?? 0) > (int) ($trackedEvent['vacation_from'] ?? 0);

$parsedMatch = $parsedCreated !== null
    && $trackedEvent !== null
    && (int) $trackedEvent['vacation_from'] === $parsedCreated['start_ts']
    && (int) $trackedEvent['vacation_to'] === $parsedCreated['end_ts'];

$cleanupResult = null;
if ($cleanup && $vacationOk && $parsedCreated !== null && $centerId !== '') {
    Paziresh24VacationApi::deleteVacation(
        $hamdastAccessToken,
        $centerId,
        $parsedCreated['start_ts'],
        $parsedCreated['end_ts']
    );

    if ($createdEventId !== '') {
        GoogleVacationRepository::removeProcessedEvent($userId, $createdEventId);
        GoogleCalendar::deleteEvent($googleAccessToken, $createdEventId);
    }

    $cleanupResult = 'cleaned';
}

echo json_encode([
    'ok' => $vacationOk && $parsedMatch,
    'user_id' => $userId,
    'google_account' => $tokenRow['google_account_email'] ?? null,
    'center_id' => $centerId,
    'month' => date('Y-m'),
    'month_events_before' => count($monthSummary),
    'created_event' => [
        'id' => $createdEventId,
        'summary' => $testEventPayload['summary'],
        'from' => $parsedCreated['start_ts'] ?? null,
        'to' => $parsedCreated['end_ts'] ?? null,
        'from_iso' => isset($parsedCreated['start_ts']) ? date('Y-m-d H:i:s', $parsedCreated['start_ts']) : null,
        'to_iso' => isset($parsedCreated['end_ts']) ? date('Y-m-d H:i:s', $parsedCreated['end_ts']) : null,
    ],
    'sync' => [
        'webhook_channel' => $channelId !== '' ? $channelId : null,
        'tracked' => $trackedEvent !== null,
        'timestamps_match' => $parsedMatch,
        'vacation_from' => $trackedEvent['vacation_from'] ?? null,
        'vacation_to' => $trackedEvent['vacation_to'] ?? null,
    ],
    'cleanup' => $cleanupResult,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
