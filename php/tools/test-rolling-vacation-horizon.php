<?php

declare(strict_types=1);

/**
 * Unit tests for the rolling 30-day vacation sync horizon.
 * CLI: php php/tools/test-rolling-vacation-horizon.php
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/vacation-bootstrap.php';

hamgam_load_vacation_modules();

$passed = 0;
$failed = 0;

function assertRolling(string $name, bool $condition): void
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

$iranTz = new DateTimeZone('Asia/Tehran');
$fixedNow = (new DateTimeImmutable('2026-06-28 15:00:00', $iranTz))->getTimestamp();

$bounds = GoogleEventParser::resolveVacationSyncHorizonBounds($fixedNow);
$expectedHorizonEnd = (new DateTimeImmutable('2026-07-29 00:00:00', $iranTz))->getTimestamp();
assertRolling(
    'horizon ends at start of Iran day +31',
    $bounds['to_ts'] === $expectedHorizonEnd
);
assertRolling(
    'horizon starts at now',
    $bounds['from_ts'] === $fixedNow
);

$rollingDay = GoogleEventParser::resolveRollingSyncTargetDayBounds($fixedNow);
$expectedRollingStart = (new DateTimeImmutable('2026-07-28 00:00:00', $iranTz))->getTimestamp();
$expectedRollingEnd = (new DateTimeImmutable('2026-07-29 00:00:00', $iranTz))->getTimestamp();
assertRolling(
    'rolling target day starts 30 Iran days ahead',
    $rollingDay['from_ts'] === $expectedRollingStart
);
assertRolling(
    'rolling target day ends at next midnight',
    $rollingDay['to_ts'] === $expectedRollingEnd
);

$insideEvent = [
    'id' => 'inside-horizon',
    'status' => 'confirmed',
    'start' => ['date' => '2026-07-10'],
    'end' => ['date' => '2026-07-11'],
];
$outsideEvent = [
    'id' => 'outside-horizon',
    'status' => 'confirmed',
    'start' => ['date' => '2026-08-15'],
    'end' => ['date' => '2026-08-16'],
];
$parsedInside = GoogleEventParser::parseEvent($insideEvent);
$parsedOutside = GoogleEventParser::parseEvent($outsideEvent);
assertRolling(
    'event inside horizon overlaps',
    is_array($parsedInside)
        && GoogleEventParser::eventOverlapsVacationSyncHorizon($parsedInside, $fixedNow)
);
assertRolling(
    'event outside horizon does not overlap',
    is_array($parsedOutside)
        && !GoogleEventParser::eventOverlapsVacationSyncHorizon($parsedOutside, $fixedNow)
);

$filtered = GoogleEventParser::filterRecurringInstancesToHorizon(
    [$insideEvent, $outsideEvent],
    $fixedNow
);
assertRolling('filter keeps only in-horizon instances', count($filtered) === 1);

$dailyMaster = [
    'id' => 'daily-series',
    'summary' => 'مرخصی روزانه',
    'status' => 'confirmed',
    'recurrence' => ['RRULE:FREQ=DAILY;COUNT=100'],
    'start' => ['date' => '2026-06-28'],
    'end' => ['date' => '2026-06-29'],
];
$syncWindow = GoogleEventParser::resolveRecurringInstancesVacationSyncWindow($dailyMaster, null, $fixedNow);
assertRolling(
    'recurring sync window capped within 30-day horizon',
    $syncWindow['time_max_ts'] <= $bounds['to_ts'] + 3600
);

$syncRef = new ReflectionClass(VacationSyncService::class);
assertRolling(
    'VacationSyncService exposes runDailyRollingVacationSync',
    $syncRef->hasMethod('runDailyRollingVacationSync')
);
assertRolling(
    'recurring horizon constant is 30 days',
    VacationSyncService::class && GoogleEventParser::VACATION_SYNC_HORIZON_DAYS === 30
);

$repoRef = new ReflectionClass(GoogleVacationRepository::class);
assertRolling(
    'GoogleVacationRepository exposes findUsersWithAutoVacationEnabled',
    $repoRef->hasMethod('findUsersWithAutoVacationEnabled')
);

echo PHP_EOL . 'Results: ' . $passed . ' passed, ' . $failed . ' failed' . PHP_EOL;
exit($failed > 0 ? 1 : 0);
