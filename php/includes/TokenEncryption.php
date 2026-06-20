<?php

declare(strict_types=1);

/**
 * AES-256-GCM encryption for OAuth tokens at rest.
 * Plaintext tokens must never be logged or returned in API responses.
 */
final class TokenEncryption
{
    private const CIPHER = 'aes-256-gcm';
    private const IV_LENGTH = 12;
    private const TAG_LENGTH = 16;

    public static function encrypt(string $plaintext): string
    {
        if ($plaintext === '') {
            return '';
        }

        $key = self::encryptionKey();
        $iv = random_bytes(self::IV_LENGTH);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LENGTH
        );

        if ($ciphertext === false || $tag === '') {
            throw new RuntimeException('Token encryption failed');
        }

        return base64_encode($iv . $tag . $ciphertext);
    }

    public static function decrypt(string $encrypted): string
    {
        if ($encrypted === '') {
            return '';
        }

        $raw = base64_decode($encrypted, true);
        if ($raw === false || strlen($raw) < self::IV_LENGTH + self::TAG_LENGTH + 1) {
            throw new RuntimeException('Invalid encrypted token payload');
        }

        $iv = substr($raw, 0, self::IV_LENGTH);
        $tag = substr($raw, self::IV_LENGTH, self::TAG_LENGTH);
        $ciphertext = substr($raw, self::IV_LENGTH + self::TAG_LENGTH);

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            self::encryptionKey(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($plaintext === false) {
            throw new RuntimeException('Token decryption failed');
        }

        return $plaintext;
    }

    private static function encryptionKey(): string
    {
        return IntegrationSecrets::encryptionKey();
    }
}
