<?php

declare(strict_types=1);

/**
 * Repair missing google_sync_token for one user (CLI only).
 * Does NOT stop/register watch and does NOT create/delete calendar events or vacations.
 *
 * Usage (on server, from project root):
 *   php php/tools/repair-user-sync-token.php 1792050
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run this script from CLI only.\n");
    exit(1);
}

$userId = trim($argv[1] ?? '');

if ($userId === '' || !preg_match('/^\d+$/', $userId)) {
    fwrite(STDERR, "Usage: php php/tools/repair-user-sync-token.php <paziresh24_user_id>\n");
    exit(1);
}

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/GoogleVacationRepository.php';
require_once __DIR__ . '/../includes/HamgamConnectionService.php';

set_time_limit(300);

$tokenRow = GoogleTokensRepository::findByUserId($userId);
if ($tokenRow === null) {
    fwrite(STDERR, "User {$userId} not found in google_tokens.\n");
    exit(1);
}

$hadSyncToken = GoogleTokensRepository::hasSyncToken($tokenRow);
$channelId = trim((string) ($tokenRow['google_channel_id'] ?? ''));
$resourceId = trim((string) ($tokenRow['google_resource_id'] ?? ''));

echo "User {$userId}\n";
echo '  sync token before: ' . ($hadSyncToken ? 'present' : '(empty)') . "\n";
echo '  watch channel: ' . ($channelId !== '' ? $channelId : '(empty)') . "\n";
echo '  watch resource: ' . ($resourceId !== '' ? $resourceId : '(empty)') . "\n";

if ($hadSyncToken) {
    echo "Sync token already present; nothing to repair.\n";
    exit(0);
}

if (GoogleTokensRepository::needsWatchRegistration($tokenRow)) {
    fwrite(STDERR, "Watch is missing or expiring; run repair-user-watch.php instead.\n");
    exit(1);
}

if (HamgamConnectionService::repairSyncTokenForUser($userId, $tokenRow)) {
    $after = GoogleTokensRepository::findByUserId($userId);
    $tokenAfter = is_string($after['google_sync_token'] ?? null) ? trim($after['google_sync_token']) : '';
    $preview = $tokenAfter !== '' ? substr($tokenAfter, 0, 12) . '...' : '(empty)';

    echo "Sync token repaired successfully.\n";
    echo '  sync token after: ' . $preview . "\n";
    exit(0);
}

fwrite(STDERR, "Sync token repair failed for user {$userId}. Check server error_log.\n");
exit(1);
