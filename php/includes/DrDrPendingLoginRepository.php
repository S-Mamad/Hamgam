<?php

declare(strict_types=1);

final class DrDrPendingLoginRepository
{
    private const TTL_SECONDS = 300;
    private const RESEND_COOLDOWN_SECONDS = 60;
    private const DUPLICATE_SEND_GUARD_SECONDS = 15;

    public static function save(string $doctorId, string $mobile, ?array $initPayload): void
    {
        $doctorId = GoogleTokensRepository::normalizeUserId($doctorId);
        $path = self::pathForDoctor($doctorId);
        $now = time();

        $data = [
            'mobile' => $mobile,
            'init_payload' => $initPayload,
            'sent_at' => $now,
            'expires_at' => $now + self::TTL_SECONDS,
        ];

        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }

        file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    /**
     * @return array{retry_after: int, mobile: string}|null
     */
    public static function recentSendForMobile(string $doctorId, string $mobile): ?array
    {
        $doctorId = GoogleTokensRepository::normalizeUserId($doctorId);
        $path = self::pathForDoctor($doctorId);

        if (!is_file($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }

        $storedMobile = (string) ($decoded['mobile'] ?? '');
        if ($storedMobile !== $mobile) {
            return null;
        }

        $sentAt = (int) ($decoded['sent_at'] ?? 0);
        $expiresAt = (int) ($decoded['expires_at'] ?? 0);
        if ($sentAt <= 0 || $expiresAt <= time()) {
            return null;
        }

        $elapsed = time() - $sentAt;
        if ($elapsed >= self::DUPLICATE_SEND_GUARD_SECONDS) {
            return null;
        }

        return [
            'mobile' => $storedMobile,
            'retry_after' => max(1, self::RESEND_COOLDOWN_SECONDS - $elapsed),
        ];
    }

    public static function resendCooldownRemaining(string $doctorId, string $mobile): int
    {
        $doctorId = GoogleTokensRepository::normalizeUserId($doctorId);
        $path = self::pathForDoctor($doctorId);

        if (!is_file($path)) {
            return 0;
        }

        $raw = file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            return 0;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return 0;
        }

        if ((string) ($decoded['mobile'] ?? '') !== $mobile) {
            return 0;
        }

        $sentAt = (int) ($decoded['sent_at'] ?? 0);
        if ($sentAt <= 0) {
            return 0;
        }

        return max(0, self::RESEND_COOLDOWN_SECONDS - (time() - $sentAt));
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
