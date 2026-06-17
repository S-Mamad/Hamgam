<?php

declare(strict_types=1);

/**
 * Test import_future_vacations backfill (30-day window).
 * Does NOT delete vacations or calendar events.
 *
 * GET /php/tools/test-vacation-backfill.php?user_id=23489442&key=YOUR_APP_SECRET
 * Optional: run=1  — execute backfill if eligible
 * Optional: force=1 — reset done_at and run backfill again (use with care)
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
$shouldRun = isset($_GET['run']) && (string) $_GET['run'] === '1';
$force = isset($_GET['force']) && (string) $_GET['force'] === '1';

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

if ($refreshToken === '' || $hamdastAccessToken === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing tokens'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($force) {
    $stmt = Database::connection()->prepare(
        'UPDATE google_tokens SET
            import_future_vacations_done_at = NULL,
            import_future_vacations_window_end = NULL,
            updated_at = CURRENT_TIMESTAMP
         WHERE paziresh24_user_id = :user_id'
    );
    $stmt->execute(['user_id' => $userId]);
    $tokenRow = GoogleTokensRepository::findByUserId($userId);
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
$timeMin = gmdate('Y-m-d\TH:i:s\Z', $now);
$timeMax = gmdate('Y-m-d\TH:i:s\Z', $windowEnd);

$events = GoogleCalendarWatch::listEventsInRange($googleAccessToken, $timeMin, $timeMax);

$eligible = [];
$skipped = [];
$alreadyTracked = [];

foreach ($events as $event) {
    if (!is_array($event)) {
        continue;
    }

    $parsed = GoogleEventParser::parseEvent($event);
    $eventId = GoogleEventParser::extractEventId($event);
    $summary = is_string($event['summary'] ?? null) ? $event['summary'] : '';
    $status = is_string($event['status'] ?? null) ? $event['status'] : 'confirmed';
    $isDeleted = ($event['deleted'] ?? false) === true || $status === 'cancelled';

    $row = [
        'id' => $eventId,
        'summary' => $summary,
        'status' => $status,
        'deleted' => $isDeleted,
    ];

    if ($parsed !== null) {
        $row['from'] = $parsed['start_ts'];
        $row['to'] = $parsed['end_ts'];
        $row['from_iso'] = date('Y-m-d H:i:s', $parsed['start_ts']);
        $row['to_iso'] = date('Y-m-d H:i:s', $parsed['end_ts']);
    }

    if ($isDeleted || $parsed === null) {
        $row['skip_reason'] = $isDeleted ? 'deleted' : 'unparseable';
        $skipped[] = $row;
        continue;
    }

    if ($parsed['status'] !== 'confirmed') {
        $row['skip_reason'] = 'not_confirmed';
        $skipped[] = $row;
        continue;
    }

    if (GoogleEventParser::isHamgamAppointmentEvent($event)) {
        $row['skip_reason'] = 'hamgam_appointment';
        $skipped[] = $row;
        continue;
    }

    if ($eventId !== null && GoogleVacationRepository::hasProcessedEvent($userId, $eventId)) {
        $tracked = GoogleVacationRepository::findProcessedEvent($userId, $eventId);
        $row['tracked'] = true;
        $row['vacation_from'] = $tracked['vacation_from'] ?? null;
        $row['vacation_to'] = $tracked['vacation_to'] ?? null;
        $alreadyTracked[] = $row;
        continue;
    }

    $row['skip_reason'] = null;
    $eligible[] = $row;
}

$backfillResult = null;
$eligibleBeforeRun = count($eligible);

if ($shouldRun || $force) {
    $backfillResult = VacationSyncService::runFutureEventsBackfill($userId, $hamdastAccessToken, $force);

    $eligible = [];
    $alreadyTracked = [];
    foreach ($events as $event) {
        if (!is_array($event)) {
            continue;
        }
        $parsed = GoogleEventParser::parseEvent($event);
        $eventId = GoogleEventParser::extractEventId($event);
        if ($parsed === null || $eventId === null) {
            continue;
        }
        if (GoogleEventParser::isHamgamAppointmentEvent($event)) {
            continue;
        }
        if ($parsed['status'] !== 'confirmed') {
            continue;
        }
        if (($event['deleted'] ?? false) === true) {
            continue;
        }

        $row = [
            'id' => $eventId,
            'summary' => $parsed['summary'],
            'from_iso' => date('Y-m-d H:i:s', $parsed['start_ts']),
            'to_iso' => date('Y-m-d H:i:s', $parsed['end_ts']),
        ];

        if (GoogleVacationRepository::hasProcessedEvent($userId, $eventId)) {
            $tracked = GoogleVacationRepository::findProcessedEvent($userId, $eventId);
            $row['vacation_from'] = $tracked['vacation_from'] ?? null;
            $row['vacation_to'] = $tracked['vacation_to'] ?? null;
            $alreadyTracked[] = $row;
        } else {
            $eligible[] = $row;
        }
    }
}

$tokenRow = GoogleTokensRepository::findByUserId($userId);

echo json_encode([
    'ok' => $eligibleBeforeRun === 0 || ($backfillResult !== null && ($backfillResult['imported'] ?? 0) > 0) || count($alreadyTracked) > 0,
    'user_id' => $userId,
    'settings' => [
        'auto_vacation' => GoogleVacationRepository::isAutoVacationEnabled($tokenRow),
        'import_future_vacations' => GoogleTokensRepository::toBoolPublic($tokenRow['import_future_vacations'] ?? false),
        'import_done_at' => $tokenRow['import_future_vacations_done_at'] ?? null,
        'import_window_end' => $tokenRow['import_future_vacations_window_end'] ?? null,
        'backfill_eligible' => GoogleTokensRepository::shouldRunFutureVacationsBackfill($tokenRow),
    ],
    'window' => [
        'from_iso' => date('Y-m-d H:i:s', $now),
        'to_iso' => date('Y-m-d H:i:s', $windowEnd),
        'days' => 30,
    ],
    'summary' => [
        'total_events_in_window' => count($events),
        'eligible_for_vacation' => count($eligible),
        'already_tracked' => count($alreadyTracked),
        'skipped' => count($skipped),
    ],
    'eligible_not_yet_tracked' => $eligible,
    'already_tracked_as_vacation' => $alreadyTracked,
    'skipped_events' => array_slice($skipped, 0, 20),
    'backfill_result' => $backfillResult,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
