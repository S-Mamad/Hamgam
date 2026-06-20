<?php

declare(strict_types=1);

final class DrDrAuthService
{
    public static function normalizeMobile(mixed $raw): ?string
    {
        if (!is_string($raw)) {
            return null;
        }

        $mobile = trim($raw);
        $mobile = str_replace([' ', '-', '(', ')'], '', $mobile);

        if (str_starts_with($mobile, '+98')) {
            $mobile = '0' . substr($mobile, 3);
        } elseif (str_starts_with($mobile, '98') && strlen($mobile) === 12) {
            $mobile = '0' . substr($mobile, 2);
        }

        if (!preg_match('/^09\d{9}$/', $mobile)) {
            return null;
        }

        return $mobile;
    }

    public static function normalizeOtpCode(mixed $raw): ?string
    {
        if (!is_string($raw) && !is_numeric($raw)) {
            return null;
        }

        $code = trim((string) $raw);
        $persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
        $arabic = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
        $ascii = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
        $code = str_replace($persian, $ascii, $code);
        $code = str_replace($arabic, $ascii, $code);
        $code = preg_replace('/\s+/', '', $code) ?? '';

        if (!preg_match('/^\d{4,8}$/', $code)) {
            return null;
        }

        return $code;
    }

    /**
     * @return array{ok: true, retry_after: int}
     */
    public static function sendOtp(string $doctorId, string $mobile): array
    {
        $doctorId = GoogleTokensRepository::normalizeUserId($doctorId);
        $config = self::apiConfig();

        if (!IntegrationRateLimiter::allow('drdr_send_otp_' . $doctorId, 5)) {
            throw new IntegrationException('rate_limited', 'تعداد درخواست کد زیاد است. چند دقیقه بعد دوباره تلاش کنید.');
        }

        DrDrPendingLoginRepository::clear($doctorId);

        $response = HttpClient::request(
            'POST',
            $config['send_otp_url'],
            self::apiHeaders($config['api_client_id']),
            ['mobile' => $mobile],
            'json',
            DrDrPendingLoginRepository::cookiePathForDoctor($doctorId)
        );

        if ($response['status'] < 200 || $response['status'] >= 300) {
            ProviderIntegrationService::logIntegrationEvent($doctorId, 'drdr', false, 'send_otp_rejected');
            throw new IntegrationException('send_otp_failed', self::humanizeDrDrError($response['body'], 'ارسال کد تأیید DrDr ناموفق بود.'));
        }

        DrDrPendingLoginRepository::save($doctorId, $mobile, is_array($response['body']) ? $response['body'] : null);
        ProviderIntegrationService::logIntegrationEvent($doctorId, 'drdr', true, 'send_otp_success');

        return [
            'ok' => true,
            'retry_after' => 60,
        ];
    }

    /**
     * @return array{ok: true, connected: true}
     */
    public static function verifyOtpAndStore(string $doctorId, string $mobile, string $code): array
    {
        $doctorId = GoogleTokensRepository::normalizeUserId($doctorId);
        $code = self::normalizeOtpCode($code);
        if ($code === null) {
            throw new IntegrationException('invalid_code', 'کد تأیید نامعتبر است.');
        }

        $pending = DrDrPendingLoginRepository::get($doctorId, $mobile);
        if ($pending === null) {
            throw new IntegrationException('otp_expired', 'کد منقضی شده. دوباره «ارسال کد» را بزنید.');
        }

        $config = self::apiConfig();
        $response = self::requestVerify($config, $doctorId, $mobile, $code);

        if ($response['status'] < 200 || $response['status'] >= 300) {
            ProviderIntegrationService::logIntegrationEvent($doctorId, 'drdr', false, 'verify_otp_rejected');
            throw new IntegrationException('verify_otp_failed', self::humanizeDrDrError($response['body'], 'کد تأیید DrDr نامعتبر است یا منقضی شده.'));
        }

        $accessToken = self::extractAccessToken($response['body']);
        if ($accessToken === null) {
            ProviderIntegrationService::logIntegrationEvent($doctorId, 'drdr', false, 'verify_no_token');
            throw new IntegrationException('verify_otp_failed', 'DrDr توکن دسترسی برنگرداند. لطفاً دوباره «ارسال کد» بزنید و کد جدید را وارد کنید.');
        }

        $refreshToken = self::extractRefreshToken($response['body']);
        $expiresAt = self::extractExpiresAt($response['body']);

        DoctorExternalConnectionsRepository::upsert(
            doctorId: $doctorId,
            provider: 'drdr',
            accessToken: $accessToken,
            refreshToken: $refreshToken,
            expiresAt: $expiresAt
        );

        DrDrPendingLoginRepository::clear($doctorId);
        ProviderIntegrationService::logIntegrationEvent($doctorId, 'drdr', true, 'verify_otp_success');

        return [
            'ok' => true,
            'connected' => true,
        ];
    }

