<?php

declare(strict_types=1);

require_once __DIR__ . '/HamgamSyncMessages.php';

final class HamgamConnectionService
{
    /**
     * بعد از auth یا ذخیره تنظیمات: توکن پذیرش۲۴ را تازه می‌کند، center_id و Watch را در صورت نیاز ثبت می‌کند.
     *
     * @return array{ok: bool, warnings: list<array{code: string, message: string}>}
     */
    public static function syncAfterAuth(string $userId, string $hamdastAccessToken): array
    {
        $warnings = [];

        try {
            $userId = GoogleTokensRepository::normalizeUserId($userId);
            $tokenRow = GoogleTokensRepository::findByUserId($userId);
            if ($tokenRow === null) {
                error_log('[hamgam-connection] no token row for user ' . $userId);
                $warnings[] = HamgamSyncMessages::warning('no_token_row');

                return ['ok' => false, 'warnings' => $warnings];
            }

            GoogleTokensRepository::updateHamdastAccessToken($userId, $hamdastAccessToken);

            $vacationCenter = Paziresh24VacationApi::resolveVacationCenter($hamdastAccessToken);
            if ($vacationCenter !== null) {
                GoogleVacationRepository::saveCenterId($userId, $vacationCenter['medical_center_id']);
                error_log('[hamgam-connection] medical_center_id saved for user ' . $userId);
            }

            $tokenRow = GoogleTokensRepository::findByUserId($userId);
            if ($tokenRow === null || !GoogleTokensRepository::needsWatchRegistration($tokenRow)) {
                return ['ok' => empty($warnings), 'warnings' => $warnings];
            }

            $refreshToken = (string) ($tokenRow['google_refresh_token'] ?? '');
            if ($refreshToken === '') {
                $warnings[] = HamgamSyncMessages::warning('google_token_refresh_failed');

                return ['ok' => false, 'warnings' => $warnings];
            }

            $googleTokenData = GoogleCalendar::refreshAccessToken($refreshToken);
            $googleAccessToken = is_array($googleTokenData) ? ($googleTokenData['access_token'] ?? '') : '';
            if (!is_string($googleAccessToken) || $googleAccessToken === '') {
                error_log('[hamgam-connection] google token refresh failed for user ' . $userId);
                $warnings[] = HamgamSyncMessages::warning('google_token_refresh_failed');

                return ['ok' => false, 'warnings' => $warnings];
            }

            $resolvedEmail = GoogleCalendar::resolveAccountEmail($googleAccessToken);
            if ($resolvedEmail !== null) {
                $existingEmail = is_string($tokenRow['google_account_email'] ?? null)
                    ? trim($tokenRow['google_account_email'])
                    : '';
                if ($existingEmail === '' || strcasecmp($existingEmail, $resolvedEmail) !== 0) {
                    GoogleTokensRepository::saveGoogleAccountEmail($userId, $resolvedEmail);
                    error_log('[hamgam-connection] google_account_email saved for user ' . $userId);
                }
            }

            require_once __DIR__ . '/../google-vacation/WatchRegistrar.php';

            if (WatchRegistrar::registerForUser($userId, $hamdastAccessToken, $googleAccessToken)) {
                error_log('[hamgam-connection] watch registered/repaired for user ' . $userId);
            } else {
                error_log('[hamgam-connection] watch registration failed for user ' . $userId);
                $warnings[] = HamgamSyncMessages::warning('watch_registration_failed');
            }
        } catch (Throwable $e) {
            error_log('[hamgam-connection] syncAfterAuth failed for user ' . $userId . ': ' . $e->getMessage());
            $warnings[] = HamgamSyncMessages::warning('sync_failed');
        }

        return ['ok' => empty($warnings), 'warnings' => $warnings];
    }

