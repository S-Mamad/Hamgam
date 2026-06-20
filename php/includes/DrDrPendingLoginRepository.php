<?php

declare(strict_types=1);

final class DrDrPendingLoginRepository
{
    /** DrDr OTP is typically valid ~2–3 minutes; keep local session aligned. */
    private const TTL_SECONDS = 180;
    public const VERIFY_MAX_AGE_SECONDS = 180;
    private const RESEND_COOLDOWN_SECONDS = 60;
    private const DUPLICATE_SEND_GUARD_SECONDS = 15;

    public static function save(string $doctorId, string $mobile, ?array $initPayload): void
    {
        $doctorId = GoogleTokensRepository::normalizeUserId($doctorId);
        $now = time();
        $payloadJson = null;
        if ($initPayload !== null) {
            $encoded = json_encode($initPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $payloadJson = $encoded === false ? null : $encoded;
        }

        try {
            self::upsertRow($doctorId, $mobile, $payloadJson, $now);
        } catch (Throwable $e) {
            RequestContext::log('drdr_pending_db_save', $e->getMessage());
        }

        self::saveToFile($doctorId, $mobile, $initPayload, $now);
    }

    private static function upsertRow(string $doctorId, string $mobile, ?string $payloadJson, int $now): void
    {
        $pdo = Database::connection();
        $expiresAt = $now + self::TTL_SECONDS;
        $driver = Config::get('DB_DRIVER', 'sqlite');

        if ($driver === 'mysql') {
            $stmt = $pdo->prepare(
                'INSERT INTO drdr_pending_otp (doctor_id, mobile, init_payload, sent_at, expires_at)
                 VALUES (:doctor_id, :mobile, :init_payload, :sent_at, :expires_at)
                 ON DUPLICATE KEY UPDATE
                    mobile = VALUES(mobile),
                    init_payload = VALUES(init_payload),
                    sent_at = VALUES(sent_at),
                    expires_at = VALUES(expires_at)'
            );
            $stmt->execute([
                'doctor_id' => $doctorId,
                'mobile' => $mobile,
                'init_payload' => $payloadJson,
                'sent_at' => $now,
                'expires_at' => $expiresAt,
            ]);

            return;
        }

        $stmt = $pdo->prepare(
            'INSERT OR REPLACE INTO drdr_pending_otp (doctor_id, mobile, init_payload, sent_at, expires_at)
             VALUES (:doctor_id, :mobile, :init_payload, :sent_at, :expires_at)'
        );
        $stmt->execute([
            'doctor_id' => $doctorId,
            'mobile' => $mobile,
            'init_payload' => $payloadJson,
            'sent_at' => $now,
            'expires_at' => $expiresAt,
        ]);
    }

    /**
     * @return array{retry_after: int, mobile: string}|null
     */
    public static function recentSendForMobile(string $doctorId, string $mobile): ?array
    {
        $row = self::readRow($doctorId, $mobile, false);
        if ($row === null) {
            return null;
        }

        $elapsed = time() - $row['sent_at'];
        if ($elapsed >= self::DUPLICATE_SEND_GUARD_SECONDS) {
            return null;
        }

        return [
            'mobile' => $row['mobile'],
            'retry_after' => max(1, self::RESEND_COOLDOWN_SECONDS - $elapsed),
        ];
    }

    public static function resendCooldownRemaining(string $doctorId, string $mobile): int
    {
        $row = self::readRow($doctorId, $mobile, false);
        if ($row === null) {
            return 0;
        }

        return max(0, self::RESEND_COOLDOWN_SECONDS - (time() - $row['sent_at']));
    }

    /**
     * @return array{mobile: string, init_payload: ?array, sent_at: int}|null
     */
    public static function get(string $doctorId, string $mobile): ?array
    {
        return self::readRow($doctorId, $mobile, false);
    }

    /**
     * Latest non-expired OTP session for a doctor (ignores mobile filter).
     *
     * @return array{mobile: string, init_payload: ?array, sent_at: int}|null
     */
    public static function getActiveForDoctor(string $doctorId): ?array
    {
        return self::readRow($doctorId, null, false);
    }

    public static function clear(string $doctorId): void
    {
        $doctorId = GoogleTokensRepository::normalizeUserId($doctorId);

        try {
            $stmt = Database::connection()->prepare(
                'DELETE FROM drdr_pending_otp WHERE doctor_id = :doctor_id'
            );
            $stmt->execute(['doctor_id' => $doctorId]);
        } catch (Throwable) {
            // Best effort.
        }

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
        $dir = __DIR__ . '/../storage/drdr_cookies';
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }

        return $dir . '/' . $safe . '.txt';
    }

