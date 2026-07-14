<?php

declare(strict_types=1);

/**
 * Local one-shot: move Aida Ardani appointment back to 2026-07-14 10:30 Tehran.
 * Requires HAMDAST_TOKEN env (provider JWT from production DB / fresh login).
 *
 * Usage:
 *   set HAMDAST_TOKEN=eyJ...
 *   php -c dev/php.ini php/tools/run-restore-aida-local.php
 */

require_once __DIR__ . '/../includes/Config.php';
require_once __DIR__ . '/../includes/HttpClient.php';
require_once __DIR__ . '/../includes/vacation-bootstrap.php';

Config::load(__DIR__ . '/../.env');
date_default_timezone_set('Asia/Tehran');
hamgam_load_vacation_modules();

$token = trim((string) (getenv('HAMDAST_TOKEN') ?: ''));
if ($token === '') {
    fwrite(STDERR, "Set HAMDAST_TOKEN environment variable.\n");
    exit(1);
}

$userId = '1792050';
$bookId = 'c25d0e67-7e9f-11f1-b196-bc2411b7c60f';
$targetFrom = (new DateTimeImmutable('2026-07-14 10:30:00', new DateTimeZone('Asia/Tehran')))->getTimestamp();

$appointment = GoogleCalendar::getAppointment($bookId, $token);
if (!is_array($appointment)) {
    echo json_encode(['ok' => false, 'error' => 'appointment_not_found'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit(1);
}

$range = Paziresh24AppointmentApi::resolveMoveRange($token, $bookId, 0, 0);
$bookFrom = (int) ($range['from'] ?? 0);
$bookTo = (int) ($range['to'] ?? 0);

$medicalCenterId = BookingAppointmentResolver::extractCenterId($appointment) ?? '9f9a1285-a711-4418-8e92-a40d4bec2f94';
$userCenterId = BookingAppointmentResolver::resolveUserCenterIdForReschedule(
    $appointment,
    $token,
    $medicalCenterId,
    null
);

echo json_encode([
    'patient' => trim(($appointment['name'] ?? '') . ' ' . ($appointment['family'] ?? '')),
    'before_from' => $bookFrom > 0 ? date('Y-m-d H:i:s', $bookFrom) : null,
    'before_to' => $bookTo > 0 ? date('Y-m-d H:i:s', $bookTo) : null,
    'target_from' => date('Y-m-d H:i:s', $targetFrom),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;

if (($argv[1] ?? '') !== '--confirm') {
    fwrite(STDERR, "Dry run only. Re-run with --confirm to execute move.\n");
    exit(0);
}

$move = Paziresh24AppointmentApi::moveAppointmentWithCenterFallback(
    $token,
    $medicalCenterId,
    $userCenterId,
    $bookFrom,
    $bookTo,
    $targetFrom
);

$out = ['ok' => (bool) ($move['success'] ?? false), 'move' => $move];

if ($move['success']) {
    $after = GoogleCalendar::getAppointment($bookId, $token);
    if (is_array($after)) {
        $af = (int) ($after['from'] ?? 0);
        $at = (int) ($after['to'] ?? 0);
        $out['after_from'] = $af > 0 ? date('Y-m-d H:i:s', $af) : null;
        $out['after_to'] = $at > 0 ? date('Y-m-d H:i:s', $at) : null;
    }
}

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
exit($out['ok'] ? 0 : 1);
