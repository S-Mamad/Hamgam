<?php

declare(strict_types=1);

/**
 * Restore ONE appointment to an exact target slot (P24 move + Google calendar sync).
 *
 * GET ...?user_id=ID&key=KEY&book_id=UUID&target_from=UNIX_TS
 * GET ...?user_id=ID&key=KEY&book_id=UUID&target_iso=2026-07-14+10:30:00  (Asia/Tehran)
 * Add &confirm=1 to execute (otherwise dry-run only).
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
$bookId = isset($_GET['book_id']) ? trim((string) $_GET['book_id']) : '';
$confirm = isset($_GET['confirm']) && (string) $_GET['confirm'] === '1';

if ($userId === '' || $bookId === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'user_id and book_id required'], JSON_UNESCAPED_UNICODE);
    exit;
}

$targetFrom = isset($_GET['target_from']) ? (int) $_GET['target_from'] : 0;
if ($targetFrom <= 0 && isset($_GET['target_iso'])) {
    try {
        $targetFrom = (new DateTimeImmutable(
            str_replace('+', ' ', (string) $_GET['target_iso']),
            new DateTimeZone('Asia/Tehran')
        ))->getTimestamp();
    } catch (Throwable) {
        $targetFrom = 0;
    }
}

if ($targetFrom <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'target_from or target_iso required'], JSON_UNESCAPED_UNICODE);
    exit;
}

$tokenRow = GoogleTokensRepository::findByUserId($userId);
if ($tokenRow === null) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'user not found'], JSON_UNESCAPED_UNICODE);
    exit;
}

$hamdastAccessToken = (string) ($tokenRow['hamdast_access_token'] ?? '');
if ($hamdastAccessToken === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'missing hamdast token'], JSON_UNESCAPED_UNICODE);
    exit;
}

$appointment = GoogleCalendar::getAppointment($bookId, $hamdastAccessToken);
if (!is_array($appointment)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'appointment not found'], JSON_UNESCAPED_UNICODE);
    exit;
}

$range = Paziresh24AppointmentApi::resolveMoveRange($hamdastAccessToken, $bookId, 0, 0);
$bookFrom = (int) ($range['from'] ?? 0);
$bookTo = (int) ($range['to'] ?? 0);

$medicalCenterId = BookingAppointmentResolver::extractCenterId($appointment) ?? '';
if ($medicalCenterId === '') {
    $medicalCenterId = (string) ($tokenRow['center_id'] ?? '');
}

$userCenterId = BookingAppointmentResolver::resolveUserCenterIdForReschedule(
    $appointment,
    $hamdastAccessToken,
    $medicalCenterId,
    null
);

$patientName = trim((string) ($appointment['name'] ?? '') . ' ' . (string) ($appointment['family'] ?? ''));

$result = [
    'ok' => true,
    'dry_run' => !$confirm,
    'user_id' => $userId,
    'book_id' => $bookId,
    'patient' => $patientName,
    'current_from' => $bookFrom > 0 ? date('Y-m-d H:i:s', $bookFrom) : null,
    'current_to' => $bookTo > 0 ? date('Y-m-d H:i:s', $bookTo) : null,
    'target_from' => $targetFrom,
    'target_from_iso' => date('Y-m-d H:i:s', $targetFrom),
    'medical_center_id' => $medicalCenterId !== '' ? $medicalCenterId : null,
    'user_center_id' => $userCenterId,
    'ready' => $medicalCenterId !== ''
        && $userCenterId !== null
        && $userCenterId !== ''
        && $bookFrom > 0
        && $bookTo > $bookFrom
        && Paziresh24AppointmentApi::hasAppointmentWriteScope($hamdastAccessToken),
];

if (!$confirm) {
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

if (!$result['ready']) {
    http_response_code(400);
    $result['ok'] = false;
    $result['error'] = 'not_ready';
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

$move = Paziresh24AppointmentApi::moveAppointmentWithCenterFallback(
    $hamdastAccessToken,
    $medicalCenterId,
    $userCenterId,
    $bookFrom,
    $bookTo,
    $targetFrom
);

$result['move'] = $move;
$result['ok'] = (bool) ($move['success'] ?? false);

if ($move['success']) {
    try {
        $result['calendar_sync'] = AppointmentWebhookService::syncCalendarFromApiMove($userId, $bookId);
    } catch (Throwable $e) {
        $result['calendar_sync_error'] = $e->getMessage();
    }

    $after = GoogleCalendar::getAppointment($bookId, $hamdastAccessToken);
    if (is_array($after)) {
        $afterFrom = (int) ($after['from'] ?? 0);
        $afterTo = (int) ($after['to'] ?? 0);
        $result['after_from'] = $afterFrom > 0 ? date('Y-m-d H:i:s', $afterFrom) : null;
        $result['after_to'] = $afterTo > 0 ? date('Y-m-d H:i:s', $afterTo) : null;
    }
} else {
    http_response_code(502);
}

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