    /**
     * @return array{mobile: string, init_payload: ?array, sent_at: int}|null
     */
    private static function readRow(string $doctorId, ?string $mobile, bool $deleteAfterRead): ?array
    {
        $doctorId = GoogleTokensRepository::normalizeUserId($doctorId);

        try {
            $stmt = Database::connection()->prepare(
                'SELECT mobile, init_payload, sent_at, expires_at
                 FROM drdr_pending_otp
                 WHERE doctor_id = :doctor_id
                 LIMIT 1'
            );
            $stmt->execute(['doctor_id' => $doctorId]);
            $row = $stmt->fetch();
            if ($row !== false) {
                $parsed = self::parseRow($row, $mobile);
                if ($parsed !== null) {
                    if ($deleteAfterRead) {
                        self::clear($doctorId);
                    }

                    return $parsed;
                }

                if ($deleteAfterRead) {
                    self::clear($doctorId);
                }
            }
        } catch (Throwable) {
            // Fall back to file storage.
        }

        return self::readFromFile($doctorId, $mobile, $deleteAfterRead);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{mobile: string, init_payload: ?array, sent_at: int}|null
     */
    private static function parseRow(array $row, ?string $mobile): ?array
    {
        $expiresAt = (int) ($row['expires_at'] ?? 0);
        if ($expiresAt <= time()) {
            return null;
        }

        $storedMobile = (string) ($row['mobile'] ?? '');
        if ($mobile !== null && $storedMobile !== $mobile) {
            return null;
        }

        $initPayload = null;
        $rawPayload = $row['init_payload'] ?? null;
        if (is_string($rawPayload) && trim($rawPayload) !== '') {
            $decoded = json_decode($rawPayload, true);
            $initPayload = is_array($decoded) ? $decoded : null;
        }

        return [
            'mobile' => $storedMobile,
            'init_payload' => $initPayload,
            'sent_at' => (int) ($row['sent_at'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed>|null $initPayload
     */
    private static function saveToFile(string $doctorId, string $mobile, ?array $initPayload, int $now): void
    {
        $path = self::pathForDoctor($doctorId);
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }

        @file_put_contents(
            $path,
            json_encode([
                'mobile' => $mobile,
                'init_payload' => $initPayload,
                'sent_at' => $now,
                'expires_at' => $now + self::TTL_SECONDS,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }

    /**
     * @return array{mobile: string, init_payload: ?array, sent_at: int}|null
     */
    private static function readFromFile(string $doctorId, ?string $mobile, bool $deleteAfterRead): ?array
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
            return null;
        }

        $storedMobile = (string) ($decoded['mobile'] ?? '');
        if ($mobile !== null && $storedMobile !== $mobile) {
            return null;
        }

        $initPayload = $decoded['init_payload'] ?? null;

        return [
            'mobile' => $storedMobile,
            'init_payload' => is_array($initPayload) ? $initPayload : null,
            'sent_at' => (int) ($decoded['sent_at'] ?? 0),
        ];
    }

    private static function pathForDoctor(string $doctorId): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9._-]/', '_', $doctorId) ?? 'unknown';

        return __DIR__ . '/../storage/drdr_pending_login/' . $safe . '.json';
    }
}
