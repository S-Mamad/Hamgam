<?php

declare(strict_types=1);

/**
 * بارگذاری کلاس‌های ماژول مرخصی — فقط در endpointهای مربوطه فراخوانی شود.
 */
function hamgam_load_vacation_modules(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }

    require_once __DIR__ . '/GoogleCalendarWatch.php';
    require_once __DIR__ . '/GoogleEventParser.php';
    require_once __DIR__ . '/GoogleVacationRepository.php';
    require_once __DIR__ . '/Paziresh24VacationApi.php';
    require_once __DIR__ . '/Paziresh24AppointmentApi.php';
    require_once __DIR__ . '/GoogleCalendarBookingRepository.php';
    require_once __DIR__ . '/GoogleWebhookHeaders.php';
    require_once __DIR__ . '/HamgamConnectionService.php';
    require_once __DIR__ . '/../google-vacation/VacationSyncService.php';

    $loaded = true;
}
