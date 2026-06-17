<?php

declare(strict_types=1);

if (!ob_get_level()) {
    ob_start();
}

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/RequestContext.php';
require_once __DIR__ . '/HttpClient.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Paziresh24Api.php';
require_once __DIR__ . '/GoogleTokensRepository.php';
require_once __DIR__ . '/GoogleVacationRepository.php';
require_once __DIR__ . '/ImportFutureVacationsRepository.php';
require_once __DIR__ . '/Response.php';
require_once __DIR__ . '/Request.php';
require_once __DIR__ . '/WebhookVerifier.php';
require_once __DIR__ . '/GoogleCalendar.php';
require_once __DIR__ . '/CalendarEventBuilder.php';
require_once __DIR__ . '/BookingAppointmentResolver.php';
require_once __DIR__ . '/GoogleCalendarBookingRepository.php';

Config::load(__DIR__ . '/../.env');
RequestContext::generateRequestId();

$logFile = __DIR__ . '/../storage/php-errors.log';
if (is_dir(dirname($logFile)) || @mkdir(dirname($logFile), 0750, true)) {
    ini_set('error_log', $logFile);
}

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

if (Config::getBool('APP_DEBUG', false)) {
    ini_set('display_errors', '1');
}

date_default_timezone_set('Asia/Tehran');
