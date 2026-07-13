<?php

declare(strict_types=1);

/**
 * Logic tests for appointment update/cancel webhook handling.
 *
 * CLI: php php/tools/test-appointment-webhook-lifecycle.php
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/Paziresh24WebhookPayload.php';
require_once __DIR__ . '/../includes/AppointmentWebhookService.php';
require_once __DIR__ . '/../includes/GoogleEventParser.php';
require_once __DIR__ . '/../includes/vacation-bootstrap.php';

hamgam_load_vacation_modules();
require_once __DIR__ . '/../google-vacation/VacationSyncService.php';

if (ob_get_level()) {
    ob_end_clean();
}

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

function invokePrivate(string $class, string $method, mixed ...$args): mixed
{
    $ref = new ReflectionClass($class);
    $m = $ref->getMethod($method);
    $m->setAccessible(true);

    return $m->invoke(null, ...$args);
}

$updatePayload = [
    'event' => 'appointment.updated',
    'data' => [
        'book_id' => 'd3fe846f-6b15-11f1-8fe5-b6c09fdc72a4',
        'doctor_user_id' => 23489442,
        'after_update_record' => [
            'id' => 'd3fe846f-6b15-11f1-8fe5-b6c09fdc72a4',
            'from' => '1781931600',
            'to' => '1781932500',
            'from_date' => '2026-06-20',
            'from_hour' => '08:30',
            'user_id' => '61d1b42a-4fa2-11f1-89cc-fa163e8a0bb8',
        ],
        'before_update_record' => [
            'from' => '1781929800',
            'to' => '1781930700',
            'from_hour' => '08:00',
        ],
    ],
];

$updateBooking = Paziresh24WebhookPayload::extractBooking($updatePayload);
assertTest(
    'update payload maps to provider.appointment.updated',
    Paziresh24WebhookPayload::extractEventType($updatePayload) === 'provider.appointment.updated'
);
assertTest(
    'update booking keeps doctor_user_id over record user_id',
    Paziresh24WebhookPayload::extractDoctorUserId($updateBooking ?? []) === '23489442'
);

$updateRange = BookingAppointmentResolver::resolveForUpdate(
    $updateBooking ?? [],
    'd3fe846f-6b15-11f1-8fe5-b6c09fdc72a4',
    'unused-token-for-test'
);
assertTest(
    'resolveForUpdate uses webhook timestamps (08:30 slot)',
    is_array($updateRange)
        && ($updateRange['from'] ?? null) === 1781931600
        && ($updateRange['to'] ?? null) === 1781932500,
    $updateRange
);

$createBooking = [
    'from' => 1781931600,
    'to' => 1781932500,
    'from_date' => '2026-06-20',
    'from_hour' => '08:30',
];
$createRange = BookingAppointmentResolver::resolve(
    $createBooking,
    'd3fe846f-6b15-11f1-8fe5-b6c09fdc72a4',
    'unused-token-for-test'
);
assertTest(
    'resolve prefers webhook timestamps on create',
    is_array($createRange)
        && ($createRange['from'] ?? null) === 1781931600
        && ($createRange['to'] ?? null) === 1781932500,
    $createRange
);

$slotOvershootBooking = [
    'from' => 1781932500,
    'to' => 1781933400,
    'from_date' => '2026-06-20',
    'from_hour' => '08:30',
    'duration' => 15,
];
$slotOvershootRange = BookingAppointmentResolver::resolveForUpdate(
    $slotOvershootBooking,
    'd3fe846f-6b15-11f1-8fe5-b6c09fdc72a4',
    'unused-token-for-test'
);
assertTest(
    '15-minute slot overshoot prefers from_date/from_hour',
    is_array($slotOvershootRange)
        && ($slotOvershootRange['from'] ?? null) === 1781931600
        && ($slotOvershootRange['to'] ?? null) === 1781932500,
    $slotOvershootRange
);

$bookId = 'bc9437f4-0000-4000-8000-000000000001';

$builtEvent = CalendarEventBuilder::build(
    ['from' => 1781931600, 'to' => 1781932500],
    ['color_id' => '9'],
    ['book_id' => $bookId]
);
$builtStart = is_array($builtEvent) ? (string) ($builtEvent['start']['dateTime'] ?? '') : '';
assertTest(
    'CalendarEventBuilder sends wall-clock dateTime without offset',
    is_array($builtEvent)
        && $builtStart === '2026-06-20T08:30:00'
        && !str_contains($builtStart, '+'),
    $builtStart
);
$parsedBuilt = GoogleEventParser::parseEvent(is_array($builtEvent) ? $builtEvent + ['id' => 'roundtrip-test'] : []);
assertTest(
    'CalendarEventBuilder round-trips through GoogleEventParser',
    is_array($parsedBuilt)
        && ($parsedBuilt['start_ts'] ?? null) === 1781931600
        && ($parsedBuilt['end_ts'] ?? null) === 1781932500,
    $parsedBuilt
);

$cancelPayload = [
    'event' => 'appointment.cancelled',
    'data' => [
        'book_id' => 'd3fe846f-6b15-11f1-8fe5-b6c09fdc72a4',
        'doctor_user_id' => 23489442,
        'book_record' => [
            'id' => 'd3fe846f-6b15-11f1-8fe5-b6c09fdc72a4',
            'from' => '1781931600',
            'to' => '1781932500',
            'user_id' => '61d1b42a-4fa2-11f1-89cc-fa163e8a0bb8',
        ],
    ],
];
$cancelBooking = Paziresh24WebhookPayload::extractBooking($cancelPayload);
assertTest(
    'cancel payload maps to provider.appointment.cancelled',
    Paziresh24WebhookPayload::extractEventType($cancelPayload) === 'provider.appointment.cancelled'
);
assertTest(
    'cancel booking extracts book_id from book_record',
    Paziresh24WebhookPayload::extractBookId($cancelBooking ?? []) === 'd3fe846f-6b15-11f1-8fe5-b6c09fdc72a4'
);

$events = [
    ['id' => 'event-old', 'extendedProperties' => ['private' => ['hamgam_book_id' => 'book-1']]],
    ['id' => 'event-new', 'extendedProperties' => ['private' => ['hamgam_book_id' => 'book-1']]],
];
$picked = invokePrivate(AppointmentWebhookService::class, 'pickEventForUpdate', $events, 'event-old');
assertTest(
    'pickEventForUpdate prefers stored google_event_id',
    is_array($picked) && ($picked['id'] ?? null) === 'event-old'
);

$withStoredOnly = invokePrivate(AppointmentWebhookService::class, 'pickEventForUpdate', [], 'stored-id');
assertTest(
    'pickEventForUpdate falls back to stored id when calendar search is empty',
    is_array($withStoredOnly) && ($withStoredOnly['id'] ?? null) === 'stored-id'
);

$merged = invokePrivate(
    AppointmentWebhookService::class,
    'ensureStoredEventIncluded',
    [['id' => 'event-a']],
    'event-b'
);
assertTest(
    'ensureStoredEventIncluded appends missing stored event id',
    count($merged) === 2
        && ($merged[0]['id'] ?? null) === 'event-a'
        && ($merged[1]['id'] ?? null) === 'event-b'
);

$descriptionEvent = [
    'id' => 'legacy-event',
    'description' => "hamgam_book_id:d3fe846f-6b15-11f1-8fe5-b6c09fdc72a4\nبیمار",
];
assertTest(
    'GoogleEventParser finds book_id in description fallback',
    GoogleEventParser::extractBookId($descriptionEvent) === 'd3fe846f-6b15-11f1-8fe5-b6c09fdc72a4'
);

$bareBookIdEvent = [
    'id' => 'new-format-event',
    'description' => "بیمار : علی\nbc9437f4-0000-4000-8000-000000000001",
];
assertTest(
    'GoogleEventParser finds bare book_id line in description',
    GoogleEventParser::extractBookId($bareBookIdEvent) === 'bc9437f4-0000-4000-8000-000000000001'
);

$bookId = 'bc9437f4-0000-4000-8000-000000000001';
$builtWithDateTime = CalendarEventBuilder::build(
    ['from' => 1781931600, 'to' => 1781932500],
    [
        'color_id' => '9',
        'Patient_date_time' => true,
        'Patient_name' => false,
    ],
    [
        'book_id' => $bookId,
        'patient_name' => 'Ali',
        'patient_family' => 'Test',
    ]
);
$builtDescription = is_array($builtWithDateTime) ? (string) ($builtWithDateTime['description'] ?? '') : '';
assertTest(
    'CalendarEventBuilder stores bare book_id in description',
    is_array($builtWithDateTime)
        && str_contains($builtDescription, $bookId)
        && !str_contains($builtDescription, 'hamgam_book_id:'),
    $builtDescription
);
assertTest(
    'CalendarEventBuilder uses Jalali date in description (not Gregorian month names)',
    is_array($builtWithDateTime)
        && str_contains($builtDescription, 'تاریخ :')
        && !preg_match('/ژوئن|June|2026/u', $builtDescription),
    $builtDescription
);
assertTest(
    'CalendarEventBuilder Jalali date includes Persian weekday',
    is_array($builtWithDateTime)
        && preg_match('/تاریخ : .+، (شنبه|یکشنبه|دوشنبه|سه‌شنبه|چهارشنبه|پنجشنبه|جمعه)/u', $builtDescription) === 1,
    $builtDescription
);

$builtWithCenter = CalendarEventBuilder::build(
    ['from' => 1781931600, 'to' => 1781932500],
    [
        'color_id' => '9',
        'Patient_name' => true,
        'Patient_center' => true,
        'Patient_date_time' => true,
    ],
    [
        'book_id' => $bookId,
        'patient_name' => 'علی',
        'patient_family' => 'احمدی',
        'center_name' => 'کلینیک ونک',
    ]
);
$centerDescription = is_array($builtWithCenter) ? (string) ($builtWithCenter['description'] ?? '') : '';
$centerLines = array_values(array_filter(explode("\n", $centerDescription), static fn (string $line): bool => trim($line) !== ''));
assertTest(
    'CalendarEventBuilder puts center name on second description line',
    is_array($builtWithCenter)
        && count($centerLines) >= 2
        && str_starts_with($centerLines[0], 'بیمار :')
        && str_starts_with($centerLines[1], 'مرکز : کلینیک ونک'),
    $centerDescription
);
$withoutCenter = CalendarEventBuilder::build(
    ['from' => 1781931600, 'to' => 1781932500],
    ['Patient_center' => false],
    ['center_name' => 'کلینیک ونک']
);
assertTest(
    'CalendarEventBuilder omits center line when setting disabled',
    is_array($withoutCenter)
        && !str_contains((string) ($withoutCenter['description'] ?? ''), 'مرکز :')
);

$nestedCenterBooking = [
    'book_id' => $bookId,
    'patient_name' => 'علی',
    'patient_family' => 'احمدی',
    'center' => ['name' => 'کلینیک ونک'],
];
$builtNestedCenter = CalendarEventBuilder::build(
    ['from' => 1781931600, 'to' => 1781932500],
    ['Patient_center' => true],
    $nestedCenterBooking
);
$nestedCenterDescription = is_array($builtNestedCenter) ? (string) ($builtNestedCenter['description'] ?? '') : '';
assertTest(
    'CalendarEventBuilder reads nested center.name',
    is_array($builtNestedCenter)
        && str_contains($nestedCenterDescription, 'مرکز : کلینیک ونک'),
    $nestedCenterDescription
);

assertTest(
    'extractCenterName reads nested medical_center.name',
    BookingAppointmentResolver::extractCenterName([
        'medical_center' => ['name' => 'مطب شمال'],
    ]) === 'مطب شمال'
);

$updateDuplicateTargets = function (array $events, ?string $keepEventId): array {
    $targets = [];
    foreach ($events as $event) {
        $eventId = GoogleEventParser::extractEventId($event);
        if ($eventId === null || $eventId === '') {
            continue;
        }
        if ($keepEventId !== null && $eventId === $keepEventId) {
            continue;
        }
        $targets[] = $eventId;
    }

    return $targets;
};

assertTest(
    'cancel should delete stored event id',
    $updateDuplicateTargets(
        [['id' => 'stored-event-id'], ['id' => 'duplicate-event-id']],
        null
    ) === ['stored-event-id', 'duplicate-event-id']
);
assertTest(
    'update should keep stored event id when removing duplicates',
    $updateDuplicateTargets(
        [['id' => 'stored-event-id'], ['id' => 'duplicate-event-id']],
        'stored-event-id'
    ) === ['duplicate-event-id']
);

$fromDateHourBooking = [
    'from_date' => '2026-06-20',
    'from_hour' => '08:30',
    'from' => '1781931600',
    'to' => '1781932500',
];
$fromDateHourRange = invokePrivate(BookingAppointmentResolver::class, 'parseFromDateHour', $fromDateHourBooking);
assertTest(
    'parseFromDateHour resolves end from to timestamp',
    is_array($fromDateHourRange)
        && ($fromDateHourRange['to'] ?? null) === 1781932500
        && ($fromDateHourRange['from'] ?? null) === 1781931600,
    $fromDateHourRange
);

$mismatchBooking = [
    'from' => 1781932500,
    'to' => 1781933400,
    'from_date' => '2026-06-20',
    'from_hour' => '08:30',
    'duration' => 15,
];
$mismatchRange = BookingAppointmentResolver::resolveForUpdate(
    $mismatchBooking,
    'd3fe846f-6b15-11f1-8fe5-b6c09fdc72a4',
    'unused-token-for-test'
);
assertTest(
    'timestamp mismatch prefers from_date/from_hour when numeric from is one slot ahead',
    is_array($mismatchRange)
        && ($mismatchRange['from'] ?? null) === 1781931600
        && ($mismatchRange['to'] ?? null) === 1781932500,
    $mismatchRange
);

assertTest(
    'VacationSyncService has revertHamgamAppointmentCalendarToApi',
    (new ReflectionClass(VacationSyncService::class))->hasMethod('revertHamgamAppointmentCalendarToApi')
);

assertTest(
    'mergeBookingRecord inherits parent from/to timestamps',
    (function (): bool {
        $payload = [
            'data' => [
                'doctor_user_id' => 23489442,
                'from' => 1781931600,
                'to' => 1781932500,
                'after_update_record' => [
                    'id' => 'd3fe846f-6b15-11f1-8fe5-b6c09fdc72a4',
                    'from_date' => '2026-06-20',
                    'from_hour' => '08:30',
                ],
            ],
        ];
        $booking = Paziresh24WebhookPayload::extractBooking($payload);

        return is_array($booking)
            && ($booking['from'] ?? null) == 1781931600
            && ($booking['to'] ?? null) == 1781932500;
    })()
);

assertTest(
    'extractAppointmentRange falls back to book_timestamp before workhour_turn_num',
    (function (): bool {
        $range = GoogleCalendar::extractAppointmentRange([
            'book_timestamp' => 1781931600,
            'workhour_turn_num' => 1781932500,
            'from' => 1781932500,
            'to' => 1781933400,
            'duration' => 15,
        ]);

        return is_array($range)
            && ($range['from'] ?? null) === 1781931600
            && ($range['to'] ?? null) === 1781932500;
    })()
);

$deployWebhookBooking = [
    'book_id' => 'test-deploy-book-id',
    'doctor_user_id' => 1792050,
    'book_date' => '2026-06-10',
    'book_time' => '16:00',
    'duration' => 15,
];
$deployRange = BookingAppointmentResolver::resolve(
    $deployWebhookBooking,
    'test-deploy-book-id',
    'unused-token-for-test'
);
assertTest(
    'production webhook with book_date/book_time resolves correct slot',
    is_array($deployRange)
        && $deployRange['to'] - $deployRange['from'] === 900,
    $deployRange
);

$wrongNumericWithBookTime = [
    'book_date' => '2026-06-20',
    'book_time' => '10:00',
    'from' => 1781940600,
    'to' => 1781941500,
    'duration' => 15,
];
$correctedRange = BookingAppointmentResolver::resolve(
    $wrongNumericWithBookTime,
    'test-book-id',
    'unused-token-for-test'
);
assertTest(
    'book_date/book_time beats wrong numeric from (+15 min)',
    is_array($correctedRange)
        && date('H:i', $correctedRange['from']) === '10:00',
    $correctedRange
);

$inflatedSpanBooking = [
    'from_date' => '2026-06-20',
    'from_hour' => '08:30',
    'book_timestamp' => 1781931600,
    'from' => 1781932500,
    'to' => 1781934300,
];
$inflatedSpanRange = BookingAppointmentResolver::resolveForUpdate(
    $inflatedSpanBooking,
    'd3fe846f-6b15-11f1-8fe5-b6c09fdc72a4',
    'unused-token-for-test'
);
assertTest(
    'inflated numeric span does not extend 15-minute wall-clock appointment',
    is_array($inflatedSpanRange)
        && ($inflatedSpanRange['from'] ?? null) === 1781931600
        && ($inflatedSpanRange['to'] ?? null) === 1781932500,
    $inflatedSpanRange
);

$bookTimePriorityBooking = [
    'book_date' => '2026-06-20',
    'book_time' => '10:00',
    'from_date' => '2026-06-20',
    'from_hour' => '09:45',
    'duration' => 15,
];
$bookTimePriorityRange = BookingAppointmentResolver::resolveForUpdate(
    $bookTimePriorityBooking,
    'd3fe846f-6b15-11f1-8fe5-b6c09fdc72a4',
    'unused-token-for-test'
);
assertTest(
    'book_date/book_time preferred over from_date/from_hour for start',
    is_array($bookTimePriorityRange)
        && date('H:i', $bookTimePriorityRange['from']) === '10:00'
        && ($bookTimePriorityRange['to'] - $bookTimePriorityRange['from']) === 900,
    $bookTimePriorityRange
);

$staleWallClockBooking = [
    'from' => 1781932500,
    'to' => 1781933400,
    'from_date' => '2026-06-20',
    'from_hour' => '08:00',
    'book_timestamp' => 1781931600,
    'duration' => 15,
];
$staleWallClockRange = BookingAppointmentResolver::resolveForUpdate(
    $staleWallClockBooking,
    'd3fe846f-6b15-11f1-8fe5-b6c09fdc72a4',
    'unused-token-for-test'
);
assertTest(
    'book_timestamp beats stale from_date/from_hour after reschedule',
    is_array($staleWallClockRange)
        && ($staleWallClockRange['from'] ?? null) === 1781931600
        && ($staleWallClockRange['to'] ?? null) === 1781932500,
    $staleWallClockRange
);

$staleWallClockAheadBooking = [
    'from' => 1781931600,
    'to' => 1781932500,
    'from_date' => '2026-06-20',
    'from_hour' => '08:30',
    'duration' => 15,
];
$staleWallClockAheadRange = BookingAppointmentResolver::resolveForUpdate(
    $staleWallClockAheadBooking,
    'd3fe846f-6b15-11f1-8fe5-b6c09fdc72a4',
    'unused-token-for-test'
);
assertTest(
    'stale wall-clock ahead of numeric from prefers numeric (08:00 not 08:30)',
    is_array($staleWallClockAheadRange)
        && ($staleWallClockAheadRange['from'] ?? null) === 1781931600
        && ($staleWallClockAheadRange['to'] ?? null) === 1781932500,
    $staleWallClockAheadRange
);

assertTest(
    'Paziresh24AppointmentApi exposes getPendingCalendarSyncRange',
    method_exists(Paziresh24AppointmentApi::class, 'getPendingCalendarSyncRange')
);

$explicitFromToBooking = [
    'from' => 1781931600,
    'to' => 1781932500,
    'duration' => 30,
];
$explicitFromToRange = BookingAppointmentResolver::resolve(
    $explicitFromToBooking,
    'test-book-id',
    'unused-token-for-test'
);
assertTest(
    'numeric from/to span preferred over conflicting duration field',
    is_array($explicitFromToRange)
        && ($explicitFromToRange['from'] ?? null) === 1781931600
        && ($explicitFromToRange['to'] ?? null) === 1781932500,
    $explicitFromToRange
);

$builtWithFromToLabels = CalendarEventBuilder::build(
    ['from' => 1781931600, 'to' => 1781932500],
    [
        'color_id' => '9',
        'Patient_date_time' => true,
    ],
    ['book_id' => $bookId]
);
$fromToDescription = is_array($builtWithFromToLabels) ? (string) ($builtWithFromToLabels['description'] ?? '') : '';
assertTest(
    'CalendarEventBuilder shows from/to labels in description',
    is_array($builtWithFromToLabels)
        && str_contains($fromToDescription, 'از : 08:30')
        && str_contains($fromToDescription, 'تا : 08:45'),
    $fromToDescription
);

echo "=== Appointment webhook lifecycle tests ===\n";
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
