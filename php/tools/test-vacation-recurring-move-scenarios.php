<?php

declare(strict_types=1);

/**
 * Recurring move + multi-day span scenarios (parser + sync guards, no live API).
 * CLI: php php/tools/test-vacation-recurring-move-scenarios.php
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/vacation-bootstrap.php';

hamgam_load_vacation_modules();
require_once __DIR__ . '/../google-vacation/VacationSyncService.php';

$passed = 0;
$failed = 0;

function moveScenarioAssert(string $name, bool $condition): void
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

$tz = new DateTimeZone('Asia/Tehran');
$syncSource = file_get_contents(__DIR__ . '/../google-vacation/VacationSyncService.php');

moveScenarioAssert(
    'sync hydrates google events before parse',
    is_string($syncSource) && str_contains($syncSource, 'hydrateGoogleEventForVacationSync')
);
moveScenarioAssert(
    'recurring move reconcile exists',
    is_string($syncSource) && str_contains($syncSource, 'tryReconcileMovedRecurringInstance')
);
moveScenarioAssert(
    'legacy collapse dissolve is guarded',
    is_string($syncSource) && str_contains($syncSource, 'isLegacyCollapsedSeriesTracking')
);

$seriesId = 'weekly-vac-series';
$oldInstanceId = $seriesId . '_20260625T063000Z';
$newInstanceId = $seriesId . '_20260628T063000Z';

$partialMovedPayload = [
    'id' => $newInstanceId,
    'recurringEventId' => $seriesId,
    'summary' => 'مرخصی',
    'status' => 'confirmed',
    'updated' => '2026-06-24T12:00:00Z',
    'start' => [
        'date' => '2026-06-28',
        'dateTime' => '2026-06-28T10:30:00',
        'timeZone' => 'Asia/Tehran',
    ],
    'end' => [
        'date' => '2026-06-29',
        'dateTime' => '2026-06-28T11:30:00',
        'timeZone' => 'Asia/Tehran',
    ],
];

$fullTimedPayload = [
    'id' => $newInstanceId,
    'recurringEventId' => $seriesId,
    'summary' => 'مرخصی',
    'status' => 'confirmed',
    'start' => ['dateTime' => '2026-06-28T10:30:00', 'timeZone' => 'Asia/Tehran'],
    'end' => ['dateTime' => '2026-06-28T11:30:00', 'timeZone' => 'Asia/Tehran'],
];

$parsedPartial = GoogleEventParser::parseEvent($partialMovedPayload);
$parsedFull = GoogleEventParser::parseEvent($fullTimedPayload);
$expectedStart = (new DateTimeImmutable('2026-06-28 10:30:00', $tz))->getTimestamp();
$expectedEnd = (new DateTimeImmutable('2026-06-28 11:30:00', $tz))->getTimestamp();

moveScenarioAssert(
    'partial webhook payload keeps timed start (not midnight)',
    is_array($parsedPartial) && $parsedPartial['start_ts'] === $expectedStart
);
moveScenarioAssert(
    'partial webhook payload keeps timed end',
    is_array($parsedPartial) && $parsedPartial['end_ts'] === $expectedEnd
);
moveScenarioAssert(
    'full timed payload matches partial corrected times',
    is_array($parsedFull)
        && is_array($parsedPartial)
        && $parsedFull['start_ts'] === $parsedPartial['start_ts']
        && $parsedFull['end_ts'] === $parsedPartial['end_ts']
);

$multiDaySpan = [
    'id' => 'multi-span-27-30',
    'summary' => 'مرخصی چند روزه',
    'status' => 'confirmed',
    'start' => ['date' => '2026-06-27'],
    'end' => ['date' => '2026-06-30'],
];
$parsedSpan = GoogleEventParser::parseEvent($multiDaySpan);
moveScenarioAssert(
    'multi-day span 27-30 starts at 27 midnight',
    is_array($parsedSpan)
        && $parsedSpan['start_ts'] === (new DateTimeImmutable('2026-06-27 00:00:00', $tz))->getTimestamp()
);
moveScenarioAssert(
    'multi-day span 27-30 ends at 30 midnight (exclusive)',
    is_array($parsedSpan)
        && $parsedSpan['end_ts'] === (new DateTimeImmutable('2026-06-30 00:00:00', $tz))->getTimestamp()
);
moveScenarioAssert(
    'multi-day span is not treated as recurring',
    GoogleEventParser::extractRecurringSeriesKey($multiDaySpan) === null
);

$oldInstance = [
    'id' => $oldInstanceId,
    'recurringEventId' => $seriesId,
    'summary' => 'مرخصی',
    'status' => 'confirmed',
    'start' => ['dateTime' => '2026-06-25T10:30:00', 'timeZone' => 'Asia/Tehran'],
    'end' => ['dateTime' => '2026-06-25T11:30:00', 'timeZone' => 'Asia/Tehran'],
];
$parsedOld = GoogleEventParser::parseEvent($oldInstance);
moveScenarioAssert(
    'old recurring instance before move keeps 10:30',
    is_array($parsedOld)
        && $parsedOld['start_ts'] === (new DateTimeImmutable('2026-06-25 10:30:00', $tz))->getTimestamp()
);

echo PHP_EOL . "Total: {$passed} passed, {$failed} failed" . PHP_EOL;
exit($failed > 0 ? 1 : 0);
