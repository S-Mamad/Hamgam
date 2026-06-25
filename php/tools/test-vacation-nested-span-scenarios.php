<?php

declare(strict_types=1);

/**
 * Nested vacation span scenarios (single event moved inside multi-day block).
 * CLI: php php/tools/test-vacation-nested-span-scenarios.php
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/vacation-bootstrap.php';

hamgam_load_vacation_modules();

$passed = 0;
$failed = 0;

function nestedAssert(string $name, bool $condition): void
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

nestedAssert(
    'individual updates use delete+create (not risky Paziresh24 update)',
    is_string($syncSource) && str_contains($syncSource, 'replaceIndividualVacationByDeleteAndCreate')
);
nestedAssert(
    'recurring series uses collapsed metadata helper',
    is_string($syncSource) && str_contains($syncSource, 'attachCollapsedSeriesMetadata')
);
nestedAssert(
    'create does not skip nested events via covering guard',
    is_string($syncSource)
        && !preg_match('/findCoveringTrackedVacation\([^)]+\)\s*;\s*if\s*\(\$covering\s*!==\s*null\)\s*\{\s*return\s*\[\]/s', $syncSource)
);

$spanStart = (new DateTimeImmutable('2026-07-07 16:30:00', $tz))->getTimestamp();
$spanEnd = (new DateTimeImmutable('2026-07-09 17:30:00', $tz))->getTimestamp();
$saturdayStart = (new DateTimeImmutable('2026-07-06 16:15:00', $tz))->getTimestamp();
$saturdayEnd = (new DateTimeImmutable('2026-07-06 18:15:00', $tz))->getTimestamp();
$mondayMovedStart = (new DateTimeImmutable('2026-07-08 14:15:00', $tz))->getTimestamp();
$mondayMovedEnd = (new DateTimeImmutable('2026-07-08 15:15:00', $tz))->getTimestamp();

nestedAssert('monday slot is inside sunday-tuesday span', $mondayMovedStart >= $spanStart && $mondayMovedEnd <= $spanEnd);
nestedAssert('saturday slot is outside sunday-tuesday span', $saturdayEnd <= $spanStart);

$spanEvent = [
    'id' => 'multi-span',
    'summary' => 'مرخصی چند روزه',
    'status' => 'confirmed',
    'start' => ['dateTime' => '2026-07-07T16:30:00', 'timeZone' => 'Asia/Tehran'],
    'end' => ['dateTime' => '2026-07-09T17:30:00', 'timeZone' => 'Asia/Tehran'],
];
$parsedSpan = GoogleEventParser::parseEvent($spanEvent);
nestedAssert('multi-day timed span parses', is_array($parsedSpan) && $parsedSpan['start_ts'] === $spanStart && $parsedSpan['end_ts'] === $spanEnd);

$singleMoved = [
    'id' => 'single-moved',
    'summary' => 'مرخصی تکی',
    'status' => 'confirmed',
    'start' => ['dateTime' => '2026-07-08T14:15:00', 'timeZone' => 'Asia/Tehran'],
    'end' => ['dateTime' => '2026-07-08T15:15:00', 'timeZone' => 'Asia/Tehran'],
];
$parsedSingle = GoogleEventParser::parseEvent($singleMoved);
nestedAssert('moved single parses 14:15 monday', is_array($parsedSingle) && $parsedSingle['start_ts'] === $mondayMovedStart);

echo PHP_EOL . "Total: {$passed} passed, {$failed} failed" . PHP_EOL;
exit($failed > 0 ? 1 : 0);
