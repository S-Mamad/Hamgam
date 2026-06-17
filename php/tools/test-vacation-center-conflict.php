<?php

declare(strict_types=1);

/**
 * Unit tests for per-center vacation conflict resolution.
 *
 * CLI: php -c dev/php.ini php/tools/test-vacation-center-conflict.php
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

$centerA = 'e5d0fa25-a8e1-40db-a957-97aa0af1c0ee';
$centerB = 'f6e1fb36-b9f2-51ec-b068-a8bb1bf1d1ff';
$bookId = 'bc9437f4-0000-4000-8000-000000000001';

$syncRef = new ReflectionClass(VacationSyncService::class);

$cancelMethod = $syncRef->getMethod('cancelOverlappingAppointments');
$cancelParams = array_map(static fn (ReflectionParameter $p) => $p->getName(), $cancelMethod->getParameters());
assertTest(
    'cancelOverlappingAppointments has medicalCenterId parameter',
    in_array('medicalCenterId', $cancelParams, true),
    $cancelParams
);

assertTest(
    'VacationSyncService has updateVacationWithConflictResolution',
    $syncRef->hasMethod('updateVacationWithConflictResolution')
);

$updateMethod = $syncRef->getMethod('updateVacationWithConflictResolution');
$updateParams = array_map(static fn (ReflectionParameter $p) => $p->getName(), $updateMethod->getParameters());
assertTest(
    'updateVacationWithConflictResolution receives vacationCenter',
    in_array('vacationCenter', $updateParams, true),
    $updateParams
);

assertTest(
    'BookingAppointmentResolver has resolveAppointmentMedicalCenterId',
    method_exists(BookingAppointmentResolver::class, 'resolveAppointmentMedicalCenterId')
);

assertTest(
    'BookingAppointmentResolver has appointmentMatchesVacationCenter',
    method_exists(BookingAppointmentResolver::class, 'appointmentMatchesVacationCenter')
);

$eventWithCenter = [
    'extendedProperties' => [
        'private' => [
            'hamgam_book_id' => $bookId,
            'hamgam_center_id' => $centerA,
        ],
    ],
];

$resolvedFromEvent = BookingAppointmentResolver::resolveAppointmentMedicalCenterId(
    $bookId,
    '',
    $eventWithCenter
);
assertTest(
    'resolveAppointmentMedicalCenterId reads hamgam_center_id from extendedProperties',
    $resolvedFromEvent === $centerA,
    $resolvedFromEvent
);

assertTest(
    'appointmentMatchesVacationCenter true when centers match',
    BookingAppointmentResolver::appointmentMatchesVacationCenter($bookId, $centerA, '', $eventWithCenter)
);

assertTest(
    'appointmentMatchesVacationCenter false when centers differ',
    !BookingAppointmentResolver::appointmentMatchesVacationCenter($bookId, $centerB, '', $eventWithCenter)
);

$bookingPayload = [
    'book_id' => $bookId,
    'medical_center_id' => $centerA,
];
assertTest(
    'extractCenterId reads medical_center_id from booking payload',
    BookingAppointmentResolver::extractCenterId($bookingPayload) === $centerA
);

$builtEvent = CalendarEventBuilder::build(
    ['from' => 1781658000, 'to' => 1781661600],
    ['color_id' => '9'],
    [
        'book_id' => $bookId,
        'medical_center_id' => $centerA,
        'patient_name' => 'Ali',
        'patient_family' => 'Test',
    ]
);
$builtCenterId = is_array($builtEvent)
    ? ($builtEvent['extendedProperties']['private']['hamgam_center_id'] ?? null)
    : null;
assertTest(
    'CalendarEventBuilder stores hamgam_center_id in extendedProperties',
    $builtCenterId === $centerA,
    $builtCenterId
);

assertTest(
    'Paziresh24VacationApi has updateVacationResult',
    method_exists(Paziresh24VacationApi::class, 'updateVacationResult')
);

assertTest(
    'isBookConflictResponse detects HTTP 409',
    Paziresh24VacationApi::isBookConflictResponse(409, null)
);

assertTest(
    'isBookConflictResponse detects BOOK_CONFLICT status body',
    Paziresh24VacationApi::isBookConflictResponse(400, ['status' => 'BOOK_CONFLICT'])
);

assertTest(
    'isBookConflictResponse false for unrelated 400',
    !Paziresh24VacationApi::isBookConflictResponse(400, ['status' => 'VALIDATION_ERROR'])
);

echo json_encode([
    'ok' => $failed === 0,
    'passed' => $passed,
    'failed' => $failed,
    'tests' => $tests,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;

exit($failed > 0 ? 1 : 0);
