<?php

declare(strict_types=1);

/**
 * GET /integrations/:provider/status
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/IntegrationSecrets.php';
require_once __DIR__ . '/../includes/TokenEncryption.php';
require_once __DIR__ . '/../includes/OAuthStateSigner.php';
require_once __DIR__ . '/../includes/IntegrationProviderConfig.php';
require_once __DIR__ . '/../includes/DoctorExternalConnectionsRepository.php';
require_once __DIR__ . '/../includes/ProviderIntegrationService.php';
require_once __DIR__ . '/../includes/IntegrationAuth.php';
require_once __DIR__ . '/../includes/DrDrPendingLoginRepository.php';
require_once __DIR__ . '/../includes/DrDrAuthService.php';

Request::applyCors();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    Response::jsonError('Method not allowed', 405);
}

try {
    $provider = IntegrationAuth::resolveProviderFromRequest();
    if (!IntegrationProviderConfig::isSupported($provider)) {
        Response::jsonError('Unsupported provider', 404);
    }

    $doctorId = IntegrationAuth::requireDoctorId();
    $oauthReady = IntegrationProviderConfig::isOAuthReady($provider);
    $status = DoctorExternalConnectionsRepository::getPublicStatus($doctorId, $provider);
    $otpPending = $provider === 'drdr' && $status === null
        ? DrDrPendingLoginRepository::getPublicPendingState($doctorId)
        : null;

    Response::json([
        'ok' => true,
        'provider' => $provider,
        'oauth_ready' => $oauthReady,
        'otp_login_ready' => $provider === 'drdr',
        'login_url' => IntegrationProviderConfig::loginUrl($provider),
        'connected' => $status !== null,
        'expires_at' => $status['expires_at'] ?? null,
        'has_refresh_token' => $status['has_refresh_token'] ?? false,
        'otp_pending' => $otpPending,
        'otp_client' => $provider === 'drdr' ? DrDrAuthService::publicOtpClientConfig() : null,
    ]);
} catch (IntegrationException $e) {
    Response::jsonError(
        $e->getMessage(),
        $e->reasonCode() === 'auth_required' || $e->reasonCode() === 'auth_failed' ? 401 : 400
    );
} catch (Throwable $e) {
    RequestContext::log('integrations/status', $e->getMessage());
    Response::jsonError('Internal server error', 500);
}
