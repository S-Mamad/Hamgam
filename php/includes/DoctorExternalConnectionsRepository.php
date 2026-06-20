<?php

declare(strict_types=1);

final class DoctorExternalConnectionsRepository
{
    public static function upsert(
        string $doctorId,
        string $provider,
        string $accessToken,
        ?string $refreshToken,
        ?int $expiresAt
    ): void {
        $doctorId = GoogleTokensRepository::normalizeUserId($doctorId);
        $provider = IntegrationProviderConfig::normalizeSlug($provider);

        $encryptedAccess = TokenEncryption::encrypt($accessToken);
        $encryptedRefresh = $refreshToken !== null && $refreshToken !== ''
            ? TokenEncryption::encrypt($refreshToken)
            : null;

        $pdo = Database::connection();
        $existing = self::findRow($doctorId, $provider);

        if ($existing !== null) {
            $stmt = $pdo->prepare(
                'UPDATE doctor_external_connections
                 SET access_token = :access_token,
                     refresh_token = :refresh_token,
                     expires_at = :expires_at,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE doctor_id = :doctor_id AND provider = :provider'
            );
            $stmt->execute([
                'access_token' => $encryptedAccess,
                'refresh_token' => $encryptedRefresh,
                'expires_at' => $expiresAt,
                'doctor_id' => $doctorId,
                'provider' => $provider,
            ]);

            return;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO doctor_external_connections (
                doctor_id, provider, access_token, refresh_token, expires_at
             ) VALUES (
                :doctor_id, :provider, :access_token, :refresh_token, :expires_at
             )'
        );
        $stmt->execute([
            'doctor_id' => $doctorId,
            'provider' => $provider,
            'access_token' => $encryptedAccess,
            'refresh_token' => $encryptedRefresh,
            'expires_at' => $expiresAt,
        ]);
    }

    public static function delete(string $doctorId, string $provider): bool
    {
        $doctorId = GoogleTokensRepository::normalizeUserId($doctorId);
        $provider = IntegrationProviderConfig::normalizeSlug($provider);

        $stmt = Database::connection()->prepare(
            'DELETE FROM doctor_external_connections
             WHERE doctor_id = :doctor_id AND provider = :provider'
        );
        $stmt->execute([
            'doctor_id' => $doctorId,
            'provider' => $provider,
        ]);

        return $stmt->rowCount() > 0;
    }

    public static function isConnected(string $doctorId, string $provider): bool
    {
        return self::findRow($doctorId, $provider) !== null;
    }

    /**
     * @return array{expires_at: ?int, has_refresh_token: bool}|null
     */
    public static function getPublicStatus(string $doctorId, string $provider): ?array
    {
        $row = self::findRow($doctorId, $provider);
        if ($row === null) {
            return null;
        }

        $refresh = $row['refresh_token'] ?? null;

        return [
            'expires_at' => self::parseExpiresAt($row['expires_at'] ?? null),
            'has_refresh_token' => is_string($refresh) && trim($refresh) !== '',
        ];
    }

    /**
     * @return array{
     *   access_token: string,
     *   refresh_token: ?string,
     *   expires_at: ?int
     * }|null
     */
    public static function getDecryptedTokens(string $doctorId, string $provider): ?array
    {
        $row = self::findRow($doctorId, $provider);
        if ($row === null) {
            return null;
        }

        $accessEncrypted = (string) ($row['access_token'] ?? '');
        if ($accessEncrypted === '') {
            return null;
        }

        $refreshEncrypted = $row['refresh_token'] ?? null;
        $refreshToken = null;
        if (is_string($refreshEncrypted) && trim($refreshEncrypted) !== '') {
            $refreshToken = TokenEncryption::decrypt($refreshEncrypted);
        }

        return [
            'access_token' => TokenEncryption::decrypt($accessEncrypted),
            'refresh_token' => $refreshToken,
            'expires_at' => self::parseExpiresAt($row['expires_at'] ?? null),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function findRow(string $doctorId, string $provider): ?array
    {
        $doctorId = GoogleTokensRepository::normalizeUserId($doctorId);
        $provider = IntegrationProviderConfig::normalizeSlug($provider);

        $stmt = Database::connection()->prepare(
            'SELECT * FROM doctor_external_connections
             WHERE doctor_id = :doctor_id AND provider = :provider
             LIMIT 1'
        );
        $stmt->execute([
            'doctor_id' => $doctorId,
            'provider' => $provider,
        ]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    private static function parseExpiresAt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        if (is_string($value)) {
            $timestamp = strtotime($value);

            return $timestamp === false ? null : $timestamp;
        }

        return null;
    }
}
