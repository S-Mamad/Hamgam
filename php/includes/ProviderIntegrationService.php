<?php

declare(strict_types=1);

final class ProviderIntegrationService
{
    private const EXPIRY_SKEW_SECONDS = 60;

    /**
     * @return array{mode: string, oauth_ready: bool, url: string}
     */
    public static function buildConnectTarget(string $provider, string $doctorId, string $returnTo = 'settings'): array
    {
        $config = IntegrationProviderConfig::resolveWithDefaults($provider);
        $doctorId = GoogleTokensRepository::normalizeUserId($doctorId);

        if (!$config['oauth_ready']) {
            return [
                'mode' => 'login',
                'oauth_ready' => false,
                'url' => $config['login_url'],
            ];
        }

        return [
            'mode' => 'oauth',
            'oauth_ready' => true,
            'url' => self::buildOAuthAuthorizeUrl($config, $doctorId, $returnTo),
        ];
    }

    /**
     * Builds the provider authorization URL (OAuth2 Authorization Code Flow).
     */
    public static function connect(string $provider, string $doctorId, string $returnTo = 'settings'): string
    {
        $target = self::buildConnectTarget($provider, $doctorId, $returnTo);
        if (!$target['oauth_ready']) {
            throw new IntegrationException(
                'oauth_not_ready',
                'OAuth DrDr هنوز فعال نشده. client_id و client_secret را در php/config/providers/drdr.php قرار دهید.'
            );
        }

        return $target['url'];
    }

    /**
     * @param array{
     *   slug: string,
     *   client_id: string,
     *   client_secret: string,
     *   auth_url: string,
     *   token_url: string,
     *   redirect_uri: string,
     *   login_url: string,
     *   scope: string,
     *   extra_auth_params: array<string, string>,
     *   oauth_ready: bool
     * } $config
     */
    private static function buildOAuthAuthorizeUrl(array $config, string $doctorId, string $returnTo): string
    {
        $state = OAuthStateSigner::create($doctorId, $config['slug'], [
            'return_to' => $returnTo,
        ]);

        $params = [
            'client_id' => $config['client_id'],
            'redirect_uri' => $config['redirect_uri'],
            'response_type' => 'code',
            'state' => $state,
        ];

        if ($config['scope'] !== '') {
            $params['scope'] = $config['scope'];
        }

        foreach ($config['extra_auth_params'] as $key => $value) {
            $params[$key] = $value;
        }

        return rtrim($config['auth_url'], '?&') . '?' . http_build_query($params);
    }

    /**
     * Exchanges authorization code, stores encrypted tokens, returns redirect target info.
     *
     * @return array{doctor_id: string, provider: string, return_to: string}
     */
    public static function handleCallback(string $provider, string $code, string $state): array
    {
        $config = IntegrationProviderConfig::resolve($provider);
        $statePayload = OAuthStateSigner::verify($state, $config['slug']);
        if ($statePayload === null) {
            throw new IntegrationException('invalid_state', 'OAuth state validation failed');
        }

        $doctorId = (string) ($statePayload['doctor_id'] ?? '');
        if ($doctorId === '') {
            throw new IntegrationException('invalid_state', 'Missing doctor_id in OAuth state');
        }

        $tokenResponse = self::exchangeAuthorizationCode($config, $code);
        $accessToken = trim((string) ($tokenResponse['access_token'] ?? ''));
        if ($accessToken === '') {
            throw new IntegrationException('token_exchange_failed', 'Provider did not return access_token');
        }

        $refreshToken = $tokenResponse['refresh_token'] ?? null;
        $refreshToken = is_string($refreshToken) && trim($refreshToken) !== '' ? trim($refreshToken) : null;
        $expiresAt = self::resolveExpiresAt($tokenResponse);

        DoctorExternalConnectionsRepository::upsert(
            doctorId: $doctorId,
            provider: $config['slug'],
            accessToken: $accessToken,
            refreshToken: $refreshToken,
            expiresAt: $expiresAt
        );

        self::logIntegrationEvent($doctorId, $config['slug'], true, 'callback_success');

        $returnTo = (string) ($statePayload['return_to'] ?? 'settings');
        if (!in_array($returnTo, ['settings', 'launcher'], true)) {
            $returnTo = 'settings';
        }

        return [
            'doctor_id' => $doctorId,
            'provider' => $config['slug'],
            'return_to' => $returnTo,
        ];
    }

    public static function getValidAccessToken(string $doctorId, string $provider): ?string
    {
        $tokens = DoctorExternalConnectionsRepository::getDecryptedTokens($doctorId, $provider);
        if ($tokens === null) {
            return null;
        }

        $accessToken = trim($tokens['access_token']);
        if ($accessToken === '') {
            return null;
        }

        $expiresAt = $tokens['expires_at'];
        if ($expiresAt !== null && $expiresAt <= (time() + self::EXPIRY_SKEW_SECONDS)) {
            if (!self::refreshAccessToken($doctorId, $provider)) {
                return null;
            }

            $refreshed = DoctorExternalConnectionsRepository::getDecryptedTokens($doctorId, $provider);

            return is_array($refreshed) ? trim((string) ($refreshed['access_token'] ?? '')) : null;
        }

        return $accessToken;
    }

