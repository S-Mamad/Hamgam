<?php

declare(strict_types=1);

/**
 * Generates and persists integration crypto secrets without .env edits.
 */
final class IntegrationSecrets
{
    private const STORAGE_FILE = __DIR__ . '/../storage/integration.secrets.json';

    public static function encryptionKey(): string
    {
        $fromEnv = Config::get('TOKEN_ENCRYPTION_KEY');
        if (is_string($fromEnv) && trim($fromEnv) !== '') {
            return self::normalizeKey($fromEnv);
        }

        return self::readOrCreateSecret('token_encryption_key', static fn (): string => base64_encode(random_bytes(32)));
    }

    public static function oauthStateSecret(): string
    {
        $fromEnv = Config::get('INTEGRATION_OAUTH_STATE_SECRET');
        if (is_string($fromEnv) && trim($fromEnv) !== '') {
            return trim($fromEnv);
        }

        return self::readOrCreateSecret('oauth_state_secret', static fn (): string => bin2hex(random_bytes(32)));
    }

    private static function readOrCreateSecret(string $key, callable $generator): string
    {
        $data = self::readStorage();
        if (isset($data[$key]) && is_string($data[$key]) && trim($data[$key]) !== '') {
            return trim($data[$key]);
        }

        $value = (string) $generator();
        $data[$key] = $value;
        self::writeStorage($data);

        return $value;
    }

    /**
     * @return array<string, string>
     */
    private static function readStorage(): array
    {
        if (!is_file(self::STORAGE_FILE)) {
            return [];
        }

        $raw = file_get_contents(self::STORAGE_FILE);
        if ($raw === false || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, string> $data
     */
    private static function writeStorage(array $data): void
    {
        $dir = dirname(self::STORAGE_FILE);
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }

        file_put_contents(
            self::STORAGE_FILE,
            json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            LOCK_EX
        );
    }

    private static function normalizeKey(string $raw): string
    {
        $decoded = base64_decode($raw, true);
        if ($decoded !== false && strlen($decoded) === 32) {
            return $decoded;
        }

        if (strlen($raw) === 32) {
            return $raw;
        }

        throw new RuntimeException('TOKEN_ENCRYPTION_KEY must be 32 bytes (raw or base64-encoded)');
    }
}
