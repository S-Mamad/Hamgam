<?php

declare(strict_types=1);

/**
 * معادل PHP workflow n8n: POST /hamgam/updatesetting
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/vacation-bootstrap.php';

hamgam_load_vacation_modules();

Request::applyCors();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    Response::jsonError('Method not allowed', 405);
}

try {
    $accessToken = Request::accessToken();
    if ($accessToken === '') {
        RequestContext::log('hamgam/updatesetting', '401: missing or invalid access_token');
        Response::jsonError('Unauthorized', 401);
    }

    $body = Request::jsonBody();
    if ($body === null) {
        Response::jsonError('Invalid JSON body', 400);
    }

    unset($body['access_token'], $body['returnJson']);

    $userId = Paziresh24Api::resolveUserId($accessToken);
    if ($userId === null) {
        Response::jsonError('User not found', 404);
    }

    GoogleTokensRepository::upsertHamdastAccessToken($userId, $accessToken);

    $updated = GoogleTokensRepository::updateSettings($userId, $body);
    if (!$updated) {
        RequestContext::log(
            'hamgam/updatesetting',
            'updateSettings failed user=' . $userId
            . ' keys=' . implode(',', array_keys($body))
        );
        Response::jsonError('Settings update failed', 400);
    }

    $tokenRow = GoogleTokensRepository::findByUserId($userId);
    $savedSettings = GoogleTokensRepository::getSettings($tokenRow);

    UserActivityLog::api($userId, 'settings.updated', 'تنظیمات ذخیره شد', 'info', [
        'keys' => array_keys($body),
    ]);

    $forceBackfill = false;
    if (VacationFeature::isEnabled() && array_key_exists('importFutureVacations', $body)) {
        $parsedImport = GoogleTokensRepository::parseImportFutureVacationsFlag($body['importFutureVacations']);
        $forceBackfill = $parsedImport === true
            && !GoogleTokensRepository::hasCompletedImportFutureVacations($tokenRow);
    }

    $shouldRunBackfill = VacationFeature::isEnabled()
        && ($forceBackfill || GoogleTokensRepository::shouldRunFutureVacationsBackfill($tokenRow));
    $backfillResult = ['ran' => false, 'imported' => 0, 'skipped' => 0, 'failed' => 0];

    if ($shouldRunBackfill) {
        GoogleTokensRepository::markSyncPending($userId, 'backfill');

        Response::jsonThenContinue(
            [
                'ok' => true,
                'backfill' => [
                    'ran' => false,
                    'pending' => true,
                    'imported' => 0,
                    'skipped' => 0,
                    'failed' => 0,
                ],
                'sync_pending' => true,
                'settings' => $savedSettings,
            ],
            static function () use ($userId, $accessToken, $forceBackfill): void {
                set_time_limit(300);
                HamgamConnectionService::runBackgroundSync($userId, $accessToken, true, $forceBackfill);
            }
        );
    }

    GoogleTokensRepository::markSyncPending($userId, 'sync');

    Response::jsonThenContinue(
        [
            'ok' => true,
            'backfill' => $backfillResult,
            'sync_pending' => true,
            'settings' => $savedSettings,
        ],
        static function () use ($userId, $accessToken): void {
            set_time_limit(120);
            HamgamConnectionService::runBackgroundSync($userId, $accessToken, false);
        }
    );
} catch (Throwable $e) {
    RequestContext::log('hamgam/updatesetting', $e->getMessage());
    Response::jsonError('Internal server error', 500);
}
