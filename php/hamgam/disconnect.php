<?php

declare(strict_types=1);

/**
 * قطع اتصال Google Calendar بدون حذف تنظیمات کاربر.
 * POST /hamgam/disconnect
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/GoogleCalendarWatch.php';

Request::applyCors();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    Response::jsonError('Method not allowed', 405);
}

try {
    $accessToken = Request::accessToken();
    if ($accessToken === '') {
        Response::jsonError('Unauthorized', 401);
    }

    $userId = Paziresh24Api::resolveUserId($accessToken);
    if ($userId === null) {
        Response::jsonError('User not found', 404);
    }

    GoogleTokensRepository::upsertHamdastAccessToken($userId, $accessToken);

    $tokenRow = GoogleTokensRepository::findByUserId($userId);
    if (!GoogleTokensRepository::hasRefreshToken($tokenRow)) {
        Response::jsonError('Google account not connected', 400);
    }

    $watchCleanup = GoogleTokensRepository::watchCleanupCredentials($tokenRow);
    if (!GoogleTokensRepository::disconnectGoogleConnection($userId)) {
        Response::jsonError('Google account not connected', 400);
    }

    RequestContext::log('hamgam/disconnect', 'disconnected user ' . $userId);

    try {
        Paziresh24Api::deleteWidget($userId);
    } catch (Throwable $widgetError) {
        RequestContext::log('hamgam/disconnect', 'deleteWidget failed: ' . $widgetError->getMessage());
    }

    $tokenRow = GoogleTokensRepository::findByUserId($userId);
    $settings = GoogleTokensRepository::getSettings($tokenRow);
    $settings['connected'] = false;

    Response::jsonThenContinue(
        [
            'ok' => true,
            'connected' => false,
            'settings' => $settings,
            'oauth_url' => Paziresh24Api::buildGoogleOAuthUrl($accessToken, 'settings', null, $userId),
        ],
        static function () use ($watchCleanup): void {
            if ($watchCleanup !== null) {
                stopGoogleWatchFromCredentials($watchCleanup);
            }
        }
    );
} catch (Throwable $e) {
    RequestContext::log('hamgam/disconnect', $e->getMessage());
    Response::jsonError('Internal server error', 500);
}

/**
 * @param array{refresh_token: string, channel_id: string, resource_id: string} $credentials
 */
function stopGoogleWatchFromCredentials(array $credentials): void
{
    $googleTokenData = GoogleCalendar::refreshAccessToken($credentials['refresh_token']);
    $googleAccessToken = is_array($googleTokenData) ? ($googleTokenData['access_token'] ?? '') : '';
    if (!is_string($googleAccessToken) || $googleAccessToken === '') {
        RequestContext::log('hamgam/disconnect', 'could not stop watch: google refresh failed');
        return;
    }

    GoogleCalendarWatch::stopWatch(
        $googleAccessToken,
        $credentials['channel_id'],
        $credentials['resource_id']
    );
    RequestContext::log('hamgam/disconnect', 'google watch stopped channel=' . $credentials['channel_id']);
}
