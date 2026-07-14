<?php

declare(strict_types=1);

/**
 * معادل PHP workflow n8n: GET /hamgam/google-oauth
 *
 * جریان (بعد از تأیید Google):
 * 1. پارس state و code از query
 * 2. تبدیل code به refresh_token
 * 3. ذخیره/بروزرسانی google_tokens
 * 4. ثبت ویجت در Hamdast (پس از redirect، در پس‌زمینه)
 * 5. هدایت به لانچر پذیرش۲۴ با direct=true (باز شدن تنظیمات داخل iframe)
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/HamgamRedirects.php';
require_once __DIR__ . '/../includes/HamgamSyncMessages.php';

try {
    $state = parseOAuthState($_GET['state'] ?? '');
    $code = $_GET['code'] ?? '';
    $code = is_string($code) ? trim($code) : '';

    if ($code === '') {
        redirectOnOAuthFailure($state, 'missing_code');
    }

    $resolved = resolveOAuthUser($state);
    $userId = $resolved['userId'];
    $hamdastAccessToken = $resolved['hamdastAccessToken'];
    $existingRow = $resolved['tokenRow'];

    if ($userId === null) {
        redirectOnOAuthFailure($state, 'auth_failed');
    }

    if ($hamdastAccessToken === '') {
        redirectOnOAuthFailure($state, 'auth_failed');
    }

    $isChangeGmail = ($state['mode'] ?? '') === 'change_gmail';

    $googleTokens = Paziresh24Api::exchangeGoogleAuthCode($code);
    if (!is_array($googleTokens)) {
        redirectOnOAuthFailure($state, 'exchange_failed');
    }

    $googleAccessToken = is_string($googleTokens['access_token'] ?? null) ? trim($googleTokens['access_token']) : '';
    $googleAccessToken = $googleAccessToken !== '' ? $googleAccessToken : null;

    $refreshToken = is_string($googleTokens['refresh_token'] ?? null) ? trim($googleTokens['refresh_token']) : '';
    if ($refreshToken === '') {
        $refreshToken = resolveRefreshTokenForChangeGmail(
            $isChangeGmail,
            $existingRow,
            $googleAccessToken
        );
    }

    if ($refreshToken === '') {
        $failureReason = $isChangeGmail ? 'new_account_consent' : 'no_refresh_token';
        redirectOnOAuthFailure($state, $failureReason);
    }

    if ($isChangeGmail && $existingRow !== null) {
        stopOldGoogleWatchIfNeeded($existingRow);
    }

    GoogleTokensRepository::upsertOAuthConnection(
        userId: $userId,
        hamdastAccessToken: $hamdastAccessToken,
        googleRefreshToken: $refreshToken,
        googleAccessToken: $googleAccessToken,
        colorId: '9',
        patientName: true,
        patientDateTime: false,
        patientNational: true,
        patientPhone: false,
        existingRow: $existingRow
    );

    // Set pending=true before redirect so the frontend can reliably wait.
    GoogleTokensRepository::markSyncPending($userId, 'sync');

    RequestContext::log('hamgam/google-oauth', 'OAuth success for user ' . $userId);
    UserActivityLog::auth($userId, 'google.connected', 'اتصال Google Calendar موفق', 'info', [
        'change_gmail' => $isChangeGmail,
    ]);

    $googleAccessTokenForEmail = $googleAccessToken;
    $refreshTokenForEmail = $refreshToken;
    $shouldClearWatchData = $isChangeGmail && $existingRow !== null;
    $emailPersistedBeforeRedirect = false;

    if ($isChangeGmail) {
        GoogleTokensRepository::clearGoogleAccountEmail($userId);
        try {
            persistGoogleAccountEmail($userId, $googleAccessTokenForEmail, $refreshTokenForEmail);
            $emailPersistedBeforeRedirect = true;
        } catch (Throwable $emailError) {
            RequestContext::log('hamgam/google-oauth', 'change_gmail email persist failed: ' . $emailError->getMessage());
        }
    }

    $returnTo = $state['return_to'] ?? 'settings';
    if (!is_string($returnTo)) {
        $returnTo = 'settings';
    }
    $mode = is_string($state['mode'] ?? null) ? $state['mode'] : '';

    if ($mode === 'change_gmail' && $returnTo === 'launcher') {
        Response::redirectViaLauncherBridge(
            HamgamRedirects::launcherAppOpenUrl(),
            ['oauth' => 'success', 'change' => 'gmail'],
            'hamgam_oauth_success',
            static function () use (
                $userId,
                $hamdastAccessToken,
                $googleAccessTokenForEmail,
                $refreshTokenForEmail,
                $shouldClearWatchData,
                $emailPersistedBeforeRedirect
            ): void {
                runOAuthSuccessBackgroundWork(
                    $userId,
                    $hamdastAccessToken,
                    $googleAccessTokenForEmail,
                    $refreshTokenForEmail,
                    $shouldClearWatchData,
                    $emailPersistedBeforeRedirect
                );
            }
        );
    }

    Response::redirectThenContinue(
        buildOAuthSuccessRedirectUrl($state),
        static function () use (
            $userId,
            $hamdastAccessToken,
            $googleAccessTokenForEmail,
            $refreshTokenForEmail,
            $shouldClearWatchData,
            $emailPersistedBeforeRedirect
        ): void {
            runOAuthSuccessBackgroundWork(
                $userId,
                $hamdastAccessToken,
                $googleAccessTokenForEmail,
                $refreshTokenForEmail,
                $shouldClearWatchData,
                $emailPersistedBeforeRedirect
            );
        }
    );
} catch (Throwable $e) {
    RequestContext::log('hamgam/google-oauth', $e->getMessage());
    redirectOnOAuthFailure(parseOAuthState($_GET['state'] ?? ''), 'internal_error');
}

/**
 * @param array<string, mixed> $state
 */
