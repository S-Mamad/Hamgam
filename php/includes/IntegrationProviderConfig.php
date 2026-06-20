<?php

declare(strict_types=1);

/**
 * Multi-provider OAuth2 configuration loaded from environment variables.
 *
 * Env pattern per provider (example: drdr):
 *   INTEGRATION_DRDR_CLIENT_ID
 *   INTEGRATION_DRDR_CLIENT_SECRET
 *   INTEGRATION_DRDR_AUTH_URL
 *   INTEGRATION_DRDR_TOKEN_URL
 *   INTEGRATION_DRDR_REDIRECT_URI
 *   INTEGRATION_DRDR_SCOPE          (optional)
 *   INTEGRATION_DRDR_EXTRA_AUTH_PARAMS  (optional JSON object)
 */
final class IntegrationProviderConfig
{
    /** @var array<string, true> */
    private static array $knownProviders = [
        'drdr' => true,
    ];

    public static function normalizeSlug(string $provider): string
    {
        $provider = strtolower(trim($provider));
        if ($provider === '' || !preg_match('/^[a-z0-9_-]{2,32}$/', $provider)) {
            throw new InvalidArgumentException('Invalid provider slug');
        }

        return $provider;
    }

    /**
     * @return array<int, string>
     */
    public static function listProviders(): array
    {
        return array_keys(self::$knownProviders);
    }

    public static function isSupported(string $provider): bool
    {
        try {
            $slug = self::normalizeSlug($provider);
        } catch (InvalidArgumentException) {
            return false;
        }

        return isset(self::$knownProviders[$slug]);
    }

    /**
     * @return array{
     *   slug: string,
     *   client_id: string,
     *   client_secret: string,
     *   auth_url: string,
     *   token_url: string,
     *   redirect_uri: string,
     *   scope: string,
     *   extra_auth_params: array<string, string>
     * }
     */
    public static function resolve(string $provider): array
    {
        $slug = self::normalizeSlug($provider);
        if (!self::isSupported($slug)) {
            throw new InvalidArgumentException('Unsupported integration provider: ' . $slug);
        }

        $prefix = 'INTEGRATION_' . strtoupper(str_replace('-', '_', $slug)) . '_';

        $scope = trim((string) Config::get($prefix . 'SCOPE', ''));
        $extraRaw = trim((string) Config::get($prefix . 'EXTRA_AUTH_PARAMS', ''));
        $extraParams = [];

        if ($extraRaw !== '') {
            $decoded = json_decode($extraRaw, true);
            if (is_array($decoded)) {
                foreach ($decoded as $key => $value) {
                    if (is_string($key) && (is_string($value) || is_numeric($value))) {
                        $extraParams[$key] = (string) $value;
                    }
                }
            }
        }

        return [
            'slug' => $slug,
            'client_id' => Config::require($prefix . 'CLIENT_ID'),
            'client_secret' => Config::require($prefix . 'CLIENT_SECRET'),
            'auth_url' => Config::require($prefix . 'AUTH_URL'),
            'token_url' => Config::require($prefix . 'TOKEN_URL'),
            'redirect_uri' => Config::require($prefix . 'REDIRECT_URI'),
            'scope' => $scope,
            'extra_auth_params' => $extraParams,
        ];
    }
}
