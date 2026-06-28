<?php

declare(strict_types=1);

/**
 * Force Google Calendar watch re-registration for one user (CLI only).
 *
 * Usage (on server, from project root):
 *   php php/tools/repair-user-watch.php 3313319
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run this script from CLI only.\n");
    exit(1);
}

$userId = trim($argv[1] ?? '');

if ($userId === '' || !preg_match('/^\d+$/', $userId)) {
    fwrite(STDERR, "Usage: php php/tools/repair-user-watch.php <paziresh24_user_id>\n");
    exit(1);
}

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/GoogleCalendarWatch.php';
require_once __DIR__ . '/../includes/GoogleVacationRepository.php';
require_once __DIR__ . '/../google-vacation/WatchRegistrar.php';

$tokenRow = GoogleTokensRepository::findByUserId($userId);
if ($tokenRow === null) {
    fwrite(STDERR, "User {$userId} not found in google_tokens.\n");
    exit(1);
}

$refreshToken = trim((string) ($tokenRow['google_refresh_token'] ?? ''));
$hamdastAccessToken = trim((string) ($tokenRow['hamdast_access_token'] ?? ''));

if ($refreshToken === '') {
    fwrite(STDERR, "User {$userId} has no google_refresh_token.\n");
    exit(1);
}

if ($hamdastAccessToken === '') {
    fwrite(STDERR, "User {$userId} has no hamdast_access_token.\n");
    exit(1);
}

$channelBefore = trim((string) ($tokenRow['google_channel_id'] ?? ''));
$resourceBefore = trim((string) ($tokenRow['google_resource_id'] ?? ''));

echo "User {$userId}\n";
echo '  channel before: ' . ($channelBefore !== '' ? $channelBefore : '(empty)') . "\n";
echo '  resource before: ' . ($resourceBefore !== '' ? $resourceBefore : '(empty)') . "\n";

if (WatchRegistrar::renewForTokenRow($tokenRow)) {
    $after = GoogleTokensRepository::findByUserId($userId);
    $channelAfter = trim((string) ($after['google_channel_id'] ?? ''));
    $resourceAfter = trim((string) ($after['google_resource_id'] ?? ''));
    $expirationAfter = $after['google_watch_expiration'] ?? null;

    echo "Watch renewed successfully.\n";
    echo '  channel after: ' . $channelAfter . "\n";
    echo '  resource after: ' . $resourceAfter . "\n";
    echo '  expiration after: ' . (is_numeric($expirationAfter) ? (string) $expirationAfter : '(empty)') . "\n";
    exit(0);
}

fwrite(STDERR, "Watch renewal failed for user {$userId}. Check server error_log.\n");
exit(1);
