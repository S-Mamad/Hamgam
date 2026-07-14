<?php

declare(strict_types=1);

/**
 * معادل PHP workflow دکمه خارجی n8n: /hamgam/button
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/HamgamRedirects.php';

try {
    $sessionToken = Paziresh24Api::parseSessionTokenFromQuery($_GET['session_token'] ?? '');

    if ($sessionToken === '' || !Paziresh24Api::isValidSessionToken($sessionToken)) {
        RequestContext::log('hamgam/button', 'invalid or missing session_token');
        Response::errorRedirect();
    }

    $tokenData = Paziresh24Api::exchangeSessionToken($sessionToken);
    $accessToken = is_array($tokenData) ? ($tokenData['access_token'] ?? '') : '';
    if (!is_string($accessToken) || $accessToken === '') {
        RequestContext::log('hamgam/button', 'session_token exchange failed');
        Response::errorRedirect();
    }

    $userId = Paziresh24Api::resolveUserId($accessToken);
    if ($userId === null) {
        RequestContext::log('hamgam/button', 'user id not found from access token');
        Response::errorRedirect();
    }

    GoogleTokensRepository::upsertHamdastAccessToken($userId, $accessToken);

    $tokenRow = GoogleTokensRepository::findByUserId($userId);
    $hasGoogleConnection = GoogleTokensRepository::hasRefreshToken($tokenRow);

    if ($hasGoogleConnection) {
        $hasRegisteredWidget = Paziresh24Api::hasWidget($userId);
        $disconnectRequested = ($_GET['disconnect'] ?? '') === '1'
            || ($_GET['action'] ?? '') === 'disconnect';

        if ($disconnectRequested) {
            $watchCleanup = GoogleTokensRepository::watchCleanupCredentials($tokenRow);

            GoogleTokensRepository::disconnectGoogleConnection($userId);
            RequestContext::logForUser('hamgam/button', $userId, 'disconnected user ' . $userId);
            UserActivityLog::auth($userId, 'google.disconnected', 'قطع اتصال از دکمه Hamgam');

            try {
                Paziresh24Api::deleteWidget($userId);
            } catch (Throwable $widgetError) {
                RequestContext::log('hamgam/button', 'deleteWidget before redirect failed: ' . $widgetError->getMessage());
            }

            Response::redirectThenContinue(
                HamgamRedirects::launcherHomeUrl(),
                static function () use ($watchCleanup): void {
                    if ($watchCleanup !== null) {
                        stopGoogleWatchWithCredentials($watchCleanup);
                    }
                }
            );
        }

        if ($hasRegisteredWidget) {
            RequestContext::logForUser('hamgam/button', $userId, 'opening app for connected user ' . $userId);
            UserActivityLog::api($userId, 'button.app_opened', 'باز کردن اپ از دکمه Hamgam');

            Response::redirectThenContinue(
                HamgamRedirects::launcherAppOpenUrl(),
                static function () use ($userId, $accessToken): void {
                    try {
                        require_once __DIR__ . '/../includes/vacation-bootstrap.php';
                        hamgam_load_vacation_modules();
                        HamgamConnectionService::syncAfterAuth($userId, $accessToken);
                    } catch (Throwable $syncError) {
                        RequestContext::logForUser('hamgam/button', $userId, 'connected open sync failed: ' . $syncError->getMessage(), 'error');
                        UserActivityLog::api($userId, 'sync.failed', 'همگام‌سازی هنگام باز کردن اپ ناموفق', 'error', ['error' => $syncError->getMessage()]);
                    }
                }
            );
        }

        RequestContext::logForUser('hamgam/button', $userId, 'repairing partial connection for user ' . $userId);
        UserActivityLog::api($userId, 'button.repair_connection', 'ترمیم اتصال ناقص (widget/sync)');

        try {
            if (!Paziresh24Api::upsertWidget($userId)) {
                RequestContext::log('hamgam/button', 'repair upsertWidget returned false for user ' . $userId);
            }
        } catch (Throwable $repairError) {
            RequestContext::log('hamgam/button', 'repair upsertWidget failed: ' . $repairError->getMessage());
        }

        Response::redirectThenContinue(
            HamgamRedirects::launcherAppOpenUrl(),
            static function () use ($userId, $accessToken): void {
                try {
                    require_once __DIR__ . '/../includes/vacation-bootstrap.php';
                    hamgam_load_vacation_modules();
                    HamgamConnectionService::syncAfterAuth($userId, $accessToken);
                } catch (Throwable $syncError) {
                    RequestContext::logForUser('hamgam/button', $userId, 'repair sync failed: ' . $syncError->getMessage(), 'error');
                    UserActivityLog::api($userId, 'sync.failed', 'همگام‌سازی بعد از ترمیم ناموفق', 'error', ['error' => $syncError->getMessage()]);
                }
            }
        );
    }

    $oauthUrl = Paziresh24Api::buildGoogleOAuthUrl($accessToken, 'launcher', null, $userId);
    UserActivityLog::auth($userId, 'button.oauth_redirect', 'هدایت به اتصال Google (هنوز متصل نیست)');
    Response::redirectThenContinue(
        $oauthUrl,
        static function () use ($userId): void {
            try {
                if (Paziresh24Api::hasWidget($userId)) {
                    RequestContext::logForUser('hamgam/button', $userId, 'removing orphan widget for user ' . $userId);
                    UserActivityLog::api($userId, 'button.widget_cleanup', 'حذف widget یتیم قبل از OAuth');
                    Paziresh24Api::deleteWidget($userId);
                }
            } catch (Throwable $cleanupError) {
                RequestContext::log('hamgam/button', 'orphan widget cleanup failed: ' . $cleanupError->getMessage());
            }
        }
    );
} catch (Throwable $e) {
    RequestContext::log('hamgam/button', $e->getMessage());
    Response::errorRedirect();
}

/**
 * @param array<string, mixed>|null $tokenRow
 * @return array{refresh_token: string, channel_id: string, resource_id: string}|null
 */
function extractWatchCleanupData(?array $tokenRow): ?array
{
    return GoogleTokensRepository::watchCleanupCredentials($tokenRow);
}

/**
 * @param array{refresh_token: string, channel_id: string, resource_id: string} $credentials
 */
function stopGoogleWatchWithCredentials(array $credentials): void
{
    require_once __DIR__ . '/../includes/GoogleCalendarWatch.php';

    $googleTokenData = GoogleCalendar::refreshAccessToken($credentials['refresh_token']);
    $googleAccessToken = is_array($googleTokenData) ? ($googleTokenData['access_token'] ?? '') : '';
    if (!is_string($googleAccessToken) || $googleAccessToken === '') {
        RequestContext::log('hamgam/button', 'could not stop watch: google refresh failed');
        return;
    }

    GoogleCalendarWatch::stopWatch(
        $googleAccessToken,
        $credentials['channel_id'],
        $credentials['resource_id']
    );
    RequestContext::log('hamgam/button', 'google watch stopped channel=' . $credentials['channel_id']);
}
