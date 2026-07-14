<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

MonitorAuth::requireAuth();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    Response::jsonError('Method not allowed', 405);
}

$filters = [];

$hours = isset($_GET['hours']) ? max(1, min(720, (int) $_GET['hours'])) : 24;
$filters['since'] = date('Y-m-d H:i:s', time() - ($hours * 3600));

if (isset($_GET['until']) && is_string($_GET['until']) && trim($_GET['until']) !== '') {
    $filters['until'] = trim($_GET['until']);
}

foreach (['channel', 'level', 'category', 'user_id', 'request_id', 'search'] as $key) {
    if (isset($_GET[$key]) && is_string($_GET[$key]) && trim($_GET[$key]) !== '') {
        $filters[$key] = trim($_GET[$key]);
    }
}

if (isset($_GET['levels']) && is_string($_GET['levels']) && trim($_GET['levels']) !== '') {
    $filters['levels'] = array_values(array_filter(array_map('trim', explode(',', $_GET['levels']))));
}

if (isset($_GET['min_id'])) {
    $filters['min_id'] = max(0, (int) $_GET['min_id']);
}

if (isset($_GET['max_id'])) {
    $filters['max_id'] = max(0, (int) $_GET['max_id']);
}

$limit = isset($_GET['limit']) ? max(1, min(500, (int) $_GET['limit'])) : 100;
$offset = isset($_GET['offset']) ? max(0, (int) $_GET['offset']) : 0;

$events = MonitorRepository::listEvents($filters, $limit, $offset);
$total = MonitorRepository::countEvents($filters);

Response::json([
    'ok' => true,
    'events' => $events,
    'pagination' => [
        'limit' => $limit,
        'offset' => $offset,
        'total' => $total,
        'has_more' => ($offset + $limit) < $total,
    ],
    'filters' => $filters,
]);
