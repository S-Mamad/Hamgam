<?php

declare(strict_types=1);

final class IntegrationAuth
{
    public static function resolveAccessTokenFromRequest(): string
    {
        $token = Request::accessToken();
        if ($token !== '') {
            return $token;
        }

        $queryToken = $_GET['access_token'] ?? '';
        if (is_string($queryToken) && trim($queryToken) !== '') {
            $normalized = trim($queryToken);
            if (Request::isValidJwtPublic($normalized)) {
                return $normalized;
            }
        }

        return '';
    }

    public static function requireDoctorId(): string
    {
        $accessToken = self::resolveAccessTokenFromRequest();
        if ($accessToken === '') {
            throw new IntegrationException('auth_required', 'Valid Paziresh24 access token is required');
        }

        $doctorId = ProviderIntegrationService::resolveDoctorIdFromAccessToken($accessToken);
        if ($doctorId === null) {
            throw new IntegrationException('auth_failed', 'Unable to resolve doctor from access token');
        }

        return $doctorId;
    }

    public static function resolveProviderFromRequest(): string
    {
        $provider = $_GET['provider'] ?? '';
        if (!is_string($provider) || trim($provider) === '') {
            throw new IntegrationException('invalid_provider', 'Provider is required');
        }

        return IntegrationProviderConfig::normalizeSlug($provider);
    }
}
