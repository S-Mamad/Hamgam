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