function buildOAuthSuccessRedirectUrl(array $state): string
{
    $mode = $state['mode'] ?? '';
    $returnTo = $state['return_to'] ?? 'settings';
    if (!is_string($returnTo)) {
        $returnTo = 'settings';
    }

    if ($returnTo === 'launcher') {
        return HamgamRedirects::launcherAppOpenUrl();
    }

    $settingsUrl = rtrim(Config::require('REDIRECT_SETTINGS'), '/');
    $query = ['oauth' => 'success'];
    if ($mode === 'change_gmail') {
        $query['change'] = 'gmail';
    }

    return $settingsUrl . '/?' . http_build_query($query);
}

/**
 * @param array<string, mixed> $state
 */
function redirectAfterOAuth(array $state): void
{
    Response::redirect(buildOAuthSuccessRedirectUrl($state));
}

/**
 * @param array<string, mixed> $state
 */
function redirectOnOAuthFailure(array $state, string $reason): never
{
    $mode = is_string($state['mode'] ?? null) ? $state['mode'] : '';
    $returnTo = $state['return_to'] ?? 'settings';
    if (!is_string($returnTo)) {
        $returnTo = 'settings';
    }

    $query = [
        'oauth' => 'error',
        'reason' => $reason,
    ];
    if ($mode === 'change_gmail') {
        $query['change'] = 'gmail';
    }

    if ($returnTo === 'launcher') {
        $launcherUrl = HamgamRedirects::launcherAppOpenUrl();
        RequestContext::log('hamgam/google-oauth', 'OAuth failure reason=' . $reason . ' redirect=launcher-bridge');
        Response::redirectViaLauncherBridge($launcherUrl, $query);
    }

    $settingsUrl = rtrim(Config::require('REDIRECT_SETTINGS'), '/');
    RequestContext::log('hamgam/google-oauth', 'OAuth failure reason=' . $reason . ' redirect=settings');
    Response::redirectFast($settingsUrl . '/?' . http_build_query($query));
}

/**
 * @param array<string, mixed> $state
 * @return array{userId: ?string, hamdastAccessToken: string, tokenRow: ?array}
 */
