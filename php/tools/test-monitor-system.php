<?php

declare(strict_types=1);

/**
 * Smoke test for monitoring stack.
 * php php/tools/test-monitor-system.php
 */

require_once __DIR__ . '/../includes/bootstrap.php';

$failures = 0;

function assertTrue(bool $cond, string $msg): void
{
    global $failures;
    if ($cond) {
        echo "OK  {$msg}\n";
        return;
    }
    $failures++;
    echo "FAIL {$msg}\n";
}

MonitorRepository::ensureSchema(Database::connection());

$id = MonitorService::record([
    'channel' => 'test-monitor',
    'level' => 'info',
    'category' => 'system',
    'action' => 'smoke.test',
    'message' => 'monitor smoke test at ' . date('c'),
    'context' => ['token' => 'should_be_redacted', 'book_id' => 'demo-123'],
]);

assertTrue(is_int($id) && $id > 0, 'insert event');

$row = MonitorRepository::findById((int) $id);
assertTrue(is_array($row) && ($row['channel'] ?? '') === 'test-monitor', 'fetch event by id');

$count = MonitorRepository::countEvents(['channel' => 'test-monitor']);
assertTrue($count >= 1, 'count events by channel');

$overview = MonitorService::systemOverview();
assertTrue(isset($overview['status']) && isset($overview['checks']), 'system overview');

MonitorService::webhook('test-monitor', 'webhook.demo', 'demo webhook', '12345', ['demo' => true]);
MonitorService::cron('test-job', 'cron demo ok');

$since = date('Y-m-d H:i:s', time() - 3600);
$stats = MonitorRepository::statsOverview($since);
assertTrue(isset($stats['total']), 'stats overview');

echo $failures === 0 ? "\nAll monitor tests passed.\n" : "\n{$failures} test(s) failed.\n";
exit($failures > 0 ? 1 : 0);
