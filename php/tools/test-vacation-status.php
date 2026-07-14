<?php

declare(strict_types=1);

/**
 * Read-only: list tracked vacations for a user (no delete).
 * GET /php/tools/test-vacation-status.php?user_id=23489442&key=YOUR_APP_SECRET
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

$stmt = Database::connection()->prepare(
    'SELECT google_event_id, event_summary, vacation_from, vacation_to, created_at
     FROM google_event_vacations
     WHERE paziresh24_user_id = :user_id
     ORDER BY vacation_from ASC'
);
$stmt->execute(['user_id' => $userId]);
$rows = $stmt->fetchAll() ?: [];

$includeApi = isset($_GET['api']) && (string) $_GET['api'] === '1';
$paziresh24Vacations = [];
$apiError = null;

if ($includeApi) {
    $tokenRow = GoogleTokensRepository::findByUserId($userId);
    $hamdastAccessToken = is_array($tokenRow) ? (string) ($tokenRow['hamdast_access_token'] ?? '') : '';
    $centerId = is_array($tokenRow) && is_string($tokenRow['center_id'] ?? null)
        ? trim($tokenRow['center_id'])
        : '';

    if ($hamdastAccessToken === '' || $centerId === '') {
        $apiError = 'missing_hamdast_token_or_center_id';
    } else {
        try {
            $tz = new DateTimeZone('Asia/Tehran');
            $rangeStart = isset($_GET['from']) ? (int) $_GET['from'] : (new DateTimeImmutable('2026-06-01 00:00:00', $tz))->getTimestamp();
            $rangeEnd = isset($_GET['to']) ? (int) $_GET['to'] : (new DateTimeImmutable('2026-09-30 23:59:59', $tz))->getTimestamp();
        } catch (Throwable) {
            $rangeStart = 0;
            $rangeEnd = 0;
        }

        if ($rangeStart <= 0 || $rangeEnd <= $rangeStart) {
            $apiError = 'invalid_range';
        } else {
            $raw = Paziresh24VacationApi::listVacations($hamdastAccessToken, $centerId, $rangeStart, $rangeEnd);
            if (!is_array($raw)) {
                $apiError = 'listVacations_failed';
            } else {
                $apiRows = isset($raw['data']) && is_array($raw['data']) ? $raw['data'] : (array_is_list($raw) ? $raw : []);
                foreach ($apiRows as $apiRow) {
                    if (!is_array($apiRow)) {
                        continue;
                    }
                    $from = (int) ($apiRow['from'] ?? $apiRow['vacation_from'] ?? 0);
                    $to = (int) ($apiRow['to'] ?? $apiRow['vacation_to'] ?? 0);
                    if ($from <= 0 || $to <= $from) {
                        continue;
                    }
                    $paziresh24Vacations[] = [
                        'from' => $from,
                        'to' => $to,
                        'from_iso' => date('Y-m-d H:i:s', $from),
                        'to_iso' => date('Y-m-d H:i:s', $to),
                        'duration_minutes' => (int) round(($to - $from) / 60),
                        'raw' => $apiRow,
                    ];
                }
                usort(
                    $paziresh24Vacations,
                    static fn(array $a, array $b): int => $a['from'] <=> $b['from']
                );
            }
        }
    }
}

echo json_encode([
    'ok' => true,
    'user_id' => $userId,
    'count' => count($rows),
    'tracked_vacations' => $rows,
    'paziresh24_api' => $includeApi ? [
        'ok' => $apiError === null,
        'error' => $apiError,
        'count' => count($paziresh24Vacations),
        'vacations' => $paziresh24Vacations,
    ] : null,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
