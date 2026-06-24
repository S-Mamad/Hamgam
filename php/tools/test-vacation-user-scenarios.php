<?php

declare(strict_types=1);

/**
 * User-facing vacation sync scenario tests (parser + sync routing, no live API).
 * CLI: php php/tools/test-vacation-user-scenarios.php
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/vacation-bootstrap.php';

hamgam_load_vacation_modules();
require_once __DIR__ . '/../google-vacation/VacationSyncService.php';

$passed = 0;
$failed = 0;

function userScenarioAssert(string $name, bool $condition): void
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

$syncSource = file_get_contents(__DIR__ . '/../google-vacation/VacationSyncService.php');
userScenarioAssert(
    'syncSingleEvent does not auto-route all recurring events to series sync',
    is_string($syncSource)
        && !preg_match(
            '/\$seriesKey\s*!==\s*null\s*\n\s*&&\s*GoogleVacationRepository::hasProcessedEvent\(\$userId,\s*\$seriesKey\)\s*\)\s*\{\s*\n\s*return\s+self::syncRecurringSeriesEvent/s',
            $syncSource
        )
);
userScenarioAssert(
    'legacy collapsed series cleanup exists',
    is_string($syncSource) && str_contains($syncSource, 'dissolveLegacyCollapsedSeriesVacation')
);
userScenarioAssert(
    'update recovery delete+create removed (prevents rapid-edit corruption)',
    is_string($syncSource) && !str_contains($syncSource, 'trying delete+create recovery')
);
userScenarioAssert(
    'stale google revision guard exists',
    is_string($syncSource) && str_contains($syncSource, 'shouldSkipStaleGoogleEventUpdate')
);

$timedSingle = [
    'id' => 'evt-single-1',
    'summary' => 'مرخصی',
    'status' => 'confirmed',
    'start' => ['dateTime' => '2026-07-10T14:00:00', 'timeZone' => 'Asia/Tehran'],
    'end' => ['dateTime' => '2026-07-10T15:30:00', 'timeZone' => 'Asia/Tehran'],
];
$parsedSingle = GoogleEventParser::parseEvent($timedSingle);
$tz = new DateTimeZone('Asia/Tehran');
userScenarioAssert('single timed event parses', $parsedSingle !== null);
userScenarioAssert(
    'single timed start is 14:00 not midnight',
    is_array($parsedSingle)
        && $parsedSingle['start_ts'] === (new DateTimeImmutable('2026-07-10 14:00:00', $tz))->getTimestamp()
);

$movedSingle = $timedSingle;
$movedSingle['start'] = ['dateTime' => '2026-07-11T14:00:00', 'timeZone' => 'Asia/Tehran'];
$movedSingle['end'] = ['dateTime' => '2026-07-11T15:30:00', 'timeZone' => 'Asia/Tehran'];
$parsedMoved = GoogleEventParser::parseEvent($movedSingle);
userScenarioAssert(
    'moved event keeps same clock time on new day',
    is_array($parsedMoved)
        && $parsedMoved['start_ts'] === (new DateTimeImmutable('2026-07-11 14:00:00', $tz))->getTimestamp()
);
userScenarioAssert(
    'move to tomorrow changes timestamp by ~1 day',
    is_array($parsedSingle) && is_array($parsedMoved)
        && abs(($parsedMoved['start_ts'] - $parsedSingle['start_ts']) - 86400) < 120
);

$dailyInstances = [];
for ($day = 10; $day <= 12; $day++) {
    $dailyInstances[] = [
        'id' => 'daily-series_202607' . $day . 'T100000Z',
        'recurringEventId' => 'daily-series',
        'summary' => 'مرخصی روزانه',
        'status' => 'confirmed',
        'start' => ['dateTime' => '2026-07-' . $day . 'T09:30:00', 'timeZone' => 'Asia/Tehran'],
        'end' => ['dateTime' => '2026-07-' . $day . 'T10:30:00', 'timeZone' => 'Asia/Tehran'],
    ];
}

foreach ($dailyInstances as $index => $instance) {
    $parsedInstance = GoogleEventParser::parseEvent($instance);
    userScenarioAssert(
        'daily recurring instance ' . ($index + 1) . ' keeps timed start (not midnight)',
        is_array($parsedInstance)
            && $parsedInstance['start_ts'] === (new DateTimeImmutable(
                '2026-07-' . (10 + $index) . ' 09:30:00',
                $tz
            ))->getTimestamp()
    );
}

$aggregatedDaily = GoogleEventParser::aggregateRecurringInstances($dailyInstances, 'daily-series');
userScenarioAssert('aggregate helper still available', is_array($aggregatedDaily));
if (is_array($aggregatedDaily) && is_array($parsedSingle)) {
    $collapsedHours = ($aggregatedDaily['end_ts'] - $aggregatedDaily['start_ts']) / 3600;
    userScenarioAssert(
        'collapsed daily timed span covers multiple days (why collapse is disabled)',
        $collapsedHours > 24
    );
}

$multiDayAllDay = [
    'id' => 'evt-allday-span',
    'summary' => 'چند روز',
    'status' => 'confirmed',
    'start' => ['date' => '2026-07-10'],
    'end' => ['date' => '2026-07-13'],
];
$parsedMulti = GoogleEventParser::parseEvent($multiDayAllDay);
userScenarioAssert(
    'multi-day all-day spans 3 nights correctly',
    is_array($parsedMulti)
        && $parsedMulti['start_ts'] === (new DateTimeImmutable('2026-07-10 00:00:00', $tz))->getTimestamp()
        && $parsedMulti['end_ts'] === (new DateTimeImmutable('2026-07-13 00:00:00', $tz))->getTimestamp()
);

$multiDayTimed = [
    'id' => 'evt-multi-timed',
    'summary' => 'مرخصی چند روزه',
    'status' => 'confirmed',
    'start' => ['dateTime' => '2026-07-10T09:00:00', 'timeZone' => 'Asia/Tehran'],
    'end' => ['dateTime' => '2026-07-12T17:00:00', 'timeZone' => 'Asia/Tehran'],
];
$parsedMultiTimed = GoogleEventParser::parseEvent($multiDayTimed);
userScenarioAssert(
    'multi-day timed start keeps 09:00 on first day',
    is_array($parsedMultiTimed)
        && $parsedMultiTimed['start_ts'] === (new DateTimeImmutable('2026-07-10 09:00:00', $tz))->getTimestamp()
);
userScenarioAssert(
    'multi-day timed end keeps 17:00 on last day',
    is_array($parsedMultiTimed)
        && $parsedMultiTimed['end_ts'] === (new DateTimeImmutable('2026-07-12 17:00:00', $tz))->getTimestamp()
);

$mixedBoundaryEvent = [
    'id' => 'evt-mixed-boundary',
    'summary' => 'مرخصی',
    'status' => 'confirmed',
    'start' => [
        'date' => '2026-07-11',
        'dateTime' => '2026-07-10T14:30:00',
        'timeZone' => 'Asia/Tehran',
    ],
    'end' => [
        'date' => '2026-07-13',
        'dateTime' => '2026-07-12T18:00:00',
        'timeZone' => 'Asia/Tehran',
    ],
];
$parsedMixed = GoogleEventParser::parseEvent($mixedBoundaryEvent);
userScenarioAssert(
    'date+dateTime on start prefers dateTime (not next-day midnight)',
    is_array($parsedMixed)
        && $parsedMixed['start_ts'] === (new DateTimeImmutable('2026-07-10 14:30:00', $tz))->getTimestamp()
);
userScenarioAssert(
    'date+dateTime on end prefers dateTime',
    is_array($parsedMixed)
        && $parsedMixed['end_ts'] === (new DateTimeImmutable('2026-07-12 18:00:00', $tz))->getTimestamp()
);

echo PHP_EOL . "Total: {$passed} passed, {$failed} failed" . PHP_EOL;
exit($failed > 0 ? 1 : 0);
