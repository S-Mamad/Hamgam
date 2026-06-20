<?php

declare(strict_types=1);

final class DrDrPendingLoginRepository
{
    private const TTL_SECONDS = 300;

    public static function save(string $doctorId, string $mobile, ?array $initPayload): void
    {
        $doctorId = GoogleTokensRepository::normalizeUserId($doctorId);
        $path = self::pathForDoctor($doctorId);

        $data = [
            'mobile' => $mobile,
            'init_payload' => $initPayload,
            'expires_at' => time() + self::TTL_SECONDS,
        ];

        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }

        file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    /**
     * @return array{mobile: string, init_payload: ?array}|null
     */
    public static function get(string $doctorId, string $mobile): ?array
    {
        return self::read($doctorId, $mobile, false);
    }

    /**
     * @return array{mobile: string, init_payload: ?array}|null
     */
    public static function consume(string $doctorId, string $mobile): ?array
    {
        return self::read($doctorId, $mobile, true);
    }

    /**
     * @return array{mobile: string, init_payload: ?array}|null
     */
    private static function read(string $doctorId, string $mobile, bool $deleteAfterRead): ?array
    {
        $doctorId = GoogleTokensRepository::normalizeUserId($doctorId);
        $path = self::pathForDoctor($doctorId);

        if (!is_file($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        if ($deleteAfterRead) {
            @unlink($path);
        }

        if ($raw === false || trim($raw) === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }

        $expiresAt = (int) ($decoded['expires_at'] ?? 0);
        if ($expiresAt <= time()) {
            if ($deleteAfterRead) {
                return null;
            }
            @unlink($path);

            return null;
        }

        $storedMobile = (string) ($decoded['mobile'] ?? '');
        if ($storedMobile !== $mobile) {
            return null;
        }

        $initPayload = $decoded['init_payload'] ?? null;

        return [
            'mobile' => $storedMobile,
            'init_payload' => is_array($initPayload) ? $initPayload : null,
        ];
    }

    public static function clear(string $doctorId): void
    {
        $doctorId = GoogleTokensRepository::normalizeUserId($doctorId);
        $path = self::pathForDoctor($doctorId);
        if (is_file($path)) {
            @unlink($path);
        }

        $cookiePath = self::cookiePathForDoctor($doctorId);
        if (is_file($cookiePath)) {
            @unlink($cookiePath);
        }
    }

    public static function cookiePathForDoctor(string $doctorId): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9._-]/', '_', GoogleTokensRepository::normalizeUserId($doctorId)) ?? 'unknown';

        return __DIR__ . '/../storage/drdr_cookies/' . $safe . '.txt';
    }

    private static function pathForDoctor(string $doctorId): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9._-]/', '_', $doctorId) ?? 'unknown';

        return __DIR__ . '/../storage/drdr_pending_login/' . $safe . '.json';
    }
}
