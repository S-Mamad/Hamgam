<?php

declare(strict_types=1);

/**
 * HMAC-signed OAuth state to prevent CSRF and bind callback to doctor_id + provider.
 */
final class OAuthStateSigner
{
    private const MAX_AGE_SECONDS = 900;

    /**
     * @param array<string, mixed> $extra
     */
    public static function create(string $doctorId, string $provider, array $extra = []): string
    {
        $payload = array_merge($extra, [
            'doctor_id' => GoogleTokensRepository::normalizeUserId($doctorId),
            'provider' => IntegrationProviderConfig::normalizeSlug($provider),
            'nonce' => bin2hex(random_bytes(16)),
            'iat' => time(),
        ]);

        return self::encode($payload);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function verify(string $state, string $expectedProvider): ?array
    {
        $payload = self::decode($state);
        if ($payload === null) {
            return null;
        }

        $provider = IntegrationProviderConfig::normalizeSlug($expectedProvider);
        $stateProvider = (string) ($payload['provider'] ?? '');
        if ($stateProvider !== $provider) {
            return null;
        }

        $doctorId = (string) ($payload['doctor_id'] ?? '');
        if ($doctorId === '') {
            return null;
        }

        $issuedAt = (int) ($payload['iat'] ?? 0);
        if ($issuedAt <= 0 || (time() - $issuedAt) > self::MAX_AGE_SECONDS) {
            return null;
        }

        $payload['doctor_id'] = GoogleTokensRepository::normalizeUserId($doctorId);

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function encode(array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || $json === '') {
            throw new RuntimeException('Failed to encode OAuth state');
        }

        $payloadB64 = self::base64UrlEncode($json);
        $signature = hash_hmac('sha256', $payloadB64, self::secret(), true);

        return $payloadB64 . '.' . self::base64UrlEncode($signature);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function decode(string $state): ?array
    {
        $state = trim($state);
        if ($state === '' || !str_contains($state, '.')) {
            return null;
        }

        [$payloadB64, $signatureB64] = explode('.', $state, 2);
        if ($payloadB64 === '' || $signatureB64 === '') {
            return null;
        }

        $expectedSignature = hash_hmac('sha256', $payloadB64, self::secret(), true);
        $actualSignature = self::base64UrlDecode($signatureB64);
        if ($actualSignature === null || !hash_equals($expectedSignature, $actualSignature)) {
            return null;
        }

        $json = self::base64UrlDecode($payloadB64);
        if ($json === null) {
            return null;
        }

        $payload = json_decode($json, true);

        return is_array($payload) ? $payload : null;
    }

    private static function secret(): string
    {
        return Config::require('INTEGRATION_OAUTH_STATE_SECRET');
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): ?string
    {
        $remainder = strlen($data) % 4;
        if ($remainder > 0) {
            $data .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($data, '-_', '+/'), true);

        return is_string($decoded) ? $decoded : null;
    }
}
