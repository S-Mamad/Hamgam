<?php

declare(strict_types=1);

require 'f:/VS Code File/New folder/Zamanak/php/includes/Config.php';
require 'f:/VS Code File/New folder/Zamanak/php/includes/HttpClient.php';
require 'f:/VS Code File/New folder/Zamanak/php/includes/vacation-bootstrap.php';

Config::load('f:/VS Code File/New folder/Zamanak/php/.env');
hamgam_load_vacation_modules();
date_default_timezone_set('Asia/Tehran');

$token = trim((string) (getenv('HAMDAST_TOKEN') ?: ($argv[1] ?? '')));
if ($token === '') {
    fwrite(STDERR, "Usage: HAMDAST_TOKEN=... php move-aida-openapi.php\n");
    exit(1);
}

$centerId = '9f9a1285-a711-4418-8e92-a40d4bec2f94';
$bookId = 'c25d0e67-7e9f-11f1-b196-bc2411b7c60f';
$targetFrom = 1784012400;

$appointment = GoogleCalendar::getAppointment($bookId, $token);
$range = Paziresh24AppointmentApi::resolveMoveRange($token, $bookId, 0, 0);
$bookFrom = (int) ($range['from'] ?? 0);
$bookTo = (int) ($range['to'] ?? 0);

echo json_encode([
    'patient' => is_array($appointment) ? trim(($appointment['name'] ?? '') . ' ' . ($appointment['family'] ?? '')) : null,
    'current_from' => $bookFrom > 0 ? date('Y-m-d H:i:s', $bookFrom) : null,
    'current_to' => $bookTo > 0 ? date('Y-m-d H:i:s', $bookTo) : null,
    'target_from' => date('Y-m-d H:i:s', $targetFrom),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;

if (($argv[2] ?? '') !== '--confirm') {
    exit(0);
}

$move = Paziresh24AppointmentApi::moveAppointmentWithCenterFallback(
    $token,
    $centerId,
    '9f9a1285-d5dc-4e68-81bf-4bd0c587b170',
    $bookFrom,
    $bookTo,
    $targetFrom
);

echo json_encode($move, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
exit($move['success'] ? 0 : 1);
