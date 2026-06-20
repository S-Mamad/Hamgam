<?php

declare(strict_types=1);

/**
 * Multi-provider OAuth2 configuration.
 * Defaults live in php/config/providers/{slug}.php — no .env edits required.
 * Optional env vars (INTEGRATION_DRDR_*) override file defaults.
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

    public static function isEncryptionReady(): bool
    {
        try {
            IntegrationSecrets::encryptionKey();
            IntegrationSecrets::oauthStateSecret();

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public static function isOAuthReady(string $provider): bool
    {
        if (!self::isSupported($provider)) {
            return false;
        }

        $config = self::loadMergedConfig(self::normalizeSlug($provider));

        return trim($config['client_id']) !== ''
            && trim($config['client_secret']) !== ''
            && trim($config['auth_url']) !== ''
            && trim($config['token_url']) !== ''
            && trim($config['redirect_uri']) !== '';
    }

    /** @deprecated use isOAuthReady */
    public static function isConfigured(string $provider): bool
    {
        return self::isOAuthReady($provider);
    }

    public static function callbackUrl(string $provider): string
    {
        $slug = self::normalizeSlug($provider);
        $prefix = 'INTEGRATION_' . strtoupper(str_replace('-', '_', $slug)) . '_';
        $fromEnv = Config::get($prefix . 'REDIRECT_URI');
        if (is_string($fromEnv) && trim($fromEnv) !== '') {
            return trim($fromEnv);
        }

        $base = rtrim((string) Config::get('APP_BASE_URL', ''), '/');
        if ($base === '') {
            $base = rtrim((string) Config::get('REDIRECT_SETTINGS', ''), '/');
        }

        return $base . '/integrations/' . $slug . '/callback';
    }

    public static function loginUrl(string $provider): string
    {
        $config = self::loadMergedConfig(self::normalizeSlug($provider));

        return trim($config['login_url']) !== ''
            ? trim($config['login_url'])
            : 'https://panel.drdr.ir/';
    }

    /**
     * @return array<int, string>
     */
    public static function missingRequirements(string $provider): array
    {
        if (!self::isSupported($provider)) {
            return ['unsupported_provider'];
        }

        $missing = [];
        $config = self::loadMergedConfig(self::normalizeSlug($provider));

        if (trim($config['client_id']) === '') {
            $missing[] = 'client_id';
        }
        if (trim($config['client_secret']) === '') {
            $missing[] = 'client_secret';
        }

        return $missing;
    }

    /**
     * @return array{
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
     * }
     */
    public static function resolve(string $provider): array
    {
        $slug = self::normalizeSlug($provider);
        if (!self::isSupported($slug)) {
            throw new InvalidArgumentException('Unsupported integration provider: ' . $slug);
        }

        $config = self::loadMergedConfig($slug);
        $config['oauth_ready'] = self::isOAuthReady($slug);

        if (!$config['oauth_ready']) {
            throw new IntegrationException(
                'oauth_not_ready',
                'OAuth DrDr هنوز فعال نشده. client_id و client_secret را در php/config/providers/drdr.php قرار دهید.'
            );
        }

        return $config;
    }

    /**
     * @return array{
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
     * }
     */
    public static function resolveWithDefaults(string $provider): array
    {
        $slug = self::normalizeSlug($provider);
        if (!self::isSupported($slug)) {
            throw new InvalidArgumentException('Unsupported integration provider: ' . $slug);
        }

        $config = self::loadMergedConfig($slug);
        $config['oauth_ready'] = self::isOAuthReady($slug);

        return $config;
    }

    /**
     * @return array<string, mixed>
     */
    private static function loadProviderFile(string $slug): array
    {
        $path = __DIR__ . '/../config/providers/' . $slug . '.php';
        if (!is_file($path)) {
            return [];
        }

        $data = require $path;

        return is_array($data) ? $data : [];
    }

    /**
     * @return array{
     *   slug: string,
     *   client_id: string,
     *   client_secret: string,
     *   auth_url: string,
     *   token_url: string,
     *   redirect_uri: string,
     *   login_url: string,
     *   scope: string,
     *   extra_auth_params: array<string, string>
     * }
     */
    private static function loadMergedConfig(string $slug): array
    {
        $file = self::loadProviderFile($slug);
        $prefix = 'INTEGRATION_' . strtoupper(str_replace('-', '_', $slug)) . '_';

        $extraParams = $file['extra_auth_params'] ?? [];
        if (!is_array($extraParams)) {
            $extraParams = [];
        }

        $extraRaw = trim((string) Config::get($prefix . 'EXTRA_AUTH_PARAMS', ''));
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
            'client_id' => self::pickString($prefix . 'CLIENT_ID', $file['client_id'] ?? ''),
            'client_secret' => self::pickString($prefix . 'CLIENT_SECRET', $file['client_secret'] ?? ''),
            'auth_url' => self::pickString($prefix . 'AUTH_URL', $file['auth_url'] ?? ''),
            'token_url' => self::pickString($prefix . 'TOKEN_URL', $file['token_url'] ?? ''),
            'redirect_uri' => self::callbackUrl($slug),
            'login_url' => self::pickString($prefix . 'LOGIN_URL', $file['login_url'] ?? 'https://panel.drdr.ir/'),
            'scope' => self::pickString($prefix . 'SCOPE', $file['scope'] ?? ''),
            'extra_auth_params' => $extraParams,
        ];
    }

    private static function pickString(string $envKey, mixed $fileValue): string
    {
        $fromEnv = Config::get($envKey);
        if (is_string($fromEnv) && trim($fromEnv) !== '') {
            return trim($fromEnv);
        }

        return is_string($fileValue) ? trim($fileValue) : '';
    }
}
