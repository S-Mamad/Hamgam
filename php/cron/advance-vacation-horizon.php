<?php

declare(strict_types=1);

/**
 * Cron: هر شب بعد از نیمه‌شب (تهران) یک روز به افق ۳۰روزهٔ مرخصی اضافه می‌کند.
 * زمان‌بندی پیشنهادی (Asia/Tehran): 5 0 * * *
 *   php /path/to/php/cron/advance-vacation-horizon.php
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/vacation-bootstrap.php';

hamgam_load_vacation_modules();

try {
    $summary = VacationSyncService::runDailyRollingVacationSync();
    error_log(
        '[google-vacation] cron rolling horizon: users=' . ($summary['users'] ?? 0)
        . ' imported=' . ($summary['imported'] ?? 0)
        . ' skipped=' . ($summary['skipped'] ?? 0)
        . ' failed=' . ($summary['failed'] ?? 0)
    );
    MonitorService::cron(
        'advance-vacation-horizon',
        'users=' . ($summary['users'] ?? 0)
        . ' imported=' . ($summary['imported'] ?? 0)
        . ' skipped=' . ($summary['skipped'] ?? 0)
        . ' failed=' . ($summary['failed'] ?? 0),
        ((int) ($summary['failed'] ?? 0)) > 0 ? 'warning' : 'info',
        is_array($summary) ? $summary : null
    );
} catch (Throwable $e) {
    error_log('[google-vacation] cron rolling horizon error: ' . $e->getMessage());
    MonitorService::cron('advance-vacation-horizon', 'error: ' . $e->getMessage(), 'error');
    exit(1);
}