    /**
     * @return array{
     *   api_client_id: string,
     *   api_client_secret: string,
     *   send_otp_url: string,
     *   oauth_token_url: string,
     *   oauth_scope: string
     * }
     */
    private static function apiConfig(): array
    {
        $path = __DIR__ . '/../config/providers/drdr.php';
        $file = is_file($path) ? require $path : [];
        if (!is_array($file)) {
            $file = [];
        }

        return [
            'api_client_id' => trim((string) ($file['api_client_id'] ?? 'f60d5037-b7ac-404a-9e3a-a263fd9f8054')),
            'api_client_secret' => trim((string) ($file['api_client_secret'] ?? 'vZHXE1Wx15g0RMVacaTN4KbKJ1mCk3jjkx3ZoKDj')),
            'send_otp_url' => trim((string) ($file['send_otp_url'] ?? 'https://drdr.ir/api/v3/auth/login/mobile/init')),
            'oauth_token_url' => trim((string) ($file['oauth_token_url'] ?? 'https://drdr.ir/api/v3/oauth/token/')),
            'oauth_scope' => trim((string) ($file['oauth_scope'] ?? '*')),
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function apiHeaders(string $clientId): array
    {
        return [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'client-id' => $clientId,
            'Origin' => 'https://drdr.ir',
            'Referer' => 'https://drdr.ir/login/?f=true',
        ];
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private static function humanizeDrDrError(?array $body, string $fallback): string
    {
        if (!is_array($body)) {
            return $fallback;
        }

        foreach (['message', 'error', 'detail'] as $key) {
            $value = $body[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return self::translateDrDrError(trim($value));
            }
        }

        if (isset($body['meta']['message']['body']) && is_string($body['meta']['message']['body'])) {
            return self::translateDrDrError(trim($body['meta']['message']['body']));
        }

        if (isset($body['data']) && is_array($body['data'])) {
            return self::humanizeDrDrError($body['data'], $fallback);
        }

        return $fallback;
    }

    private static function translateDrDrError(string $message): string
    {
        $normalized = strtolower($message);

        if (str_contains($normalized, 'user credentials were incorrect')) {
            return 'کد تأیید DrDr اشتباه است.';
        }

        if (str_contains($normalized, 'too many attempts')) {
            return 'تعداد تلاش زیاد است. چند دقیقه بعد دوباره امتحان کنید.';
        }

        return $message;
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private static function extractAccessToken(?array $body): ?string
    {
        if (!is_array($body)) {
            return null;
        }

        foreach (['access_token', 'accessToken', 'token'] as $key) {
            $value = $body[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        if (isset($body['payload']) && is_array($body['payload'])) {
            return self::extractAccessToken($body['payload']);
        }

        if (isset($body['data']) && is_array($body['data'])) {
            return self::extractAccessToken($body['data']);
        }

        return null;
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private static function extractRefreshToken(?array $body): ?string
    {
        if (!is_array($body)) {
            return null;
        }

        foreach (['refresh_token', 'refreshToken'] as $key) {
            $value = $body[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        if (isset($body['payload']) && is_array($body['payload'])) {
            return self::extractRefreshToken($body['payload']);
        }

        if (isset($body['data']) && is_array($body['data'])) {
            return self::extractRefreshToken($body['data']);
        }

        return null;
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private static function extractExpiresAt(?array $body): ?int
    {
        if (!is_array($body)) {
            return null;
        }

        $expiresIn = $body['expires_in'] ?? $body['expiresIn'] ?? null;
        if (is_numeric($expiresIn)) {
            return time() + max(0, (int) $expiresIn);
        }

        if (isset($body['payload']) && is_array($body['payload'])) {
            return self::extractExpiresAt($body['payload']);
        }

        if (isset($body['data']) && is_array($body['data'])) {
            return self::extractExpiresAt($body['data']);
        }

        return null;
    }

    /**
     * @param array{
     *   api_client_id: string,
     *   api_client_secret: string,
     *   oauth_token_url: string,
     *   oauth_scope: string
     * } $config
     * @return array{status: int, body: ?array<string, mixed>, raw: string}
     */
    private static function requestVerify(array $config, string $doctorId, string $mobile, string $code): array
    {
        $payload = [
            'grant_type' => 'otp',
            'client_id' => $config['api_client_id'],
            'client_secret' => $config['api_client_secret'],
            'mobile' => $mobile,
            'otp_code' => $code,
            'scope' => $config['oauth_scope'] !== '' ? $config['oauth_scope'] : '*',
        ];

        return HttpClient::request(
            'POST',
            $config['oauth_token_url'],
            self::apiHeaders($config['api_client_id']),
            $payload,
            'json',
            DrDrPendingLoginRepository::cookiePathForDoctor($doctorId)
        );
    }
}
