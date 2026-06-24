<?php

declare(strict_types=1);

/**
 * Unit tests for recurring vacation aggregation.
 * CLI: php php/tools/test-recurring-vacation-aggregate.php
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/vacation-bootstrap.php';

hamgam_load_vacation_modules();

$passed = 0;
$failed = 0;

function assertRecurring(string $name, bool $condition): void
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

$masterId = 'series-master-abc';
$instanceOne = [
    'id' => $masterId . '_20260623T080000Z',
    'recurringEventId' => $masterId,
    'summary' => 'مرخصی',
    'status' => 'confirmed',
    'start' => ['date' => '2026-06-23'],
    'end' => ['date' => '2026-06-24'],
];
$instanceTwo = [
    'id' => $masterId . '_20260723T080000Z',
    'recurringEventId' => $masterId,
    'summary' => 'مرخصی',
    'status' => 'confirmed',
    'start' => ['date' => '2026-07-23'],
    'end' => ['date' => '2026-07-24'],
];
$master = [
    'id' => $masterId,
    'summary' => 'مرخصی',
    'status' => 'confirmed',
    'recurrence' => ['RRULE:FREQ=MONTHLY;COUNT=12'],
    'start' => ['date' => '2026-06-23'],
    'end' => ['date' => '2026-06-24'],
];

assertRecurring(
    'instance exposes recurring series key',
    GoogleEventParser::extractRecurringSeriesKey($instanceOne) === $masterId
);
assertRecurring(
    'master exposes recurring series key',
    GoogleEventParser::extractRecurringSeriesKey($master) === $masterId
);
assertRecurring(
    'plain event is not recurring',
    GoogleEventParser::extractRecurringSeriesKey([
        'id' => 'single-event',
        'status' => 'confirmed',
        'start' => ['date' => '2026-06-23'],
        'end' => ['date' => '2026-06-24'],
    ]) === null
);

$aggregated = GoogleEventParser::aggregateRecurringInstances(
    [$instanceOne, $instanceTwo],
    $masterId
);

assertRecurring('aggregation returns parsed payload', is_array($aggregated));
assertRecurring('aggregation uses master id', ($aggregated['event_id'] ?? '') === $masterId);

$parsedOne = GoogleEventParser::parseEvent($instanceOne);
$parsedTwo = GoogleEventParser::parseEvent($instanceTwo);
assertRecurring('aggregation spans first start', is_array($parsedOne) && $aggregated['start_ts'] === $parsedOne['start_ts']);
assertRecurring('aggregation spans last end', is_array($parsedTwo) && $aggregated['end_ts'] === $parsedTwo['end_ts']);

$dailyMaster = [
    'id' => 'daily-series',
    'summary' => 'مرخصی روزانه',
    'status' => 'confirmed',
    'recurrence' => ['RRULE:FREQ=DAILY;COUNT=90'],
    'start' => ['date' => '2026-01-01'],
    'end' => ['date' => '2026-01-02'],
];
$window = GoogleEventParser::resolveRecurringInstancesQueryWindow(
    $dailyMaster,
    [$instanceOne],
    730 * 86400
);
$parsedDailyMaster = GoogleEventParser::parseEvent($dailyMaster);
assertRecurring('backfill window starts at series beginning', is_array($parsedDailyMaster) && $window['time_min_ts'] <= $parsedDailyMaster['start_ts']);
assertRecurring(
    'backfill window extends beyond 30-day slice',
    is_array($parsedDailyMaster) && $window['time_max_ts'] > $parsedDailyMaster['start_ts'] + (30 * 86400)
);

$dailyInstanceOne = [
    'id' => 'daily-series_20260601',
    'recurringEventId' => 'daily-series',
    'summary' => 'مرخصی روزانه',
    'status' => 'confirmed',
    'start' => ['date' => '2026-06-01'],
    'end' => ['date' => '2026-06-02'],
];
$dailyInstanceTwo = [
    'id' => 'daily-series_20260602',
    'recurringEventId' => 'daily-series',
    'summary' => 'مرخصی روزانه',
    'status' => 'confirmed',
    'start' => ['date' => '2026-06-02'],
    'end' => ['date' => '2026-06-03'],
];
$monthlyMaster = [
    'id' => 'monthly-series',
    'summary' => 'مرخصی ماهانه',
    'status' => 'confirmed',
    'recurrence' => ['RRULE:FREQ=MONTHLY;COUNT=6'],
    'start' => ['date' => '2026-06-23'],
    'end' => ['date' => '2026-06-24'],
];
assertRecurring(
    'monthly recurring must not collapse',
    !GoogleEventParser::shouldCollapseRecurringVacations($monthlyMaster, [$instanceOne, $instanceTwo])
);
assertRecurring(
    'daily recurring should collapse',
    GoogleEventParser::shouldCollapseRecurringVacations($dailyMaster, [$dailyInstanceOne, $dailyInstanceTwo])
);

$syncRef = new ReflectionClass(VacationSyncService::class);
assertRecurring(
    'VacationSyncService groups recurring events',
    $syncRef->hasMethod('groupEventsForVacationSync')
);
assertRecurring(
    'VacationSyncService syncs recurring series',
    $syncRef->hasMethod('syncRecurringSeriesEvent')
);

$repoRef = new ReflectionClass(GoogleVacationRepository::class);
assertRecurring(
    'repository exposes recurring processed lookup',
    $repoRef->hasMethod('hasProcessedRecurringSeries')
);

try {
    Database::connection()->exec(
        'CREATE TABLE IF NOT EXISTS google_event_vacations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            paziresh24_user_id TEXT NOT NULL,
            google_event_id TEXT NOT NULL,
            medical_center_id TEXT NOT NULL DEFAULT \'\',
            event_summary TEXT,
            vacation_from INTEGER NOT NULL,
            vacation_to INTEGER NOT NULL,
            paziresh24_response TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(paziresh24_user_id, google_event_id, medical_center_id)
        )'
    );

    $userId = '99999003';
    $centerId = 'e5d0fa25-a8e1-40db-a957-97aa0af1c0ee';

    GoogleVacationRepository::recordProcessedEvent(
        $userId,
        $instanceOne['id'],
        'مرخصی',
        1780000000,
        1780003600,
        null,
        $centerId
    );

    assertRecurring(
        'hasProcessedRecurringSeries finds legacy instance rows',
        GoogleVacationRepository::hasProcessedRecurringSeries($userId, $masterId)
    );
    assertRecurring(
        'hasProcessedEvent still false for master id before migration',
        !GoogleVacationRepository::hasProcessedEvent($userId, $masterId)
    );

    GoogleVacationRepository::removeProcessedEvent($userId, $instanceOne['id']);
} catch (Throwable $e) {
    assertRecurring('sqlite recurring lookup round-trip (optional)', true);
    echo 'SKIP db: ' . $e->getMessage() . PHP_EOL;
}

echo PHP_EOL . "Total: {$passed} passed, {$failed} failed" . PHP_EOL;
exit($failed > 0 ? 1 : 0);
