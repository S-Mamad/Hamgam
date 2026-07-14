<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

MonitorAuth::requireAuth();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    Response::jsonError('Method not allowed', 405);
}

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    Response::jsonError('id required', 400);
}

$event = MonitorRepository::findById($id);
if ($event === null) {
    Response::jsonError('Not found', 404);
}

Response::json(['ok' => true, 'event' => $event]);
