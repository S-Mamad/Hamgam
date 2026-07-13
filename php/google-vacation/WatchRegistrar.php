<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';

final class WatchRegistrar
{
    public static function registerForUser(
        string $userId,
        string $hamdastAccessToken,
        string $googleAccessToken
    ): bool {
        $tokenRow = GoogleTokensRepository::findByUserId($userId);
        if ($tokenRow !== null) {
            $oldChannelId = (string) ($tokenRow['google_channel_id'] ?? '');
            $oldResourceId = (string) ($tokenRow['google_resource_id'] ?? '');
            if ($oldChannelId !== '' && $oldResourceId !== '') {
                GoogleCalendarWatch::stopWatch($googleAccessToken, $oldChannelId, $oldResourceId);
            }
        }

        $webhookUrl = Config::require('GOOGLE_CALENDAR_WEBHOOK_URL');
        $channelId = GoogleCalendarWatch::generateChannelId();

        $watchResponse = GoogleCalendarWatch::registerWatch(
            $googleAccessToken,
            $webhookUrl,
            $channelId
        );

        if (!is_array($watchResponse)) {
            GoogleVacationRepository::clearWatchData($userId);
            error_log('[google-vacation] watch registration failed for user ' . $userId);
            return false;
        }

        $resourceId = is_string($watchResponse['resourceId'] ?? null) ? $watchResponse['resourceId'] : '';
        $expiration = $watchResponse['expiration'] ?? null;

        if ($resourceId === '' || !is_numeric($expiration)) {
            GoogleVacationRepository::clearWatchData($userId);
            error_log('[google-vacation] invalid watch response for user ' . $userId);
            return false;
        }

        // Persist the watch identity immediately after Google accepts the watch.
        // The sync-token step below pages through the whole calendar and can be slow
        // enough on large accounts that the background worker is killed before it
        // returns — which previously left channel/resource/expiration NULL even though
        // Google had already registered the watch. Saving first makes this recoverable.
        GoogleVacationRepository::saveWatchData(
            $userId,
            $channelId,
            $resourceId,
            (int) $expiration,
            null
        );

        $syncToken = GoogleCalendarWatch::captureInitialSyncToken($googleAccessToken);

        if (is_string($syncToken) && $syncToken !== '') {
            GoogleVacationRepository::saveSyncToken($userId, $syncToken);
        }

        $vacationCenter = Paziresh24VacationApi::resolveVacationCenter($hamdastAccessToken);
        if ($vacationCenter !== null) {
            GoogleVacationRepository::saveCenterId($userId, $vacationCenter['medical_center_id']);
            error_log(
                '[google-vacation] medical_center_id saved for user '
                . $userId
                . ': '
                . $vacationCenter['medical_center_id']
            );
        } else {
            error_log('[google-vacation] medical_center_id not resolved for user ' . $userId);
        }

        error_log('[google-vacation] watch registered for user ' . $userId . ' channel=' . $channelId);

        return true;
    }

    public static function renewForTokenRow(array $tokenRow): bool
    {
        $userId = (string) ($tokenRow['paziresh24_user_id'] ?? '');
        $refreshToken = (string) ($tokenRow['google_refresh_token'] ?? '');
        $hamdastAccessToken = (string) ($tokenRow['hamdast_access_token'] ?? '');
        $oldChannelId = (string) ($tokenRow['google_channel_id'] ?? '');
        $oldResourceId = (string) ($tokenRow['google_resource_id'] ?? '');

        if ($userId === '' || $refreshToken === '' || $hamdastAccessToken === '') {
            return false;
        }

        $googleTokenData = GoogleCalendar::refreshAccessToken($refreshToken);
        $googleAccessToken = is_array($googleTokenData) ? ($googleTokenData['access_token'] ?? '') : '';
        if (!is_string($googleAccessToken) || $googleAccessToken === '') {
            error_log('[google-vacation] renew watch: token refresh failed for user ' . $userId);
            return false;
        }

        if ($oldChannelId !== '' && $oldResourceId !== '') {
            GoogleCalendarWatch::stopWatch($googleAccessToken, $oldChannelId, $oldResourceId);
        }

        return self::registerForUser($userId, $hamdastAccessToken, $googleAccessToken);
    }
}
