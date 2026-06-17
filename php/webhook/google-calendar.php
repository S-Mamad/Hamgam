<?php

declare(strict_types=1);

/**
 * Google Calendar Watch webhook — Google Calendar → Paziresh24 vacation sync
 * POST /webhook/google-calendar
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/vacation-bootstrap.php';

hamgam_load_vacation_modules();
require_once __DIR__ . '/../google-vacation/VacationSyncService.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit;
}

$headers = GoogleWebhookHeaders::read();
$channelId = $headers['channel_id'];
$resourceId = $headers['resource_id'];
$resourceState = $headers['resource_state'];

try {
    if ($channelId !== '' && $resourceState !== '') {
        VacationSyncService::handleNotification($channelId, $resourceId, $resourceState);
    } else {
        RequestContext::log('google-vacation', 'webhook missing headers channel=' . $channelId . ' state=' . $resourceState);
    }
} catch (Throwable $e) {
    RequestContext::log('google-vacation', 'webhook error: ' . $e->getMessage());
}

http_response_code(200);
header('Content-Type: text/plain; charset=utf-8');
echo 'OK';
