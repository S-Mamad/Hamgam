<?php

declare(strict_types=1);

/**
 * GET /integrations/:provider/callback
 *
 * OAuth2 callback: validates signed state, exchanges code for tokens, stores encrypted credentials.
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/HamgamRedirects.php';
require_once __DIR__ . '/../includes/IntegrationSecrets.php';
require_once __DIR__ . '/../includes/TokenEncryption.php';
require_once __DIR__ . '/../includes/OAuthStateSigner.php';
require_once __DIR__ . '/../includes/IntegrationProviderConfig.php';
require_once __DIR__ . '/../includes/DoctorExternalConnectionsRepository.php';
require_once __DIR__ . '/../includes/IntegrationRateLimiter.php';
require_once __DIR__ . '/../includes/ProviderIntegrationService.php';
require_once __DIR__ . '/../includes/IntegrationAuth.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    Response::jsonError('Method not allowed', 405);
}

$provider = is_string($_GET['provider'] ?? null) ? trim((string) $_GET['provider']) : 'unknown';
$clientIp = is_string($_SERVER['REMOTE_ADDR'] ?? null) ? (string) $_SERVER['REMOTE_ADDR'] : 'unknown';
$rateBucket = 'callback_' . $clientIp . '_' . $provider;

if (!IntegrationRateLimiter::allow($rateBucket)) {
    ProviderIntegrationService::logIntegrationEvent('unknown', $provider, false, 'rate_limited');
    redirectIntegrationFailure($provider, 'rate_limited');
}

try {
    $provider = IntegrationAuth::resolveProviderFromRequest();
    if (!IntegrationProviderConfig::isSupported($provider)) {
        ProviderIntegrationService::logIntegrationEvent('unknown', $provider, false, 'unsupported_provider');
        redirectIntegrationFailure($provider, 'unsupported_provider');
    }

    $providerError = $_GET['error'] ?? null;
    if (is_string($providerError) && trim($providerError) !== '') {
        ProviderIntegrationService::logIntegrationEvent('unknown', $provider, false, 'provider_denied');
        redirectIntegrationFailure($provider, 'provider_denied');
    }

    $code = $_GET['code'] ?? '';
    $state = $_GET['state'] ?? '';
    $code = is_string($code) ? trim($code) : '';
    $state = is_string($state) ? trim($state) : '';

    if ($code === '' || $state === '') {
        ProviderIntegrationService::logIntegrationEvent('unknown', $provider, false, 'missing_code_or_state');
        redirectIntegrationFailure($provider, 'missing_code');
    }

    $result = ProviderIntegrationService::handleCallback($provider, $code, $state);
    redirectIntegrationSuccess($result['return_to'], $result['provider']);
} catch (IntegrationException $e) {
    ProviderIntegrationService::logIntegrationEvent('unknown', $provider, false, $e->reasonCode());
    redirectIntegrationFailure($provider, $e->reasonCode());
} catch (Throwable $e) {
    RequestContext::log('integrations/callback', $e->getMessage());
    ProviderIntegrationService::logIntegrationEvent('unknown', $provider, false, 'internal_error');
    redirectIntegrationFailure($provider, 'internal_error');
}

/**
 * @param array{return_to: string, provider: string} $result
 */
function redirectIntegrationSuccess(string $returnTo, string $provider): never
{
    $query = [
        'integration' => 'success',
        'provider' => $provider,
    ];

    if ($returnTo === 'launcher') {
        Response::redirectViaLauncherBridge(
            HamgamRedirects::launcherAppOpenUrl(),
            $query,
            'hamgam_integration_success'
        );
    }

    $settingsUrl = rtrim(Config::require('REDIRECT_SETTINGS'), '/');
    Response::redirectFast($settingsUrl . '/?' . http_build_query($query));
}

function redirectIntegrationFailure(string $provider, string $reason): never
{
    try {
        $providerSlug = IntegrationProviderConfig::normalizeSlug($provider);
    } catch (InvalidArgumentException) {
        $providerSlug = 'unknown';
    }

    $query = [
        'integration' => 'error',
        'provider' => $providerSlug,
        'reason' => $reason,
    ];

    $settingsUrl = rtrim(Config::require('REDIRECT_SETTINGS'), '/');
    Response::redirectFast($settingsUrl . '/?' . http_build_query($query));
}
