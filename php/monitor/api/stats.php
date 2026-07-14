<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

MonitorAuth::requireAuth();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    Response::jsonError('Method not allowed', 405);
}

$hours = isset($_GET['hours']) ? max(1, min(720, (int) $_GET['hours'])) : 24;
$since = date('Y-m-d H:i:s', time() - ($hours * 3600));

$overview = MonitorRepository::statsOverview($since);
$byChannel = MonitorRepository::statsByChannel($since, 25);
$byHour = MonitorRepository::statsByHour($since);
$byLevel = MonitorRepository::statsByLevel($since);
$byCategory = MonitorRepository::statsByCategory($since);
$channels = MonitorRepository::distinctChannels();
$recentErrors = MonitorRepository::listEvents(
    ['since' => $since, 'levels' => ['error', 'critical']],
    15,
    0
);
$recentActivity = MonitorRepository::listEvents(['since' => $since], 40, 0);

Response::json([
    'ok' => true,
    'period_hours' => $hours,
    'since' => $since,
    'overview' => $overview,
    'by_channel' => $byChannel,
    'by_hour' => $byHour,
    'by_level' => $byLevel,
    'by_category' => $byCategory,
    'channels' => $channels,
    'recent_errors' => $recentErrors,
    'recent_activity' => $recentActivity,
]);
