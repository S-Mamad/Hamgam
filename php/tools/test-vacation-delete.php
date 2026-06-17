<?php

declare(strict_types=1);

/**
 * One-off manual test: DELETE vacation via Paziresh24 API using DB tokens.
 * Usage (production only, then delete this file):
 *   /php/tools/test-vacation-delete.php?user_id=23489442&from=1781658000&to=1781672400&key=YOUR_APP_SECRET
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/vacation-bootstrap.php';

hamgam_load_vacation_modules();

header('Content-Type: application/json; charset=utf-8');

$expectedKey = Config::get('HAMDAST_API_KEY', '');
$providedKey = isset($_GET['key']) ? (string) $_GET['key'] : '';

if ($expectedKey === '' || !hash_equals($expectedKey, $providedKey)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

$userId = GoogleTokensRepository::normalizeUserId((string) ($_GET['user_id'] ?? ''));
$from = isset($_GET['from']) ? (int) $_GET['from'] : 0;
$to = isset($_GET['to']) ? (int) $_GET['to'] : 0;

if ($userId === '' || $from <= 0 || $to <= 0 || $to <= $from) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'user_id, from, to required'], JSON_UNESCAPED_UNICODE);
    exit;
}

$tokenRow = GoogleTokensRepository::findByUserId($userId);
if ($tokenRow === null) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'user not found'], JSON_UNESCAPED_UNICODE);
    exit;
}

$hamdastAccessToken = (string) ($tokenRow['hamdast_access_token'] ?? '');
$centerId = isset($tokenRow['center_id']) && is_string($tokenRow['center_id'])
    ? trim($tokenRow['center_id'])
    : '';

if ($hamdastAccessToken === '' || $centerId === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing hamdast token or center_id'], JSON_UNESCAPED_UNICODE);
    exit;
}

$tracked = null;
if (isset($_GET['event_id']) && is_string($_GET['event_id']) && $_GET['event_id'] !== '') {
    $row = GoogleVacationRepository::findProcessedEvent($userId, (string) $_GET['event_id']);
    if ($row !== null) {
        $from = (int) ($row['vacation_from'] ?? $from);
        $to = (int) ($row['vacation_to'] ?? $to);
        $tracked = $row;
    }
}

$response = Paziresh24VacationApi::deleteVacation($hamdastAccessToken, $centerId, $from, $to);

echo json_encode([
    'ok' => $response !== null,
    'user_id' => $userId,
    'center_id' => $centerId,
    'from' => $from,
    'to' => $to,
    'tracked_event' => $tracked,
    'api_response' => $response,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
