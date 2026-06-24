<?php

declare(strict_types=1);

/**
 * Regression tests: single events must keep exact times and never collapse.
 * CLI: php php/tools/test-vacation-single-event-safety.php
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/vacation-bootstrap.php';

hamgam_load_vacation_modules();

$passed = 0;
$failed = 0;

function assertSingle(string $name, bool $condition): void
{
    global $passed, $failed;

    if ($condition) {
        $passed++;
        echo 'PASS ' . $name . PHP_EOL;
        return;
    }

    $failed++;
    echo 'FAIL ' . $name . PHP_EOL;
}

$timedEvent = [
    'id' => 'single-timed-event',
    'summary' => 'مرخصی',
    'status' => 'confirmed',
    'start' => ['dateTime' => '2026-06-20T10:00:00', 'timeZone' => 'Asia/Tehran'],
    'end' => ['dateTime' => '2026-06-20T11:30:00', 'timeZone' => 'Asia/Tehran'],
];

$parsedTimed = GoogleEventParser::parseEvent($timedEvent);
assertSingle('single timed event parses', $parsedTimed !== null);
assertSingle('single timed event is not recurring', GoogleEventParser::extractRecurringSeriesKey($timedEvent) === null);

$expectedStart = (new DateTimeImmutable('2026-06-20 10:00:00', new DateTimeZone('Asia/Tehran')))->getTimestamp();
$expectedEnd = (new DateTimeImmutable('2026-06-20 11:30:00', new DateTimeZone('Asia/Tehran')))->getTimestamp();
assertSingle('single timed start preserved', is_array($parsedTimed) && $parsedTimed['start_ts'] === $expectedStart);
assertSingle('single timed end preserved', is_array($parsedTimed) && $parsedTimed['end_ts'] === $expectedEnd);
assertSingle(
    'single timed duration is 90 minutes',
    is_array($parsedTimed) && ($parsedTimed['end_ts'] - $parsedTimed['start_ts']) === 5400
);

$multiDayEvent = [
    'id' => 'single-multi-day',
    'summary' => 'مرخصی چند روزه',
    'status' => 'confirmed',
    'start' => ['date' => '2026-06-20'],
    'end' => ['date' => '2026-06-23'],
];
$parsedMultiDay = GoogleEventParser::parseEvent($multiDayEvent);
$multiStart = (new DateTimeImmutable('2026-06-20 00:00:00', new DateTimeZone('Asia/Tehran')))->getTimestamp();
$multiEnd = (new DateTimeImmutable('2026-06-23 00:00:00', new DateTimeZone('Asia/Tehran')))->getTimestamp();
assertSingle('multi-day all-day start preserved', is_array($parsedMultiDay) && $parsedMultiDay['start_ts'] === $multiStart);
assertSingle('multi-day all-day end preserved', is_array($parsedMultiDay) && $parsedMultiDay['end_ts'] === $multiEnd);

$weeklyInstanceA = [
    'id' => 'weekly-series_20260620T100000Z',
    'recurringEventId' => 'weekly-series',
    'status' => 'confirmed',
    'start' => ['dateTime' => '2026-06-20T10:00:00', 'timeZone' => 'Asia/Tehran'],
    'end' => ['dateTime' => '2026-06-20T11:00:00', 'timeZone' => 'Asia/Tehran'],
];
$weeklyInstanceB = [
    'id' => 'weekly-series_20260627T100000Z',
    'recurringEventId' => 'weekly-series',
    'status' => 'confirmed',
    'start' => ['dateTime' => '2026-06-27T10:00:00', 'timeZone' => 'Asia/Tehran'],
    'end' => ['dateTime' => '2026-06-27T11:00:00', 'timeZone' => 'Asia/Tehran'],
];
assertSingle(
    'weekly recurring instances must not collapse',
    !GoogleEventParser::shouldCollapseRecurringVacations(null, [$weeklyInstanceA, $weeklyInstanceB])
);

$aggregatedWeekly = GoogleEventParser::aggregateRecurringInstances(
    [$weeklyInstanceA, $weeklyInstanceB],
    'weekly-series'
);
assertSingle('weekly aggregate spans a week gap (shows why collapse is wrong)', is_array($aggregatedWeekly));
if (is_array($aggregatedWeekly) && is_array($parsedTimed)) {
    $gapDays = ($aggregatedWeekly['end_ts'] - $aggregatedWeekly['start_ts']) / 86400;
    assertSingle('weekly collapsed span would exceed 2 days', $gapDays > 2);
}

echo PHP_EOL . "Total: {$passed} passed, {$failed} failed" . PHP_EOL;
exit($failed > 0 ? 1 : 0);
