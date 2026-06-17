<?php

declare(strict_types=1);

/**
 * Unit tests for appointment-related settings parsing and flags.
 *
 * CLI: php -c dev/php.ini php/tools/test-appointment-settings.php
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/vacation-bootstrap.php';

if (ob_get_level()) {
    ob_end_clean();
}

hamgam_load_vacation_modules();
require_once __DIR__ . '/../google-vacation/VacationSyncService.php';

$tests = [];
$passed = 0;
$failed = 0;

function assertTest(string $name, bool $condition, mixed $detail = null): void
{
    global $tests, $passed, $failed;

    if ($condition) {
        $passed++;
        $tests[] = ['name' => $name, 'ok' => true];
        return;
    }

    $failed++;
    $tests[] = [
        'name' => $name,
        'ok' => false,
        'detail' => $detail,
    ];
}

function parseSettingsBody(array $body): ?array
{
    $ref = new ReflectionClass(GoogleTokensRepository::class);
    $method = $ref->getMethod('parseSettingsBody');
    $method->setAccessible(true);

    /** @var array<string, mixed>|null $parsed */
    $parsed = $method->invoke(null, $body);

    return $parsed;
}

$basePayload = [
    'colorId' => '9',
    'fullName' => true,
    'datetime' => false,
    'nationalId' => false,
    'phone' => false,
    'autoVacation' => true,
    'importFutureVacations' => false,
    'cancelAppointmentOnEventDelete' => true,
    'cancelConflictingAppointments' => false,
    'vacationSyncCenters' => [
        'mode' => 'selected',
        'centerIds' => ['e5d0fa25-a8e1-40db-a957-97aa0af1c0ee'],
    ],
];

$parsed = parseSettingsBody($basePayload);
assertTest('parseSettingsBody accepts appointment flags', is_array($parsed));
assertTest('cancel_appointment_on_event_delete parsed as true', ($parsed['cancel_appointment_on_event_delete'] ?? null) === true);
assertTest('cancel_conflicting_appointments parsed as false', ($parsed['cancel_conflicting_appointments'] ?? null) === false);

$offPayload = $basePayload;
$offPayload['autoVacation'] = false;
$offPayload['cancelAppointmentOnEventDelete'] = true;
$offPayload['cancelConflictingAppointments'] = true;

$parsedOff = parseSettingsBody($offPayload);
assertTest('appointment flags forced false when autoVacation off', ($parsedOff['cancel_appointment_on_event_delete'] ?? null) === false);
assertTest('conflict flag forced false when autoVacation off', ($parsedOff['cancel_conflicting_appointments'] ?? null) === false);

$defaults = GoogleTokensRepository::getSettings(null);
assertTest('default cancel on delete is true', $defaults['cancel_appointment_on_event_delete'] === true);
assertTest('default cancel conflict is true', $defaults['cancel_conflicting_appointments'] === true);

$rowEnabled = [
    'cancel_appointment_on_event_delete' => 1,
    'cancel_conflicting_appointments' => 0,
];
assertTest('isCancelAppointmentOnEventDeleteEnabled reads row true', GoogleTokensRepository::isCancelAppointmentOnEventDeleteEnabled($rowEnabled));
assertTest('isCancelConflictingAppointmentsEnabled reads row false', !GoogleTokensRepository::isCancelConflictingAppointmentsEnabled($rowEnabled));

$settingsFromRow = GoogleTokensRepository::getSettings($rowEnabled);
assertTest('getSettings maps cancel_appointment_on_event_delete', $settingsFromRow['cancel_appointment_on_event_delete'] === true);
assertTest('getSettings maps cancel_conflicting_appointments', $settingsFromRow['cancel_conflicting_appointments'] === false);

$ref = new ReflectionClass(VacationSyncService::class);
assertTest('VacationSyncService has processDeletedAppointmentEvent', $ref->hasMethod('processDeletedAppointmentEvent'));

$method = $ref->getMethod('processDeletedAppointmentEvent');
$params = array_map(static fn (ReflectionParameter $p) => $p->getName(), $method->getParameters());
assertTest('processDeletedAppointmentEvent receives tokenRow', in_array('tokenRow', $params, true));

$appointmentWithBookId = [
    'id' => 'evt-book-id-test',
    'summary' => 'Ali Rezaei',
    'status' => 'confirmed',
    'description' => 'hamgam_book_id: bc9437f4-0000-4000-8000-000000000001',
    'start' => ['dateTime' => '2026-06-20T10:00:00', 'timeZone' => 'Asia/Tehran'],
    'end' => ['dateTime' => '2026-06-20T11:00:00', 'timeZone' => 'Asia/Tehran'],
];
assertTest(
    'backfill skips appointment events with book_id',
    GoogleEventParser::isHamgamAppointmentEvent($appointmentWithBookId)
        && GoogleEventParser::extractBookId($appointmentWithBookId) !== null
);
assertTest(
    'deleted appointment still detected via book_id',
    GoogleEventParser::isHamgamAppointmentEvent(array_merge($appointmentWithBookId, ['status' => 'cancelled']))
);

