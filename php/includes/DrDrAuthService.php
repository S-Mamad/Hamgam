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
        if (is_array($response['body'])) {
            ProviderIntegrationService::logIntegrationEvent(
                $doctorId,
                'drdr',
                true,
                'send_otp_keys:' . implode(',', self::collectPayloadKeys($response['body']))
            );
        }
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
        $response = self::requestVerify(
            $config,
            $doctorId,
            $mobile,
            $code,
            $pending['init_payload'] ?? null
        );

        if ($response['status'] < 200 || $response['status'] >= 300) {
            ProviderIntegrationService::logIntegrationEvent($doctorId, 'drdr', false, 'verify_otp_rejected');
            throw new IntegrationException('verify_otp_failed', self::humanizeDrDrError($response['body'], 'کد تأیید DrDr نامعتبر است یا منقضی شده.'));
        }

        $accessToken = self::extractAccessToken($response['body']);
        if ($accessToken === null) {
            ProviderIntegrationService::logIntegrationEvent($doctorId, 'drdr', false, 'verify_no_token');
            throw new IntegrationException('verify_otp_failed', 'DrDr توکن دسترسی برنگرداند. endpoint verify را بررسی کنید.');
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
     * @return array{api_client_id: string, send_otp_url: string, verify_otp_urls: list<string>}
     */
    private static function apiConfig(): array
    {
        $path = __DIR__ . '/../config/providers/drdr.php';
        $file = is_file($path) ? require $path : [];
        if (!is_array($file)) {
            $file = [];
        }

        $verifyUrls = $file['verify_otp_urls'] ?? [];
        if (!is_array($verifyUrls)) {
            $verifyUrls = [];
        }

        $primaryVerify = trim((string) ($file['verify_otp_url'] ?? $file['send_otp_url'] ?? 'https://drdr.ir/api/v3/auth/login/mobile/init'));
        $sendUrl = trim((string) ($file['send_otp_url'] ?? 'https://drdr.ir/api/v3/auth/login/mobile/init'));
        if ($sendUrl !== '') {
            array_unshift($verifyUrls, $sendUrl);
        }
        if ($primaryVerify !== '') {
            array_unshift($verifyUrls, $primaryVerify);
        }

        $verifyUrls = array_values(array_unique(array_filter(array_map(
            static fn ($url) => is_string($url) ? trim($url) : '',
            $verifyUrls
        ))));

        if ($verifyUrls === []) {
            $verifyUrls = ['https://drdr.ir/api/v3/auth/login/mobile/init'];
        }

        return [
            'api_client_id' => trim((string) ($file['api_client_id'] ?? 'f60d5037-b7ac-404a-9e3a-a263fd9f8054')),
            'send_otp_url' => trim((string) ($file['send_otp_url'] ?? 'https://drdr.ir/api/v3/auth/login/mobile/init')),
            'verify_otp_urls' => $verifyUrls,
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
                return trim($value);
            }
        }

        if (isset($body['data']) && is_array($body['data'])) {
            return self::humanizeDrDrError($body['data'], $fallback);
        }

        return $fallback;
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

        if (isset($body['data']) && is_array($body['data'])) {
            return self::extractExpiresAt($body['data']);
        }

        return null;
    }

    /**
     * @param array<string, mixed>|null $initPayload
     * @return array<string, mixed>
     */
    private static function buildVerifyPayload(string $mobile, string $code, ?array $initPayload): array
    {
        $payload = array_merge(
            ['mobile' => $mobile, 'code' => $code],
            self::extractInitSessionFields($initPayload)
        );

        if (!isset($payload['verificationCode'])) {
            $payload['verificationCode'] = $code;
        }

        return $payload;
    }

    /**
     * @param array<string, mixed>|null $initPayload
     * @return array<string, string>
     */
    private static function extractInitSessionFields(?array $initPayload): array
    {
        if (!is_array($initPayload)) {
            return [];
        }

        $skipKeys = [
            'mobile', 'message', 'messages', 'status', 'success', 'ok', 'error', 'errors', 'meta', 'code',
        ];
        $fields = [];

        foreach (self::flattenScalarFields($initPayload) as $key => $value) {
            if (in_array(strtolower($key), $skipKeys, true)) {
                continue;
            }
            if (stripos($key, 'message') !== false) {
                continue;
            }
            $fields[$key] = $value;
        }

        return $fields;
    }

    /**
     * @param array<string, mixed>|null $initPayload
     */
    private static function extractInitHeaderToken(?array $initPayload): ?string
    {
        if (!is_array($initPayload)) {
            return null;
        }

        foreach (['token', 'accessToken', 'access_token', 'verificationToken', 'verification_token', 'sessionToken', 'session_token'] as $key) {
            $value = self::findScalarField($initPayload, $key);
            if ($value !== null && strlen($value) > 8) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, string>
     */
    private static function flattenScalarFields(array $payload, string $prefix = ''): array
    {
        $fields = [];

        foreach ($payload as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            $path = $prefix === '' ? $key : $prefix . '.' . $key;

            if (is_string($value) || is_numeric($value)) {
                $stringValue = trim((string) $value);
                if ($stringValue !== '') {
                    $fields[$key] = $stringValue;
                    $fields[$path] = $stringValue;
                }
                continue;
            }

            if (is_array($value)) {
                $fields = array_merge($fields, self::flattenScalarFields($value, $path));
            }
        }

        return $fields;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function findScalarField(array $payload, string $targetKey): ?string
    {
        foreach ($payload as $key => $value) {
            if ($key === $targetKey && (is_string($value) || is_numeric($value))) {
                $stringValue = trim((string) $value);

                return $stringValue !== '' ? $stringValue : null;
            }

            if (is_array($value)) {
                $nested = self::findScalarField($value, $targetKey);
                if ($nested !== null) {
                    return $nested;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<string>
     */
    private static function collectPayloadKeys(array $payload, string $prefix = ''): array
    {
        $keys = [];
        foreach ($payload as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            $path = $prefix === '' ? $key : $prefix . '.' . $key;
            $keys[] = $path;
            if (is_array($value)) {
                $keys = array_merge($keys, self::collectPayloadKeys($value, $path));
            }
        }

        return $keys;
    }

    /**
     * @param array{api_client_id: string, send_otp_url: string, verify_otp_urls: list<string>} $config
     * @param array<string, mixed>|null $initPayload
     * @return array{status: int, body: ?array<string, mixed>, raw: string}
     */
    private static function requestVerify(
        array $config,
        string $doctorId,
        string $mobile,
        string $code,
        ?array $initPayload
    ): array {
        $payload = self::buildVerifyPayload($mobile, $code, $initPayload);
        $headers = self::apiHeaders($config['api_client_id']);
        $headerToken = self::extractInitHeaderToken($initPayload);
        if ($headerToken !== null) {
            $headers['Authorization'] = 'Bearer ' . $headerToken;
        }

        $cookieJar = DrDrPendingLoginRepository::cookiePathForDoctor($doctorId);
        $verifyUrl = $config['verify_otp_urls'][0] ?? $config['send_otp_url'];

        return HttpClient::request(
            'POST',
            $verifyUrl,
            $headers,
            $payload,
            'json',
            $cookieJar
        );
    }
}
