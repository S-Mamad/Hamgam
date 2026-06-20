<?php

declare(strict_types=1);

/**
 * POST /integrations/:provider/complete-otp
 * Body: { access_token, drdr_access_token, drdr_refresh_token?, expires_in? }
 *
 * Stores DrDr tokens after browser-direct oauth/token exchange (same as drdr.ir login).
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/IntegrationSecrets.php';
require_once __DIR__ . '/../includes/TokenEncryption.php';
require_once __DIR__ . '/../includes/OAuthStateSigner.php';
require_once __DIR__ . '/../includes/IntegrationProviderConfig.php';
require_once __DIR__ . '/../includes/DoctorExternalConnectionsRepository.php';
require_once __DIR__ . '/../includes/IntegrationRateLimiter.php';
require_once __DIR__ . '/../includes/ProviderIntegrationService.php';
require_once __DIR__ . '/../includes/IntegrationAuth.php';
require_once __DIR__ . '/../includes/DrDrPendingLoginRepository.php';
require_once __DIR__ . '/../includes/DrDrAuthService.php';

Request::applyCors();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    Response::jsonError('Method not allowed', 405);
}

try {
    $provider = IntegrationAuth::resolveProviderFromRequest();
    if ($provider !== 'drdr') {
        Response::jsonError('OTP login is only supported for DrDr', 404);
    }

    $doctorId = IntegrationAuth::requireDoctorId();
    $body = Request::jsonBody();
    if (!is_array($body)) {
        throw new IntegrationException('invalid_body', 'Invalid JSON body');
    }

    $accessToken = trim((string) ($body['drdr_access_token'] ?? ''));
    $refreshToken = trim((string) ($body['drdr_refresh_token'] ?? $body['refresh_token'] ?? ''));
    $refreshToken = $refreshToken !== '' ? $refreshToken : null;
    $expiresAt = null;

    $drdrResponse = $body['drdr_response'] ?? $body['drdr_body'] ?? null;
    if ($accessToken === '' && is_array($drdrResponse)) {
        $parsed = DrDrAuthService::extractTokensFromOAuthBody($drdrResponse);
        $accessToken = $parsed['access_token'];
        $refreshToken = $parsed['refresh_token'] ?? $refreshToken;
        $expiresAt = $parsed['expires_at'] ?? null;
    }

    if ($accessToken === '') {
        throw new IntegrationException('invalid_token', 'توکن DrDr دریافت نشد.');
    }

    if ($expiresAt === null) {
        $expiresIn = $body['expires_in'] ?? $body['expiresIn'] ?? null;
        if (is_numeric($expiresIn)) {
            $expiresAt = time() + max(0, (int) $expiresIn);
        }
    }

    $result = DrDrAuthService::storeClientTokens($doctorId, $accessToken, $refreshToken, $expiresAt);
    $status = DoctorExternalConnectionsRepository::getPublicStatus($doctorId, $provider);

    Response::json([
        'ok' => true,
        'provider' => $provider,
        'connected' => $result['connected'] ?? true,
        'expires_at' => $status['expires_at'] ?? null,
        'has_refresh_token' => $status['has_refresh_token'] ?? false,
    ]);
} catch (IntegrationException $e) {
    Response::json([
        'ok' => false,
        'error' => $e->getMessage(),
        'reason' => $e->reasonCode(),
    ], match ($e->reasonCode()) {
        'auth_required', 'auth_failed' => 401,
        'rate_limited' => 429,
        default => 400,
    });
} catch (Throwable $e) {
    RequestContext::log('integrations/complete-otp', $e->getMessage());
    Response::jsonError('Internal server error', 500);
}