    /**
     * همگام‌سازی پس‌زمینه بعد از ذخیره تنظیمات (شامل backfill اختیاری).
     */
    public static function runBackgroundSync(
        string $userId,
        string $hamdastAccessToken,
        bool $runBackfillWhenEligible = false,
        bool $resetBackfillState = false
    ): void {
        $operation = $runBackfillWhenEligible ? 'backfill' : 'sync';
        GoogleTokensRepository::markSyncPending($userId, $operation);

        $warnings = [];
        $backfillResult = ['ran' => false, 'imported' => 0, 'skipped' => 0, 'failed' => 0];

        try {
            $syncResult = self::syncAfterAuth($userId, $hamdastAccessToken);
            $warnings = array_merge($warnings, $syncResult['warnings']);
        } catch (Throwable $syncError) {
            RequestContext::log('hamgam/sync', 'syncAfterAuth failed: ' . $syncError->getMessage());
            $warnings[] = HamgamSyncMessages::warning('sync_failed');
        }

        if ($runBackfillWhenEligible) {
            try {
                GoogleTokensRepository::updateSyncProgress($userId, [
                    'phase' => 'preparing',
                    'processed' => 0,
                    'total' => 0,
                    'percent' => 5,
                ]);

                $tokenRow = GoogleTokensRepository::findByUserId($userId);
                if ($tokenRow !== null && GoogleTokensRepository::shouldRunFutureVacationsBackfill($tokenRow)) {
                    if (
                        $resetBackfillState
                        && !GoogleTokensRepository::hasCompletedImportFutureVacations($tokenRow)
                    ) {
                        GoogleTokensRepository::resetImportBackfillState($userId);
                    }

                    $backfillResult = VacationSyncService::runFutureEventsBackfill(
                        $userId,
                        $hamdastAccessToken,
                        $resetBackfillState
                            && !GoogleTokensRepository::hasCompletedImportFutureVacations($tokenRow)
                    );
                }

                if (!$backfillResult['ran']) {
                    $warnings[] = HamgamSyncMessages::warning('backfill_not_run');
                } elseif ($backfillResult['failed'] > 0) {
                    $warnings[] = HamgamSyncMessages::warning(
                        'backfill_partial_fail',
                        (string) $backfillResult['failed']
                    );
                }
            } catch (Throwable $backfillError) {
                RequestContext::log('hamgam/sync', 'backfill failed: ' . $backfillError->getMessage());
                $warnings[] = HamgamSyncMessages::warning('backfill_failed');
            }
        }

        GoogleTokensRepository::saveSyncStatus($userId, [
            'pending' => false,
            'operation' => $operation,
            'ok' => empty($warnings),
            'warnings' => $warnings,
            'backfill' => $backfillResult,
        ]);
    }

    /**
     * @return array{ran: bool, imported: int, skipped: int, failed: int}
     */
    public static function runBackfillNow(
        string $userId,
        string $hamdastAccessToken,
        bool $resetBackfillState = false
    ): array {
        set_time_limit(300);

        $warnings = [];
        $backfillResult = ['ran' => false, 'imported' => 0, 'skipped' => 0, 'failed' => 0];

        try {
            $syncResult = self::syncAfterAuth($userId, $hamdastAccessToken);
            $warnings = array_merge($warnings, $syncResult['warnings']);
        } catch (Throwable $syncError) {
            RequestContext::log('hamgam/backfill', 'syncAfterAuth failed: ' . $syncError->getMessage());
            $warnings[] = HamgamSyncMessages::warning('sync_failed');
        }

        try {
            $tokenRow = GoogleTokensRepository::findByUserId($userId);
            if (
                $resetBackfillState
                && ($tokenRow === null || !GoogleTokensRepository::hasCompletedImportFutureVacations($tokenRow))
            ) {
                GoogleTokensRepository::resetImportBackfillState($userId);
            }

            $backfillResult = VacationSyncService::runFutureEventsBackfill(
                $userId,
                $hamdastAccessToken,
                $resetBackfillState
                    && ($tokenRow === null || !GoogleTokensRepository::hasCompletedImportFutureVacations($tokenRow))
            );

            if ($backfillResult['failed'] > 0) {
                $warnings[] = HamgamSyncMessages::warning(
                    'backfill_partial_fail',
                    (string) $backfillResult['failed']
                );
            }
        } catch (Throwable $backfillError) {
            RequestContext::log('hamgam/backfill', 'backfill failed: ' . $backfillError->getMessage());
            $warnings[] = HamgamSyncMessages::warning('backfill_failed');
        }

        GoogleTokensRepository::saveSyncStatus($userId, [
            'pending' => false,
            'operation' => 'backfill',
            'ok' => empty($warnings),
            'warnings' => $warnings,
            'backfill' => $backfillResult,
        ]);

        return $backfillResult;
    }
}
