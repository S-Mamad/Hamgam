<?php

declare(strict_types=1);

/**
 * GET /integrations/:provider/connect
 *
 * Validates Paziresh24 doctor session, builds OAuth2 authorization URL, redirects to provider.
 * Optional: ?format=json returns { oauth_url } instead of redirect (Authorization header required).
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/TokenEncryption.php';
require_once __DIR__ . '/../includes/OAuthStateSigner.php';
require_once __DIR__ . '/../includes/IntegrationProviderConfig.php';
require_once __DIR__ . '/../includes/DoctorExternalConnectionsRepository.php';
require_once __DIR__ . '/../includes/IntegrationRateLimiter.php';
require_once __DIR__ . '/../includes/ProviderIntegrationService.php';
require_once __DIR__ . '/../includes/IntegrationAuth.php';

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
    $returnTo = $_GET['return_to'] ?? 'settings';
    if (!is_string($returnTo) || !in_array($returnTo, ['settings', 'launcher'], true)) {
        $returnTo = 'settings';
    }

    $oauthUrl = ProviderIntegrationService::connect($provider, $doctorId, $returnTo);
    ProviderIntegrationService::logIntegrationEvent($doctorId, $provider, true, 'connect_started');

    $format = strtolower(trim((string) ($_GET['format'] ?? '')));
    if ($format === 'json') {
        Response::json([
            'ok' => true,
            'provider' => $provider,
            'oauth_url' => $oauthUrl,
        ]);
    }

    Response::redirectFast($oauthUrl);
} catch (IntegrationException $e) {
    ProviderIntegrationService::logIntegrationEvent(
        'unknown',
        is_string($_GET['provider'] ?? null) ? (string) $_GET['provider'] : 'unknown',
        false,
        $e->reasonCode()
    );
    Response::jsonError($e->getMessage(), $e->reasonCode() === 'auth_required' || $e->reasonCode() === 'auth_failed' ? 401 : 400);
} catch (Throwable $e) {
    RequestContext::log('integrations/connect', $e->getMessage());
    Response::jsonError('Internal server error', 500);
}
