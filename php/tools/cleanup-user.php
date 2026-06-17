<?php

declare(strict_types=1);

/**
 * One-time cleanup for legacy Zamanak/Hamgam user data.
 *
 * Usage (on server, from project root):
 *   php php/tools/cleanup-user.php 11683704
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run this script from CLI only.\n");
    exit(1);
}

$userId = $argv[1] ?? '';
$userId = trim($userId);

if ($userId === '' || !preg_match('/^\d+$/', $userId)) {
    fwrite(STDERR, "Usage: php php/tools/cleanup-user.php <paziresh24_user_id>\n");
    exit(1);
}

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/GoogleCalendarWatch.php';

$tokenRow = GoogleTokensRepository::findByUserId($userId);
$watchCleanup = null;

if ($tokenRow !== null) {
    $channelId = (string) ($tokenRow['google_channel_id'] ?? '');
    $resourceId = (string) ($tokenRow['google_resource_id'] ?? '');
    $refreshToken = (string) ($tokenRow['google_refresh_token'] ?? '');

    if ($channelId !== '' && $resourceId !== '' && $refreshToken !== '') {
        $watchCleanup = [
            'refresh_token' => $refreshToken,
            'channel_id' => $channelId,
            'resource_id' => $resourceId,
        ];
    }
}

GoogleTokensRepository::purgeUserRecords($userId);

if ($watchCleanup !== null) {
    $googleTokenData = GoogleCalendar::refreshAccessToken($watchCleanup['refresh_token']);
    $googleAccessToken = is_array($googleTokenData) ? ($googleTokenData['access_token'] ?? '') : '';
    if (is_string($googleAccessToken) && $googleAccessToken !== '') {
        GoogleCalendarWatch::stopWatch(
            $googleAccessToken,
            $watchCleanup['channel_id'],
            $watchCleanup['resource_id']
        );
        echo "Google watch stopped.\n";
    } else {
        echo "Google watch stop skipped (refresh failed).\n";
    }
}

$widgetDeleted = Paziresh24Api::deleteWidget($userId);
echo $widgetDeleted
    ? "Widget removed for user {$userId}.\n"
    : "Widget delete returned non-2xx for user {$userId} (may already be removed).\n";

echo "Database rows purged for user {$userId}.\n";
