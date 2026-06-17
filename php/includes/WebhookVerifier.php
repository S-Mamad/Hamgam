<?php

declare(strict_types=1);

final class WebhookVerifier
{
    private const TIMESTAMP_TOLERANCE_SECONDS = 300;

    public static function verifySvix(string $rawBody): bool
    {
        $secret = Config::get('PAZIRESH24_WEBHOOK_SECRET', '');
        if ($secret === null || trim($secret) === '') {
            return true;
        }

        $msgId = self::readHeader('svix-id') ?? self::readHeader('webhook-id');
        $timestamp = self::readHeader('svix-timestamp') ?? self::readHeader('webhook-timestamp');
        $signature = self::readHeader('svix-signature') ?? self::readHeader('webhook-signature');

        if ($msgId === null || $timestamp === null || $signature === null) {
            error_log(
                '[WebhookVerifier] missing Svix headers id='
                . ($msgId === null ? '0' : '1')
                . ' ts=' . ($timestamp === null ? '0' : '1')
                . ' sig=' . ($signature === null ? '0' : '1')
            );
            return false;
        }

        if (!ctype_digit($timestamp)) {
            error_log('[WebhookVerifier] invalid Svix timestamp');
            return false;
        }

        $timestampInt = (int) $timestamp;
        if (abs(time() - $timestampInt) > self::TIMESTAMP_TOLERANCE_SECONDS) {
            error_log('[WebhookVerifier] Svix timestamp outside tolerance');
            return false;
        }

        $secretKey = self::decodeSigningSecret(trim($secret));
        if ($secretKey === null) {
            error_log('[WebhookVerifier] invalid PAZIRESH24_WEBHOOK_SECRET format');
            return false;
        }

        $signedContent = $msgId . '.' . $timestamp . '.' . $rawBody;
        $expectedSignature = base64_encode(hash_hmac('sha256', $signedContent, $secretKey, true));

        foreach (preg_split('/\s+/', trim($signature)) ?: [] as $versionedSignature) {
            $providedSignature = self::extractProvidedSignature($versionedSignature);
            if ($providedSignature !== '' && hash_equals($expectedSignature, $providedSignature)) {
                return true;
            }
        }

        error_log('[WebhookVerifier] Svix signature mismatch');
        return false;
    }

    private static function readHeader(string $name): ?string
    {
        $normalized = strtoupper(str_replace('-', '_', $name));
        foreach (['HTTP_' . $normalized, 'REDIRECT_HTTP_' . $normalized] as $serverKey) {
            if (isset($_SERVER[$serverKey]) && is_string($_SERVER[$serverKey]) && $_SERVER[$serverKey] !== '') {
                return $_SERVER[$serverKey];
            }
        }

        if (!function_exists('getallheaders')) {
            return null;
        }

        $headers = getallheaders();
        if (!is_array($headers)) {
            return null;
        }

        foreach ($headers as $key => $value) {
            if (strcasecmp((string) $key, $name) === 0 && is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private static function decodeSigningSecret(string $secret): ?string
    {
        $secret = trim($secret, " \t\n\r\0\x0B'\"");

        if (str_starts_with($secret, 'whsec_')) {
            $encoded = substr($secret, 6);
            if ($encoded === '') {
                return null;
            }

            $decoded = base64_decode($encoded, true);

            return $decoded === false ? null : $decoded;
        }

        $decoded = base64_decode($secret, true);
        if ($decoded !== false && strlen($decoded) >= 16) {
            return $decoded;
        }

        return null;
    }

    private static function extractProvidedSignature(string $versionedSignature): string
    {
        if (str_starts_with($versionedSignature, 'v1,')) {
            return substr($versionedSignature, 3);
        }

        if (str_starts_with($versionedSignature, 'v1=')) {
            return substr($versionedSignature, 3);
        }

        return $versionedSignature;
    }
}
