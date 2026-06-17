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

echo json_encode([
    'ok' => true,
    'user_id' => $userId,
    'count' => count($rows),
    'tracked_vacations' => $rows,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
