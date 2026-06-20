<?php

declare(strict_types=1);

/**
 * POST /integrations/:provider/send-otp
 * Body: { access_token, mobile }
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

    $mobile = DrDrAuthService::normalizeMobile($body['mobile'] ?? '');
    if ($mobile === null) {
        throw new IntegrationException('invalid_mobile', 'شماره موبایل معتبر نیست. مثال: 09123456789');
    }

    $result = DrDrAuthService::sendOtp($doctorId, $mobile);

    Response::json([
        'ok' => true,
        'provider' => $provider,
        'mobile' => $mobile,
        'retry_after' => $result['retry_after'] ?? 60,
    ]);
} catch (IntegrationException $e) {
    Response::jsonError(
        $e->getMessage(),
        match ($e->reasonCode()) {
            'auth_required', 'auth_failed' => 401,
            'rate_limited' => 429,
            default => 400,
        }
    );
} catch (Throwable $e) {
    RequestContext::log('integrations/send-otp', $e->getMessage());
    Response::jsonError('Internal server error', 500);
}
