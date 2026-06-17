<?php

declare(strict_types=1);

/**
 * Unit tests for HamgamSyncMessages and sync status helpers.
 * CLI: php php/tools/test-sync-messages.php
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/HamgamSyncMessages.php';

$failed = 0;

function assertSync(bool $condition, string $message): void
{
    global $failed;
    if (!$condition) {
        echo 'FAIL ' . $message . PHP_EOL;
        $failed++;
        return;
    }
    echo 'PASS ' . $message . PHP_EOL;
}

$watch = HamgamSyncMessages::warning('watch_registration_failed');
assertSync($watch['code'] === 'watch_registration_failed', 'watch warning code');
assertSync(
    str_contains($watch['message'], 'همگام‌سازی'),
    'watch warning message is Persian'
);

$partial = HamgamSyncMessages::warning('backfill_partial_fail', '3');
assertSync(str_contains($partial['message'], '3'), 'backfill partial includes count');

$unknown = HamgamSyncMessages::warning('unknown_code');
assertSync(str_contains($unknown['message'], 'Google Calendar'), 'unknown code fallback message');

assertSync(HamgamSyncMessages::hasErrors([$watch]), 'hasErrors true when warnings exist');
assertSync(!HamgamSyncMessages::hasErrors([]), 'hasErrors false when empty');

echo PHP_EOL . ($failed === 0 ? 'All sync message tests passed.' : "{$failed} failed.") . PHP_EOL;
exit($failed > 0 ? 1 : 0);
