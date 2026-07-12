<?php

declare(strict_types=1);

/**
 * معادل PHP workflow n8n: POST /hamgam/auth
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/HamgamSyncMessages.php';

Request::applyCors();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    Response::jsonError('Method not allowed', 405);
}

try {
    $body = Request::jsonBody();
    if ($body === null) {
        Response::jsonError('Invalid JSON body', 400);
    }

    $sessionToken = $body['hamdast_session_token'] ?? '';
    if (!is_string($sessionToken) || trim($sessionToken) === '') {
        Response::jsonError('Missing session token', 400);
    }

    $sessionToken = urldecode(trim($sessionToken));
    if (!preg_match('/^[A-Za-z0-9\-_]+\.[A-Za-z0-9\-_]+\.[A-Za-z0-9\-_]+$/', $sessionToken)) {
        Response::jsonError('Invalid session token', 400);
    }

    $tokenData = Paziresh24Api::exchangeSessionToken($sessionToken);
    $accessToken = is_array($tokenData) ? ($tokenData['access_token'] ?? '') : '';
    if (!is_string($accessToken) || $accessToken === '') {
        Response::jsonError('Authentication failed', 401);
    }

    $userId = Paziresh24Api::resolveUserId($accessToken);
    if ($userId === null) {
        Response::jsonError('User not found', 404);
    }

    GoogleTokensRepository::upsertHamdastAccessToken($userId, $accessToken);

    $tokenRow = GoogleTokensRepository::findByUserId($userId);
    $connected = GoogleTokensRepository::hasRefreshToken($tokenRow);

    $settings = GoogleTokensRepository::getSettings($tokenRow);
    $settings['connected'] = $connected;

    $response = [
        'access_token' => $accessToken,
        'connected' => $connected,
        'settings' => $settings,
    ];

    if ($settings['google_account_email'] !== null) {
        $response['google_account_email'] = $settings['google_account_email'];
    }

    if (!$connected) {
        $response['oauth_url'] = Paziresh24Api::buildGoogleOAuthUrl($accessToken, 'settings', null, $userId);
    }

    $needsBackgroundSync = $connected && (
        GoogleTokensRepository::needsWatchRegistration($tokenRow)
        || GoogleTokensRepository::needsSyncTokenRepair($tokenRow)
    );

    if (!$needsBackgroundSync) {
        Response::json($response);
    }

    Response::jsonThenContinue(
        $response,
        static function () use ($userId, $accessToken): void {
            GoogleTokensRepository::markSyncPending($userId, 'sync');

            try {
                require_once __DIR__ . '/../includes/vacation-bootstrap.php';
                hamgam_load_vacation_modules();

                $result = HamgamConnectionService::syncAfterAuth($userId, $accessToken);
                GoogleTokensRepository::saveSyncStatus($userId, [
                    'pending' => false,
                    'operation' => 'sync',
                    'ok' => $result['ok'],
                    'warnings' => $result['warnings'],
                    'backfill' => null,
                ]);
            } catch (Throwable $syncError) {
                RequestContext::log('hamgam/auth', 'syncAfterAuth failed: ' . $syncError->getMessage());
                GoogleTokensRepository::saveSyncStatus($userId, [
                    'pending' => false,
                    'operation' => 'sync',
                    'ok' => false,
                    'warnings' => [HamgamSyncMessages::warning('sync_failed')],
                    'backfill' => null,
                ]);
            }
        }
    );
} catch (Throwable $e) {
    RequestContext::log('hamgam/auth', $e->getMessage());
    Response::jsonError('Internal server error', 500);
}