function resolveOAuthUser(array $state): array
{
    $hamdastAccessToken = extractHamdastAccessToken($state);
    $userId = null;
    $tokenRow = null;

    if ($hamdastAccessToken !== '') {
        $userId = Paziresh24Api::extractUserIdFromJwt($hamdastAccessToken);
    }

    if ($userId === null) {
        $stateUserId = $state['user_id'] ?? '';
        if (is_string($stateUserId) && trim($stateUserId) !== '') {
            $userId = GoogleTokensRepository::normalizeUserId($stateUserId);
        }
    }

    if ($userId === null && $hamdastAccessToken !== '') {
        $userId = Paziresh24Api::resolveUserId($hamdastAccessToken);
    }

    if ($userId === null) {
        return [
            'userId' => null,
            'hamdastAccessToken' => $hamdastAccessToken,
            'tokenRow' => null,
        ];
    }

    if ($hamdastAccessToken === '') {
        $tokenRow = GoogleTokensRepository::findByUserId($userId);
        if (is_array($tokenRow)) {
            $dbToken = trim((string) ($tokenRow['hamdast_access_token'] ?? ''));
            if ($dbToken !== '') {
                $hamdastAccessToken = $dbToken;
            }
        }
    } else {
        $tokenRow = GoogleTokensRepository::findByUserId($userId);
    }

    return [
        'userId' => $userId,
        'hamdastAccessToken' => $hamdastAccessToken,
        'tokenRow' => is_array($tokenRow) ? $tokenRow : null,
    ];
}

/**
 * @param array<string, mixed>|null $existingRow
 */
function resolveRefreshTokenForChangeGmail(
    bool $isChangeGmail,
    ?array $existingRow,
    ?string $googleAccessToken
): string {
    if (!$isChangeGmail || $existingRow === null) {
        return '';
    }

    $existingRefresh = trim((string) ($existingRow['google_refresh_token'] ?? ''));
    if ($existingRefresh === '') {
        return '';
    }

    if ($googleAccessToken === null) {
        return $existingRefresh;
    }

    $newEmail = GoogleCalendar::resolveAccountEmail($googleAccessToken);
    $oldEmail = trim((string) ($existingRow['google_account_email'] ?? ''));

    if ($newEmail === null) {
        return $existingRefresh;
    }

    if ($oldEmail === '') {
        $existingAccessData = GoogleCalendar::refreshAccessToken($existingRefresh);
        $existingAccessToken = is_array($existingAccessData)
            ? trim((string) ($existingAccessData['access_token'] ?? ''))
            : '';
        if ($existingAccessToken !== '') {
            $existingEmail = GoogleCalendar::resolveAccountEmail($existingAccessToken);
            if ($existingEmail !== null && strcasecmp($existingEmail, $newEmail) === 0) {
                return $existingRefresh;
            }
        }

        RequestContext::log('hamgam/google-oauth', 'change_gmail new account requires refresh_token from Google');
        return '';
    }

    if (strcasecmp($newEmail, $oldEmail) === 0) {
        return $existingRefresh;
    }

    RequestContext::log(
        'hamgam/google-oauth',
        'new Google account without refresh_token'
        . ' old=' . $oldEmail
        . ' new=' . $newEmail
    );

    return '';
}

/**
 * @return array<string, mixed>
 */
function parseOAuthState(mixed $stateRaw): array
{
    if (!is_string($stateRaw) || trim($stateRaw) === '') {
        return [];
    }

    $decoded = urldecode($stateRaw);
    $parsed = json_decode($decoded, true);
    if (is_array($parsed)) {
        return $parsed;
    }

    $parsed = json_decode($stateRaw, true);

    return is_array($parsed) ? $parsed : [];
}

/**
 * @param array<string, mixed> $state
 */
function extractHamdastAccessToken(array $state): string
{
    $token = $state['acces_token'] ?? $state['token'] ?? '';
    if (!is_string($token) || trim($token) === '') {
        return '';
    }

    if (!preg_match('/^[A-Za-z0-9\-_]+\.[A-Za-z0-9\-_]+\.[A-Za-z0-9\-_]+$/', $token)) {
        return '';
    }

    return $token;
}

function persistGoogleAccountEmail(string $userId, ?string $googleAccessToken, string $refreshToken): void
{
    $accessToken = is_string($googleAccessToken) ? trim($googleAccessToken) : '';
    if ($accessToken === '' && $refreshToken !== '') {
        $refreshed = GoogleCalendar::refreshAccessToken($refreshToken);
        $accessToken = is_array($refreshed) ? trim((string) ($refreshed['access_token'] ?? '')) : '';
    }

    if ($accessToken === '') {
        return;
    }

    $accountEmail = GoogleCalendar::resolveAccountEmail($accessToken);
    if ($accountEmail !== null) {
        GoogleTokensRepository::saveGoogleAccountEmail($userId, $accountEmail);
    }
}

