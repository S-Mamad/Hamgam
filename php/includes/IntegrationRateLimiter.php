<?php

declare(strict_types=1);

/**
 * Simple file-based rate limiter for OAuth callback endpoint.
 */
final class IntegrationRateLimiter
{
    private const DEFAULT_MAX_ATTEMPTS = 30;
    private const WINDOW_SECONDS = 60;

    public static function allow(string $bucket, ?int $maxAttempts = null): bool
    {
        $maxAttempts ??= max(1, (int) (Config::get('INTEGRATION_CALLBACK_RATE_LIMIT', (string) self::DEFAULT_MAX_ATTEMPTS) ?? (string) self::DEFAULT_MAX_ATTEMPTS));
        $now = time();
        $path = self::storagePath($bucket);
        $attempts = self::readAttempts($path, $now);

        if (count($attempts) >= $maxAttempts) {
            return false;
        }

        $attempts[] = $now;
        self::writeAttempts($path, $attempts);

        return true;
    }

    /**
     * @return array<int, int>
     */
    private static function readAttempts(string $path, int $now): array
    {
        if (!is_file($path)) {
            return [];
        }

        $raw = file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $cutoff = $now - self::WINDOW_SECONDS;
        $attempts = [];

        foreach ($decoded as $timestamp) {
            if (is_int($timestamp) && $timestamp >= $cutoff) {
                $attempts[] = $timestamp;
            } elseif (is_numeric($timestamp) && (int) $timestamp >= $cutoff) {
                $attempts[] = (int) $timestamp;
            }
        }

        return $attempts;
    }

    /**
     * @param array<int, int> $attempts
     */
    private static function writeAttempts(string $path, array $attempts): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }

        file_put_contents($path, json_encode(array_values($attempts), JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    private static function storagePath(string $bucket): string
    {
        $safeBucket = preg_replace('/[^a-zA-Z0-9._-]/', '_', $bucket) ?? 'default';

        return __DIR__ . '/../storage/rate_limits/integration_' . $safeBucket . '.json';
    }
}
