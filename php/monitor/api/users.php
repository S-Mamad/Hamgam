<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

MonitorAuth::requireAuth();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    Response::jsonError('Method not allowed', 405);
}

$limit = isset($_GET['limit']) ? max(1, min(500, (int) $_GET['limit'])) : 100;
$userId = isset($_GET['user_id']) && is_string($_GET['user_id']) ? trim($_GET['user_id']) : '';

$users = MonitorService::usersHealth($limit);

if ($userId !== '') {
    $users = array_values(array_filter(
        $users,
        static fn (array $row): bool => ($row['user_id'] ?? '') === GoogleTokensRepository::normalizeUserId($userId)
    ));
}

Response::json([
    'ok' => true,
    'users' => $users,
    'count' => count($users),
]);
