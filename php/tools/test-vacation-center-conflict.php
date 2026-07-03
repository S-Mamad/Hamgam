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
    'cancelOverlappingAppointments receives vacationCenter array',
    in_array('vacationCenter', $cancelParams, true),
    $cancelParams
);

assertTest(
    'VacationSyncService has resolveOverlappingAppointmentsFromConflictBody',
    $syncRef->hasMethod('resolveOverlappingAppointmentsFromConflictBody')
);

assertTest(
    'VacationSyncService has clearConflictingAppointmentsBeforeVacation',
    $syncRef->hasMethod('clearConflictingAppointmentsBeforeVacation')
);

assertTest(
    'VacationSyncService has buildOverlappingAppointmentTargetFromApi',
    $syncRef->hasMethod('buildOverlappingAppointmentTargetFromApi')
);

$fromApiMethod = $syncRef->getMethod('buildOverlappingAppointmentTargetFromApi');
$fromApiParams = array_map(static fn (ReflectionParameter $p) => $p->getName(), $fromApiMethod->getParameters());
assertTest(
    'buildOverlappingAppointmentTargetFromApi accepts optional googleEvent',
    in_array('googleEvent', $fromApiParams, true),
    $fromApiParams
);

assertTest(
    'GoogleCalendarBookingRepository has getBookIdByGoogleEventId',
    method_exists(GoogleCalendarBookingRepository::class, 'getBookIdByGoogleEventId')
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

$bookingWithIdOnly = [
    'id' => $bookId,
    'medical_center_id' => $centerA,
    'patient_name' => 'Ali',
    'patient_family' => 'Test',
];
$builtFromId = CalendarEventBuilder::build(
    ['from' => 1781658000, 'to' => 1781661600],
    ['color_id' => '9', 'Patient_name' => 1],
    $bookingWithIdOnly
);
assertTest(
    'CalendarEventBuilder stores hamgam_book_id when API returns id instead of book_id',
    is_array($builtFromId)
        && ($builtFromId['extendedProperties']['private']['hamgam_book_id'] ?? null) === $bookId,
    is_array($builtFromId) ? ($builtFromId['extendedProperties']['private']['hamgam_book_id'] ?? null) : null
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
    'Paziresh24AppointmentApi has getFirstAvailableSlot',
    method_exists(Paziresh24AppointmentApi::class, 'getFirstAvailableSlot')
);

assertTest(
    'Paziresh24AppointmentApi has moveAppointmentResult',
    method_exists(Paziresh24AppointmentApi::class, 'moveAppointmentResult')
);

assertTest(
    'extractFirstWorkhourTurnNum picks earliest future slot',
    Paziresh24AppointmentApi::extractFirstWorkhourTurnNum([
        'data' => [
            ['workhour_turn_num' => 1_700_000_000],
            ['workhour_turn_num' => 1_800_000_000],
        ],
    ], 1_750_000_000) === 1_800_000_000
);

assertTest(
    'extractFirstWorkhourTurnNum skips slots inside vacation range',
    Paziresh24AppointmentApi::extractFirstWorkhourTurnNum([
        'data' => [
            ['workhour_turn_num' => 1_700_000_000],
            ['workhour_turn_num' => 1_750_000_000],
            ['workhour_turn_num' => 1_800_000_000],
        ],
    ], 0, 1_720_000_000, 1_780_000_000) === 1_700_000_000
);

assertTest(
    'extractFirstWorkhourTurnNum reads from field in slot item',
    Paziresh24AppointmentApi::extractFirstWorkhourTurnNum([
        'slots' => [
            ['from' => 1782073800],
        ],
    ]) === 1782073800
);

assertTest(
    'extractFirstWorkhourTurnNum reads slots nested under center UUID key',
    Paziresh24AppointmentApi::extractFirstWorkhourTurnNum([
        'a1111111-1111-4111-8111-111111111111' => [
            'slots' => [
                ['from' => 1782073800],
            ],
        ],
    ]) === 1782073800
);

assertTest(
    'Paziresh24AppointmentApi has resolveMoveRange',
    method_exists(Paziresh24AppointmentApi::class, 'resolveMoveRange')
);

assertTest(
    'extractUserCenterId reads user_center_id from booking payload',
    BookingAppointmentResolver::extractUserCenterId([
        'user_center_id' => 'uc-test-123',
    ]) === 'uc-test-123'
);

assertTest(
    'extractRangeFromPayload reads from_date and from_hour',
    BookingAppointmentResolver::extractRangeFromPayload([
        'from_date' => '2026-06-20',
        'from_hour' => '10:30',
        'to' => '1781932500',
    ]) !== null
);

assertTest(
    'resolveUserCenterIdForReschedule falls back to medical_center_id',
    BookingAppointmentResolver::resolveUserCenterIdForReschedule(
        null,
        '',
        'a1111111-1111-4111-8111-111111111111',
        null
    ) === 'a1111111-1111-4111-8111-111111111111'
);

assertTest(
    'extractFirstWorkhourTurnNum prefers workhour_turn_num over from',
    Paziresh24AppointmentApi::extractFirstWorkhourTurnNum([
        'data' => [
            ['from' => 1782073800, 'workhour_turn_num' => 1782077400],
        ],
    ]) === 1782077400
);

assertTest(
    'extractFirstWorkhourTurnNum reads slots nested under date key',
    Paziresh24AppointmentApi::extractFirstWorkhourTurnNum([
        '2026-07-15' => [
            'slots' => [
                ['workhour_turn_num' => 1784226600],
            ],
        ],
    ]) === 1784226600
);

assertTest(
    'extractFirstWorkhourTurnNum skips exact appointment timestamp',
    Paziresh24AppointmentApi::extractFirstWorkhourTurnNum([
        'data' => [
            ['workhour_turn_num' => 1782073800],
            ['workhour_turn_num' => 1782077400],
        ],
    ], 0, null, null, 1782073800) === 1782077400
);

assertTest(
    'extractFirstWorkhourTurnNum picks next day when today is inside vacation',
    Paziresh24AppointmentApi::extractFirstWorkhourTurnNum([
        '2026-06-28' => [
            ['workhour_turn_num' => 1_780_000_000],
        ],
        '2026-06-29' => [
            ['workhour_turn_num' => 1_790_000_000],
        ],
    ], 0, 1_770_000_000, 1_785_000_000) === 1_790_000_000
);

assertTest(
    'resolveMoveRangeFromAppointment prefers workhour_turn_num for book_from',
    Paziresh24AppointmentApi::resolveMoveRangeFromAppointment([
        'from' => 1782073800,
        'to' => 1782075600,
        'workhour_turn_num' => 1782077400,
        'duration' => 30,
    ])['from'] === 1782077400
);

assertTest(
    'resolveMoveRangeFromAppointment normalizes book_to from duration',
    (static function (): bool {
        $range = Paziresh24AppointmentApi::resolveMoveRangeFromAppointment([
            'workhour_turn_num' => 1782077400,
            'duration' => 30,
        ]);

        return $range['to'] === 1782077400 + (30 * 60);
    })()
);

assertTest(
    'replaceIndividualVacation reschedules appointments before deleting old vacation',
    (static function (): bool {
        $source = file_get_contents(__DIR__ . '/../google-vacation/VacationSyncService.php');
        if (!is_string($source)) {
            return false;
        }

        $start = strpos($source, 'private static function replaceIndividualVacationByDeleteAndCreate');
        if ($start === false) {
            return false;
        }

        $chunk = substr($source, $start, 2500);

        return str_contains($chunk, 'clearConflictingAppointmentsBeforeVacation')
            && str_contains($chunk, 'deleteVacation')
            && strpos($chunk, 'clearConflictingAppointmentsBeforeVacation') < strpos($chunk, 'deleteVacation');
    })()
);

assertTest(
    'resolveSlotSearchRange spans three months from start of today',
    (static function (): bool {
        $range = Paziresh24AppointmentApi::resolveSlotSearchRange();
        if ($range === null) {
            return false;
        }

        return $range['range_end'] > $range['range_start']
            && ($range['range_end'] - $range['range_start']) >= (89 * 86400);
    })()
);

assertTest(
    'extractWorkhourTurnNumCandidates returns multiple ordered slots',
    Paziresh24AppointmentApi::extractWorkhourTurnNumCandidates([
        'data' => [
            ['workhour_turn_num' => 1_800_000_000],
            ['workhour_turn_num' => 1_790_000_000],
            ['workhour_turn_num' => 1_810_000_000],
        ],
    ], 0, null, null, null, 2) === [1_790_000_000, 1_800_000_000]
);

assertTest(
    'extractWorkhourTurnNumCandidates skips slots before vacation end',
    Paziresh24AppointmentApi::extractWorkhourTurnNumCandidates([
        'data' => [
            ['workhour_turn_num' => 1_780_000_000],
            ['workhour_turn_num' => 1_790_000_000],
        ],
    ], 1_785_000_000, 1_770_000_000, 1_785_000_000) === [1_790_000_000]
);

assertTest(
    'VacationSyncService has resolveOverlappingAppointmentRange',
    $syncRef->hasMethod('resolveOverlappingAppointmentRange')
);

$rangesOverlapMethod = $syncRef->getMethod('rangesOverlap');
$rangesOverlapMethod->setAccessible(true);
assertTest(
    'rangesOverlap true when calendar event intersects vacation after API move',
    $rangesOverlapMethod->invoke(null, 1_780_000_000, 1_780_003_600, 1_779_990_000, 1_780_010_000) === true
);
assertTest(
    'rangesOverlap false when API time no longer intersects vacation window',
    $rangesOverlapMethod->invoke(null, 1_790_000_000, 1_790_003_600, 1_779_990_000, 1_780_010_000) === false
);

assertTest(
    'VacationSyncService has resolveOverlappingAppointments',
    $syncRef->hasMethod('resolveOverlappingAppointments')
);

assertTest(
    'Paziresh24AppointmentApi has rescheduleToFirstAvailableSlot',
    method_exists(Paziresh24AppointmentApi::class, 'rescheduleToFirstAvailableSlot')
);

assertTest(
    'Paziresh24AppointmentApi has moveAppointmentWithCenterFallback',
    method_exists(Paziresh24AppointmentApi::class, 'moveAppointmentWithCenterFallback')
);

$appointmentApiSource = (string) file_get_contents(__DIR__ . '/../includes/Paziresh24AppointmentApi.php');
assertTest(
    'moveAppointmentResult default URL is openapi booking move',
    str_contains($appointmentApiSource, "'https://openapi.paziresh24.com/v1/booking/move'")
);
assertTest(
    'moveCenterPathCandidates prefers medical_center_id before user_center_id',
    str_contains($appointmentApiSource, 'foreach ([$medicalCenterId, $userCenterId] as $candidate)')
);

assertTest(
    'isMoveApiSuccessBody rejects NO_RECORD',
    !Paziresh24AppointmentApi::isMoveApiSuccessBody(['status' => 'NO_RECORD', 'message' => 'No record found'])
);

assertTest(
    'isMoveApiSuccessBody accepts SUCCESS with shifted_books',
    Paziresh24AppointmentApi::isMoveApiSuccessBody([
        'status' => 'SUCCESS',
        'result' => ['shifted_books' => [['new_book' => ['id' => 'test-book']]]],
    ])
);

assertTest(
    'AppointmentWebhookService has syncCalendarFromApiMove',
    method_exists(AppointmentWebhookService::class, 'syncCalendarFromApiMove')
);

assertTest(
    'VacationSyncService has processUpdatedAppointmentEvent',
    $syncRef->hasMethod('processUpdatedAppointmentEvent')
);

assertTest(
    'VacationSyncService has appointmentTimestampsMatch',
    $syncRef->hasMethod('appointmentTimestampsMatch')
);

$timestampMatchMethod = $syncRef->getMethod('appointmentTimestampsMatch');
$timestampMatchMethod->setAccessible(true);
assertTest(
    'appointmentTimestampsMatch treats same slot as equal',
    $timestampMatchMethod->invoke(null, 1782073800, 1782073800) === true
);
assertTest(
    'appointmentTimestampsMatch treats moved slot as different',
    $timestampMatchMethod->invoke(null, 1782073800, 1782077400) === false
);

assertTest(
    'BookingAppointmentResolver has resolveUserCenterIdForReschedule',
    method_exists(BookingAppointmentResolver::class, 'resolveUserCenterIdForReschedule')
);

assertTest(
    'normalizeUnixTimestamp rejects small turn numbers',
    Paziresh24AppointmentApi::extractFirstWorkhourTurnNum([
        'data' => [['turn_num' => 42], ['from' => 1782073800]],
    ]) === 1782073800
);

assertTest(
    'VacationSyncService has findOverlappingAppointments',
    $syncRef->hasMethod('findOverlappingAppointments')
);

assertTest(
    'default cancel conflict is reschedule (false)',
    !GoogleTokensRepository::isCancelConflictingAppointmentsEnabled(null)
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

assertTest(
    'extractBookIdsFromConflictBody reads book_id field',
    Paziresh24VacationApi::extractBookIdsFromConflictBody([
        'status' => 'BOOK_CONFLICT',
        'book_id' => 'a1111111-1111-4111-8111-111111111111',
    ]) === ['a1111111-1111-4111-8111-111111111111']
);

assertTest(
    'extractBookIdsFromConflictBody reads nested book_ids',
    Paziresh24VacationApi::extractBookIdsFromConflictBody([
        'data' => [
            'book_ids' => [
                'b2222222-2222-4222-8222-222222222222',
                'c3333333-3333-4333-8333-333333333333',
            ],
        ],
    ]) === [
        'b2222222-2222-4222-8222-222222222222',
        'c3333333-3333-4333-8333-333333333333',
    ]
);

assertTest(
    'extractBookIdsFromConflictBody reads UUIDs from message text',
    Paziresh24VacationApi::extractBookIdsFromConflictBody([
        'status' => 'BOOK_CONFLICT',
        'message' => 'conflict with book d3fe846f-6b15-11f1-8fe5-b6c09fdc72a4',
    ]) === ['d3fe846f-6b15-11f1-8fe5-b6c09fdc72a4']
);

assertTest(
    'slot fallback does not pick time inside vacation range',
    Paziresh24AppointmentApi::extractFirstWorkhourTurnNum([
        'data' => [
            ['from' => 1_750_000_000],
        ],
    ], 0, 1_740_000_000, 1_760_000_000) === null
);

echo json_encode([
    'ok' => $failed === 0,
    'passed' => $passed,
    'failed' => $failed,
    'tests' => $tests,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;

exit($failed > 0 ? 1 : 0);
