<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

$failed = 0;

function assertWatch(bool $condition, string $message): void
{
    global $failed;
    if ($condition) {
        echo 'OK   ' . $message . PHP_EOL;
        return;
    }
    echo 'FAIL ' . $message . PHP_EOL;
    $failed++;
}

$validExpiration = (int) (microtime(true) * 1000) + 86400000;

assertWatch(
    GoogleTokensRepository::needsWatchRegistration([
        'google_refresh_token' => 'rt',
        'google_channel_id' => 'channel-1',
        'google_resource_id' => '',
        'google_watch_expiration' => $validExpiration,
    ]),
    'needs registration when resource_id is empty'
);

assertWatch(
    !GoogleTokensRepository::needsWatchRegistration([
        'google_refresh_token' => 'rt',
        'google_channel_id' => 'channel-1',
        'google_resource_id' => 'resource-1',
        'google_watch_expiration' => $validExpiration,
    ]),
    'skips registration when channel, resource, and expiration are valid'
);

$healthyWatchRow = [
    'google_refresh_token' => 'rt',
    'google_channel_id' => 'channel-1',
    'google_resource_id' => 'resource-1',
    'google_watch_expiration' => $validExpiration,
    'google_sync_token' => null,
];

assertWatch(
    GoogleTokensRepository::needsSyncTokenRepair($healthyWatchRow),
    'needs sync token repair when watch is healthy but sync token is empty'
);

assertWatch(
    !GoogleTokensRepository::needsSyncTokenRepair(array_merge($healthyWatchRow, [
        'google_sync_token' => 'sync-token-abc',
    ])),
    'skips sync token repair when token already exists'
);

assertWatch(
    !GoogleTokensRepository::needsSyncTokenRepair([
        'google_refresh_token' => 'rt',
        'google_channel_id' => '',
        'google_resource_id' => 'resource-1',
        'google_watch_expiration' => $validExpiration,
        'google_sync_token' => null,
    ]),
    'sync token repair deferred when watch registration is required first'
);

exit($failed > 0 ? 1 : 0);