    public static function refreshAccessToken(string $doctorId, string $provider): bool
    {
        $config = IntegrationProviderConfig::resolve($provider);
        $tokens = DoctorExternalConnectionsRepository::getDecryptedTokens($doctorId, $config['slug']);
        if ($tokens === null) {
            self::logIntegrationEvent($doctorId, $config['slug'], false, 'refresh_not_connected');
            return false;
        }

        $refreshToken = $tokens['refresh_token'] ?? null;
        if (!is_string($refreshToken) || trim($refreshToken) === '') {
            self::logIntegrationEvent($doctorId, $config['slug'], false, 'refresh_missing_refresh_token');
            return false;
        }

        try {
            $response = HttpClient::request(
                'POST',
                $config['token_url'],
                ['Content-Type' => 'application/x-www-form-urlencoded'],
                [
                    'grant_type' => 'refresh_token',
                    'client_id' => $config['client_id'],
                    'client_secret' => $config['client_secret'],
                    'refresh_token' => $refreshToken,
                ]
            );
        } catch (Throwable $e) {
            self::logIntegrationEvent($doctorId, $config['slug'], false, 'refresh_http_error');
            return false;
        }

        if ($response['status'] < 200 || $response['status'] >= 300 || !is_array($response['body'])) {
            self::logIntegrationEvent($doctorId, $config['slug'], false, 'refresh_provider_rejected');
            return false;
        }

        $newAccessToken = trim((string) ($response['body']['access_token'] ?? ''));
        if ($newAccessToken === '') {
            self::logIntegrationEvent($doctorId, $config['slug'], false, 'refresh_no_access_token');
            return false;
        }

        $newRefreshToken = $response['body']['refresh_token'] ?? null;
        $newRefreshToken = is_string($newRefreshToken) && trim($newRefreshToken) !== ''
            ? trim($newRefreshToken)
            : $refreshToken;

        DoctorExternalConnectionsRepository::upsert(
            doctorId: $doctorId,
            provider: $config['slug'],
            accessToken: $newAccessToken,
            refreshToken: $newRefreshToken,
            expiresAt: self::resolveExpiresAt($response['body'])
        );

        self::logIntegrationEvent($doctorId, $config['slug'], true, 'refresh_success');

        return true;
    }

    public static function disconnect(string $doctorId, string $provider): bool
    {
        $slug = IntegrationProviderConfig::normalizeSlug($provider);
        $deleted = DoctorExternalConnectionsRepository::delete($doctorId, $slug);
        self::logIntegrationEvent(
            GoogleTokensRepository::normalizeUserId($doctorId),
            $slug,
            $deleted,
            $deleted ? 'disconnect_success' : 'disconnect_not_found'
        );

        return $deleted;
    }

    /**
     * Resolves doctor_id from a valid Paziresh24 access token (JWT/API).
     */
    public static function resolveDoctorIdFromAccessToken(string $accessToken): ?string
    {
        $accessToken = trim($accessToken);
        if ($accessToken === '' || !Request::isValidJwtPublic($accessToken)) {
            return null;
        }

        return Paziresh24Api::resolveUserId($accessToken);
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private static function exchangeAuthorizationCode(array $config, string $code): array
    {
        $code = trim($code);
        if ($code === '') {
            throw new IntegrationException('missing_code', 'Authorization code is required');
        }

        try {
            $response = HttpClient::request(
                'POST',
                $config['token_url'],
                ['Content-Type' => 'application/x-www-form-urlencoded'],
                [
                    'grant_type' => 'authorization_code',
                    'client_id' => $config['client_id'],
                    'client_secret' => $config['client_secret'],
                    'code' => $code,
                    'redirect_uri' => $config['redirect_uri'],
                ]
            );
        } catch (Throwable $e) {
            throw new IntegrationException('token_exchange_failed', 'Token exchange HTTP request failed');
        }

        if ($response['status'] < 200 || $response['status'] >= 300 || !is_array($response['body'])) {
            throw new IntegrationException('token_exchange_failed', 'Provider rejected token exchange');
        }

        return $response['body'];
    }

    /**
     * @param array<string, mixed> $tokenResponse
     */
    private static function resolveExpiresAt(array $tokenResponse): ?int
    {
        $expiresIn = $tokenResponse['expires_in'] ?? null;
        if (!is_numeric($expiresIn)) {
            return null;
        }

        return time() + max(0, (int) $expiresIn);
    }

    public static function logIntegrationEvent(
        string $doctorId,
        string $provider,
        bool $success,
        string $reason
    ): void {
        RequestContext::log(
            'integrations/oauth',
            'doctor_id=' . GoogleTokensRepository::normalizeUserId($doctorId)
            . ' provider=' . IntegrationProviderConfig::normalizeSlug($provider)
            . ' status=' . ($success ? 'success' : 'failure')
            . ' reason=' . $reason
        );
    }
}

final class IntegrationException extends RuntimeException
{
    public function __construct(
        private readonly string $reasonCode,
        string $message
    ) {
        parent::__construct($message);
    }

    public function reasonCode(): string
    {
        return $this->reasonCode;
    }
}
