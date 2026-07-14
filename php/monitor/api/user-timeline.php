<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

MonitorAuth::requireAuth();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    Response::jsonError('Method not allowed', 405);
}

$userId = isset($_GET['user_id']) && is_string($_GET['user_id']) ? trim($_GET['user_id']) : '';
if ($userId === '') {
    Response::jsonError('user_id is required', 400);
}

$userId = GoogleTokensRepository::normalizeUserId($userId);
$hours = isset($_GET['hours']) ? max(1, min(720, (int) $_GET['hours'])) : 168;
$since = date('Y-m-d H:i:s', time() - ($hours * 3600));
$limit = isset($_GET['limit']) ? max(1, min(500, (int) $_GET['limit'])) : 100;
$offset = isset($_GET['offset']) ? max(0, (int) $_GET['offset']) : 0;

$filters = [
    'user_id' => $userId,
    'since' => $since,
];

if (isset($_GET['level']) && is_string($_GET['level']) && trim($_GET['level']) !== '') {
    $filters['level'] = trim($_GET['level']);
}

if (isset($_GET['category']) && is_string($_GET['category']) && trim($_GET['category']) !== '') {
    $filters['category'] = trim($_GET['category']);
}

if (isset($_GET['search']) && is_string($_GET['search']) && trim($_GET['search']) !== '') {
    $filters['search'] = trim($_GET['search']);
}

$events = MonitorRepository::listEvents($filters, $limit, $offset);
$total = MonitorRepository::countEvents($filters);
$stats = MonitorRepository::statsFromFilters($filters);

$userProfile = null;
foreach (MonitorService::usersHealth(500) as $row) {
    if (($row['user_id'] ?? '') === $userId) {
        $userProfile = $row;
        break;
    }
}

Response::json([
    'ok' => true,
    'user_id' => $userId,
    'period_hours' => $hours,
    'since' => $since,
    'profile' => $userProfile,
    'stats' => $stats,
    'events' => $events,
    'pagination' => [
        'limit' => $limit,
        'offset' => $offset,
        'total' => $total,
        'has_more' => ($offset + $limit) < $total,
    ],
]);
