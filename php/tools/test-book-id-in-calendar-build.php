<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/GoogleEventParser.php';

$booking = [
    'id' => 'e86d0cc0-76aa-11f1-b196-bc2411b7c60f',
    'from' => 1783143000,
    'to' => 1783143900,
    'center_id' => 'e5d0fa25-a8e1-40db-a957-97aa0af1c0ee',
    'name' => 'Mohammad',
    'family' => 'Mohammadi',
    'national_code' => '4421760447',
];

$settings = [
    'color_id' => '9',
    'Patient_name' => 1,
    'Patient_center' => 1,
    'Patient_national' => 1,
];

$event = CalendarEventBuilder::build(
    ['from' => 1783143000, 'to' => 1783143900],
    $settings,
    $booking
);

$lines = explode("\n", (string) ($event['description'] ?? ''));

echo json_encode([
    'has_extended' => isset($event['extendedProperties']),
    'book_id_private' => $event['extendedProperties']['private']['hamgam_book_id'] ?? null,
    'description_last_line' => $lines[array_key_last($lines)] ?? '',
    'extract_book_id' => GoogleEventParser::extractBookId(is_array($event) ? $event : []),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
