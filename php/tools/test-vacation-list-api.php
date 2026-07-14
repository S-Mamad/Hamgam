<?php

declare(strict_types=1);

/**
 * Read-only: list Paziresh24 vacations for a user (no create/delete).
 * GET /php/tools/test-vacation-list-api.php?user_id=1792050&key=YOUR_APP_SECRET
 * Optional: from=UNIX&to=UNIX (defaults: start of today → +90 days, Asia/Tehran)
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

$hamdastAccessToken = (string) ($tokenRow['hamdast_access_token'] ?? '');
$centerId = isset($tokenRow['center_id']) && is_string($tokenRow['center_id'])
    ? trim($tokenRow['center_id'])
    : '';

if ($hamdastAccessToken === '' || $centerId === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing hamdast token or center_id'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $tz = new DateTimeZone('Asia/Tehran');
    $rangeStart = isset($_GET['from']) ? (int) $_GET['from'] : (new DateTimeImmutable('today 00:00:00', $tz))->getTimestamp();
    $rangeEnd = isset($_GET['to']) ? (int) $_GET['to'] : (new DateTimeImmutable('today 00:00:00', $tz))->modify('+90 days')->getTimestamp();
} catch (Throwable) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid date range'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($rangeEnd <= $rangeStart) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'to must be after from'], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = Paziresh24VacationApi::listVacations($hamdastAccessToken, $centerId, $rangeStart, $rangeEnd);
if (!is_array($raw)) {
    http_response_code(502);
    echo json_encode(['ok' => false, 'error' => 'listVacations failed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$rows = [];
if (isset($raw['data']) && is_array($raw['data'])) {
    $rows = $raw['data'];
} elseif (array_is_list($raw)) {
    $rows = $raw;
}

$normalized = [];
foreach ($rows as $row) {
    if (!is_array($row)) {
        continue;
    }

    $from = (int) ($row['from'] ?? $row['vacation_from'] ?? 0);
    $to = (int) ($row['to'] ?? $row['vacation_to'] ?? 0);
    if ($from <= 0 || $to <= $from) {
        continue;
    }

    $durationSec = $to - $from;
    $normalized[] = [
        'from' => $from,
        'to' => $to,
        'from_iso' => date('Y-m-d H:i:s', $from),
        'to_iso' => date('Y-m-d H:i:s', $to),
        'duration_minutes' => (int) round($durationSec / 60),
        'duration_seconds' => $durationSec,
        'raw' => $row,
    ];
}

usort($normalized, static fn(array $a, array $b): int => $a['from'] <=> $b['from']);

$trackedStmt = Database::connection()->prepare(
    'SELECT google_event_id, event_summary, vacation_from, vacation_to, created_at
     FROM google_event_vacations
     WHERE paziresh24_user_id = :user_id
     ORDER BY vacation_from ASC'
);
$trackedStmt->execute(['user_id' => $userId]);
$tracked = $trackedStmt->fetchAll() ?: [];

echo json_encode([
    'ok' => true,
    'user_id' => $userId,
    'google_account' => $tokenRow['google_account_email'] ?? null,
    'center_id' => $centerId,
    'auto_vacation' => GoogleVacationRepository::isAutoVacationEnabled($tokenRow),
    'query_from_iso' => date('Y-m-d H:i:s', $rangeStart),
    'query_to_iso' => date('Y-m-d H:i:s', $rangeEnd),
    'paziresh24_vacation_count' => count($normalized),
    'paziresh24_vacations' => $normalized,
    'hamgam_tracked_count' => count($tracked),
    'hamgam_tracked_vacations' => $tracked,
    'api_raw_keys' => array_keys($raw),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
