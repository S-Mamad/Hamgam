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

exit($failed > 0 ? 1 : 0);