function runOAuthSuccessBackgroundWork(
    string $userId,
    string $hamdastAccessToken,
    ?string $googleAccessToken,
    string $refreshToken,
    bool $shouldClearWatchData,
    bool $emailPersistedBeforeRedirect
): void {
    // Important: let the frontend poll and wait until DB writes + sync are done.
    // Without this, the UI may paint stale google_account_email / backfill state.
    GoogleTokensRepository::markSyncPending($userId, 'sync');

    if ($shouldClearWatchData) {
        try {
            require_once __DIR__ . '/../includes/GoogleVacationRepository.php';
            require_once __DIR__ . '/../includes/GoogleCalendarBookingRepository.php';
            if (!$emailPersistedBeforeRedirect) {
                GoogleTokensRepository::clearGoogleAccountEmail($userId);
            }
            GoogleVacationRepository::clearWatchData($userId);
            GoogleVacationRepository::clearProcessedEvents($userId);
            GoogleCalendarBookingRepository::clearAllForUser($userId);
        } catch (Throwable $clearError) {
            RequestContext::log('hamgam/google-oauth', 'gmail change cleanup failed: ' . $clearError->getMessage());
        }
    }

    if (!$emailPersistedBeforeRedirect) {
        try {
            persistGoogleAccountEmail($userId, $googleAccessToken, $refreshToken);
        } catch (Throwable $emailError) {
            RequestContext::log('hamgam/google-oauth', 'persistGoogleAccountEmail failed: ' . $emailError->getMessage());
        }
    }

    try {
        if (!Paziresh24Api::upsertWidget($userId)) {
            RequestContext::log('hamgam/google-oauth', 'upsertWidget returned false for user ' . $userId);
        }
    } catch (Throwable $widgetError) {
        RequestContext::log('hamgam/google-oauth', 'upsertWidget failed: ' . $widgetError->getMessage());
    }

    try {
        require_once __DIR__ . '/../includes/vacation-bootstrap.php';
        hamgam_load_vacation_modules();

        $result = HamgamConnectionService::syncAfterAuth($userId, $hamdastAccessToken);
        GoogleTokensRepository::saveSyncStatus($userId, [
            'pending' => false,
            'operation' => 'sync',
            'ok' => $result['ok'],
            'warnings' => $result['warnings'],
            'backfill' => null,
        ]);
    } catch (Throwable $watchError) {
        RequestContext::log('hamgam/google-oauth', 'connection sync failed: ' . $watchError->getMessage());
        GoogleTokensRepository::saveSyncStatus($userId, [
            'pending' => false,
            'operation' => 'sync',
            'ok' => false,
            'warnings' => [HamgamSyncMessages::warning('sync_failed')],
            'backfill' => null,
        ]);
    }
}

/**
 * @param array<string, mixed>|null $tokenRow
 */
function stopOldGoogleWatchIfNeeded(?array $tokenRow): void
{
    if ($tokenRow === null) {
        return;
    }

    $channelId = (string) ($tokenRow['google_channel_id'] ?? '');
    $resourceId = (string) ($tokenRow['google_resource_id'] ?? '');
    $refreshToken = (string) ($tokenRow['google_refresh_token'] ?? '');

    if ($channelId === '' || $resourceId === '' || $refreshToken === '') {
        return;
    }

    require_once __DIR__ . '/../includes/GoogleCalendarWatch.php';

    $googleTokenData = GoogleCalendar::refreshAccessToken($refreshToken);
    $googleAccessToken = is_array($googleTokenData) ? ($googleTokenData['access_token'] ?? '') : '';
    if (!is_string($googleAccessToken) || $googleAccessToken === '') {
        RequestContext::log('hamgam/google-oauth', 'could not stop old watch: google refresh failed');
        return;
    }

    GoogleCalendarWatch::stopWatch($googleAccessToken, $channelId, $resourceId);
    RequestContext::log('hamgam/google-oauth', 'old google watch stopped channel=' . $channelId);
}