$repoRef = new ReflectionClass(GoogleVacationRepository::class);
assertTest(
    'GoogleVacationRepository has related event lookup',
    $repoRef->hasMethod('findProcessedEventsRelatedToGoogleEvent')
);

$syncRef = new ReflectionClass(VacationSyncService::class);
assertTest(
    'VacationSyncService has untracked delete fallback',
    $syncRef->hasMethod('buildUntrackedDeletedVacationTargets')
);

$centersPayload = $basePayload;
$centersPayload['vacationSyncCenters'] = [
    'mode' => 'selected',
    'centerIds' => ['e5d0fa25-a8e1-40db-a957-97aa0af1c0ee'],
];
$parsedCenters = parseSettingsBody($centersPayload);
assertTest(
    'vacationSyncCenters selected parsed',
    is_array($parsedCenters)
        && ($parsedCenters['vacation_sync_centers']['mode'] ?? '') === 'selected'
        && ($parsedCenters['vacation_sync_centers']['center_ids'][0] ?? '') === 'e5d0fa25-a8e1-40db-a957-97aa0af1c0ee'
);

$invalidCentersPayload = $basePayload;
$invalidCentersPayload['vacationSyncCenters'] = ['mode' => 'selected', 'centerIds' => []];
assertTest('vacationSyncCenters empty rejected when autoVacation on', parseSettingsBody($invalidCentersPayload) === null);

$legacySelection = GoogleTokensRepository::parseVacationSyncCentersFromRow([
    'center_id' => 'e5d0fa25-a8e1-40db-a957-97aa0af1c0ee',
]);
assertTest(
    'legacy center_id maps to selected mode for sync',
    $legacySelection['mode'] === 'selected'
        && ($legacySelection['center_ids'][0] ?? '') === 'e5d0fa25-a8e1-40db-a957-97aa0af1c0ee'
);

$legacyUiSelection = GoogleTokensRepository::parseVacationSyncCentersFromRow([
    'center_id' => 'e5d0fa25-a8e1-40db-a957-97aa0af1c0ee',
], false);
assertTest(
    'legacy center_id is ignored for settings UI',
    $legacyUiSelection['mode'] === 'selected'
        && $legacyUiSelection['center_ids'] === []
);

$legacySettings = GoogleTokensRepository::getSettings([
    'center_id' => 'e5d0fa25-a8e1-40db-a957-97aa0af1c0ee',
    'color_id' => '9',
    'Patient_name' => 1,
    'Patient_date_time' => 0,
    'Patient_national' => 0,
    'Patient_phone' => 0,
    'auto_vacation' => 1,
    'import_future_vacations' => 0,
    'cancel_appointment_on_event_delete' => 1,
    'cancel_conflicting_appointments' => 1,
]);
assertTest(
    'getSettings does not preselect legacy center_id',
    ($legacySettings['vacation_sync_centers']['mode'] ?? '') === 'selected'
        && ($legacySettings['vacation_sync_centers']['center_ids'] ?? null) === []
);

$basePayload['vacationSyncCenters'] = ['mode' => 'all', 'centerIds' => []];

$dbOk = null;
$dbDetail = 'skipped: DB unavailable locally';
try {
    Database::connection();
    $updated = GoogleTokensRepository::updateSettings('99999001', $basePayload);
    $row = GoogleTokensRepository::findByUserId('99999001');
    $saved = GoogleTokensRepository::getSettings($row);
    $dbOk = $updated === true
        && $saved['cancel_appointment_on_event_delete'] === true
        && $saved['cancel_conflicting_appointments'] === false;
    $dbDetail = $dbOk ? 'sqlite/mysql round-trip ok' : 'save mismatch';
    GoogleTokensRepository::deleteByUserId('99999001');
} catch (Throwable $e) {
    $dbDetail = 'skipped: ' . $e->getMessage();
}

assertTest(
    'database round-trip save (optional)',
    $dbOk === null || $dbOk === true,
    $dbDetail
);

if (PHP_SAPI === 'cli') {
    echo "=== Appointment settings tests ===\n";
    foreach ($tests as $test) {
        $mark = $test['ok'] ? 'PASS' : 'FAIL';
        echo $mark . ' ' . $test['name'];
        if (!$test['ok'] && isset($test['detail'])) {
            echo ' :: ' . json_encode($test['detail'], JSON_UNESCAPED_UNICODE);
        }
        echo "\n";
    }
    echo "\nTotal: {$passed} passed, {$failed} failed\n";
    exit($failed > 0 ? 1 : 0);
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'ok' => $failed === 0,
    'passed' => $passed,
    'failed' => $failed,
    'tests' => $tests,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
