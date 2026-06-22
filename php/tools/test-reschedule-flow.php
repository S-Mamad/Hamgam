<?php

declare(strict_types=1);

/**
 * Diagnose reschedule flow (slots + move) for a book_id.
 *
 * GET ...?user_id=ID&key=KEY&book_id=UUID                     — dry run (slots only)
 * GET ...?user_id=ID&key=KEY&book_id=UUID&confirm=1           — execute move + calendar sync
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
$range = Paziresh24AppointmentApi::resolveMoveRange($hamdastAccessToken, $bookId, 0, 0);

$medicalCenterId = is_array($appointment)
    ? (BookingAppointmentResolver::extractCenterId($appointment) ?? '')
    : '';

if ($medicalCenterId === '') {
    $medicalCenterId = (string) ($tokenRow['center_id'] ?? '');
}

$userCenterId = BookingAppointmentResolver::resolveUserCenterIdForReschedule(
    is_array($appointment) ? $appointment : null,
    $hamdastAccessToken,
    $medicalCenterId,
    null
);

$targetFrom = null;
if ($medicalCenterId !== '' && $userCenterId !== null && $userCenterId !== '') {
    $targetFrom = Paziresh24AppointmentApi::getFirstAvailableSlot(
        $hamdastAccessToken,
        $medicalCenterId,
        $userCenterId
    );
}

$result = [
    'ok' => true,
    'user_id' => $userId,
    'book_id' => $bookId,
    'cancel_conflicting_appointments' => GoogleTokensRepository::isCancelConflictingAppointmentsEnabled($tokenRow),
    'has_appointment_write_scope' => Paziresh24AppointmentApi::hasAppointmentWriteScope($hamdastAccessToken),
    'appointment_fetched' => is_array($appointment),
    'medical_center_id' => $medicalCenterId !== '' ? $medicalCenterId : null,
    'user_center_id' => $userCenterId,
    'book_from' => $range['from'] > 0 ? $range['from'] : null,
    'book_to' => $range['to'] > 0 ? $range['to'] : null,
    'target_from' => $targetFrom,
    'ready_to_move' => $medicalCenterId !== ''
        && $userCenterId !== null
        && $userCenterId !== ''
        && $targetFrom !== null
        && $range['from'] > 0
        && $range['to'] > $range['from'],
];

if ($confirm && $result['ready_to_move']) {
    $move = Paziresh24AppointmentApi::rescheduleToFirstAvailableSlot(
        $hamdastAccessToken,
        $bookId,
        $medicalCenterId,
        $userCenterId,
        $range['from'],
        $range['to']
    );
    $result['move'] = $move;

    if ($move['success']) {
        try {
            $fresh = GoogleCalendar::getAppointment($bookId, $hamdastAccessToken);
            if (is_array($fresh)) {
                $result['calendar_sync'] = AppointmentWebhookService::handleUpdate($fresh, $userId, $bookId);
            }
        } catch (Throwable $e) {
            $result['calendar_sync_error'] = $e->getMessage();
        }
    }

    $result['ok'] = (bool) ($move['success'] ?? false);
}

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
