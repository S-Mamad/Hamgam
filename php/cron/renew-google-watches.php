<?php

declare(strict_types=1);

/**
 * Cron: تمدید Watch گوگل قبل از انقضا (~۷ روز)
 * زمان‌بندی پیشنهادی: هر ۶ ساعت — جزئیات در php/DEPLOY.md
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/GoogleCalendarWatch.php';
require_once __DIR__ . '/../includes/GoogleVacationRepository.php';
require_once __DIR__ . '/../includes/Paziresh24VacationApi.php';
require_once __DIR__ . '/../google-vacation/WatchRegistrar.php';

try {
    $rows = array_merge(
        GoogleVacationRepository::findUsersNeedingWatchRegistration(),
        GoogleVacationRepository::findExpiringWatches(86400000)
    );
    $seen = [];
    $renewed = 0;
    $failed = 0;

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $userId = (string) ($row['paziresh24_user_id'] ?? '');
        if ($userId === '' || isset($seen[$userId])) {
            continue;
        }
        $seen[$userId] = true;

        if (WatchRegistrar::renewForTokenRow($row)) {
            $renewed++;
        } else {
            $failed++;
        }
    }

    error_log('[google-vacation] cron renew: renewed=' . $renewed . ' failed=' . $failed);
    MonitorService::cron(
        'renew-google-watches',
        'renewed=' . $renewed . ' failed=' . $failed,
        $failed > 0 ? 'warning' : 'info',
        ['renewed' => $renewed, 'failed' => $failed, 'users_seen' => count($seen)]
    );
} catch (Throwable $e) {
    error_log('[google-vacation] cron renew error: ' . $e->getMessage());
    MonitorService::cron('renew-google-watches', 'error: ' . $e->getMessage(), 'error');
    exit(1);
}
