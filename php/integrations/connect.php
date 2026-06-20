<?php

declare(strict_types=1);

/**
 * GET /integrations/:provider/connect
 *
 * One click → DrDr login (or OAuth authorize when credentials are set).
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

    $target = ProviderIntegrationService::buildConnectTarget($provider, $doctorId, $returnTo);
    ProviderIntegrationService::logIntegrationEvent(
        $doctorId,
        $provider,
        true,
        $target['oauth_ready'] ? 'connect_oauth_started' : 'connect_login_started'
    );

    $format = strtolower(trim((string) ($_GET['format'] ?? '')));
    if ($format === 'json') {
        Response::json([
            'ok' => true,
            'provider' => $provider,
            'oauth_ready' => $target['oauth_ready'],
            'mode' => $target['mode'],
            'oauth_url' => $target['url'],
        ]);
    }

    Response::redirectFast($target['url']);
} catch (IntegrationException $e) {
    ProviderIntegrationService::logIntegrationEvent(
        'unknown',
        is_string($_GET['provider'] ?? null) ? (string) $_GET['provider'] : 'unknown',
        false,
        $e->reasonCode()
    );
    $statusCode = match ($e->reasonCode()) {
        'auth_required', 'auth_failed' => 401,
        default => 400,
    };
    Response::jsonError($e->getMessage(), $statusCode);
} catch (Throwable $e) {
    RequestContext::log('integrations/connect', $e->getMessage());
    Response::jsonError('Internal server error', 500);
}
