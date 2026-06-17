<?php

declare(strict_types=1);

final class Paziresh24Api
{
    public static function exchangeSessionToken(string $sessionToken): ?array
    {
        $response = HttpClient::request(
            'POST',
            Config::require('PAZIRESH24_OAUTH_URL'),
            [
                'x-api-key' => Config::require('HAMDAST_API_KEY'),
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            ['session_token' => $sessionToken]
        );

        if ($response['status'] < 200 || $response['status'] >= 300) {
            error_log('[Paziresh24Api] exchangeSessionToken failed: HTTP ' . $response['status'] . ' ' . $response['raw']);
            return null;
        }

        return $response['body'];
    }

    public static function getProfile(string $accessToken): ?array
    {
        $response = HttpClient::request(
            'GET',
            Config::require('PAZIRESH24_PROFILE_URL'),
            ['Authorization' => 'Bearer ' . $accessToken]
        );

        if ($response['status'] < 200 || $response['status'] >= 300) {
            return null;
        }

        return $response['body'];
    }

    public static function getUserInformation(string $accessToken): ?array
    {
        $response = HttpClient::request(
            'GET',
            Config::require('PAZIRESH24_USER_INFO_URL'),
            ['Authorization' => 'Bearer ' . $accessToken]
        );

        if ($response['status'] < 200 || $response['status'] >= 300) {
            return null;
        }

        return $response['body'];
    }

    public static function getOpenPlatformUserInformation(string $accessToken): ?array
    {
        $response = HttpClient::request(
            'GET',
            Config::require('PAZIRESH24_OPEN_USER_INFO_URL'),
            ['Authorization' => 'Bearer ' . $accessToken]
        );

        if ($response['status'] < 200 || $response['status'] >= 300) {
            return null;
        }

        return $response['body'];
    }

    public static function extractUserId(?array $userInfo): ?string
    {
        if (!is_array($userInfo)) {
            return null;
        }

        if (isset($userInfo['data']) && is_array($userInfo['data'])) {
            $nested = self::extractUserId($userInfo['data']);
            if ($nested !== null) {
                return $nested;
            }
        }

        $users = $userInfo['users'] ?? null;
        if (!is_array($users) || !isset($users[0]) || !is_array($users[0])) {
            return null;
        }

        $userId = $users[0]['id'] ?? null;
        if ($userId === null || $userId === '') {
            return null;
        }

        return GoogleTokensRepository::normalizeUserId((string) $userId);
    }

    public static function resolveUserId(string $accessToken): ?string
    {
        $userId = self::extractUserIdFromJwt($accessToken);
        if ($userId !== null) {
            return $userId;
        }

        $userInfo = self::getOpenPlatformUserInformation($accessToken);
        $userId = self::extractUserId($userInfo);
        if ($userId !== null) {
            return $userId;
        }

        $userInfo = self::getUserInformation($accessToken);

        return self::extractUserId($userInfo);
    }

    public static function extractUserIdFromJwt(string $accessToken): ?string
    {
        $parts = explode('.', $accessToken);
        if (count($parts) !== 3) {
            return null;
        }

        $payloadJson = self::base64UrlDecode($parts[1]);
        if ($payloadJson === null) {
            return null;
        }

        $payload = json_decode($payloadJson, true);
        if (!is_array($payload)) {
            return null;
        }

        $sub = $payload['sub'] ?? null;
        if (!is_string($sub) || trim($sub) === '') {
            return null;
        }

        return GoogleTokensRepository::normalizeUserId(trim($sub));
    }

    private static function base64UrlDecode(string $data): ?string
    {
        $remainder = strlen($data) % 4;
        if ($remainder > 0) {
            $data .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($data, '-_', '+/'), true);

        return is_string($decoded) ? $decoded : null;
    }

    public static function isValidSessionToken(string $token): bool
    {
        if (strlen($token) < 20 || strlen($token) > 4096) {
            return false;
        }

        return (bool) preg_match('/^[A-Za-z0-9\-_]+\.[A-Za-z0-9\-_]+\.[A-Za-z0-9\-_]+$/', $token);
    }

    public static function parseSessionTokenFromQuery(mixed $raw): string
    {
        if (!is_string($raw)) {
            return '';
        }

        return urldecode(trim($raw));
    }

    public static function hasWidget(string $userId): bool
    {
        $url = rtrim(Config::require('HAMDAST_WIDGETS_URL'), '/') . '/' . rawurlencode($userId);

        $response = HttpClient::request(
            'GET',
            $url,
            ['X-API-Key' => Config::require('HAMDAST_API_KEY')]
        );

        if ($response['status'] < 200 || $response['status'] >= 300) {
            return false;
        }

        $body = $response['body'];
        if (!is_array($body) || $body === []) {
            return false;
        }

        return true;
    }

    public static function deleteWidget(string $userId): bool
    {
        $url = rtrim(Config::require('HAMDAST_WIDGETS_URL'), '/') . '/' . rawurlencode($userId);

        $response = HttpClient::request(
            'DELETE',
            $url,
            ['X-API-Key' => Config::require('HAMDAST_API_KEY')]
        );

        return $response['status'] >= 200 && $response['status'] < 300;
    }

    public static function upsertWidget(string $userId): bool
    {
        $url = rtrim(Config::require('HAMDAST_WIDGETS_URL'), '/') . '/' . rawurlencode($userId);

        $response = HttpClient::request(
            'PUT',
            $url,
            ['X-API-Key' => Config::require('HAMDAST_API_KEY')]
        );

        return $response['status'] >= 200 && $response['status'] < 300;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function exchangeGoogleAuthCode(string $code): ?array
    {
        $response = HttpClient::request(
            'POST',
            Config::get('GOOGLE_TOKEN_URL', 'https://oauth2.googleapis.com/token'),
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            [
                'client_id' => Config::require('GOOGLE_CLIENT_ID'),
                'client_secret' => Config::require('GOOGLE_CLIENT_SECRET'),
                'code' => $code,
                'grant_type' => 'authorization_code',
                'redirect_uri' => Config::require('GOOGLE_OAUTH_CALLBACK_URI'),
            ]
        );

        if ($response['status'] < 200 || $response['status'] >= 300) {
            return null;
        }

        return $response['body'];
    }

    public static function buildGoogleOAuthUrl(
        string $accessToken,
        string $returnTo = 'settings',
        ?string $mode = null,
        ?string $userId = null,
        ?string $loginHint = null
    ): string {
        $stateData = [
            'acces_token' => $accessToken,
            'token' => $accessToken,
            'return_to' => $returnTo,
        ];

        if (is_string($mode) && trim($mode) !== '') {
            $stateData['mode'] = trim($mode);
        }

        if (is_string($userId) && trim($userId) !== '') {
            $stateData['user_id'] = GoogleTokensRepository::normalizeUserId($userId);
        }

        $state = json_encode($stateData, JSON_UNESCAPED_SLASHES);

        $scope = trim(Config::require('GOOGLE_OAUTH_SCOPE'));
        if (!str_contains($scope, 'userinfo.email')) {
            $scope .= ' https://www.googleapis.com/auth/userinfo.email';
        }

        $params = [
            'client_id' => Config::require('GOOGLE_CLIENT_ID'),
            'redirect_uri' => Config::require('GOOGLE_OAUTH_CALLBACK_URI'),
            'response_type' => 'code',
            'scope' => $scope,
            'access_type' => 'offline',
            'prompt' => $mode === 'change_gmail' ? 'consent select_account' : 'consent',
            'state' => $state ?: '',
        ];

        if ($mode === 'change_gmail') {
            $params['include_granted_scopes'] = 'true';
        }

        if (is_string($loginHint) && trim($loginHint) !== '' && filter_var(trim($loginHint), FILTER_VALIDATE_EMAIL)) {
            $params['login_hint'] = trim($loginHint);
        }

        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
    }
}
