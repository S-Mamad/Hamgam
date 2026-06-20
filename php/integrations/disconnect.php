<?php

declare(strict_types=1);

/**
 * POST /integrations/:provider/disconnect
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/TokenEncryption.php';
require_once __DIR__ . '/../includes/OAuthStateSigner.php';
require_once __DIR__ . '/../includes/IntegrationProviderConfig.php';
require_once __DIR__ . '/../includes/DoctorExternalConnectionsRepository.php';
require_once __DIR__ . '/../includes/ProviderIntegrationService.php';
require_once __DIR__ . '/../includes/IntegrationAuth.php';

Request::applyCors();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    Response::jsonError('Method not allowed', 405);
}

try {
    $provider = IntegrationAuth::resolveProviderFromRequest();
    if (!IntegrationProviderConfig::isSupported($provider)) {
        Response::jsonError('Unsupported provider', 404);
    }

    $doctorId = IntegrationAuth::requireDoctorId();
    $disconnected = ProviderIntegrationService::disconnect($doctorId, $provider);

    Response::json([
        'ok' => true,
        'provider' => $provider,
        'disconnected' => $disconnected,
    ]);
} catch (IntegrationException $e) {
    Response::jsonError(
        $e->getMessage(),
        $e->reasonCode() === 'auth_required' || $e->reasonCode() === 'auth_failed' ? 401 : 400
    );
} catch (Throwable $e) {
    RequestContext::log('integrations/disconnect', $e->getMessage());
    Response::jsonError('Internal server error', 500);
}
