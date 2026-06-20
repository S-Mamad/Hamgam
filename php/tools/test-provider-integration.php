<?php

declare(strict_types=1);

/**
 * Unit tests for external provider OAuth integration layer.
 * Usage: php -c dev/php.ini php/tools/test-provider-integration.php
 */

$root = dirname(__DIR__);
$testEnv = $root . '/storage/test_provider_integration.env';
$testDbRelative = 'storage/test_provider_integration.sqlite';
$testDb = $root . '/' . $testDbRelative;

@mkdir($root . '/storage', 0750, true);

file_put_contents($testEnv, implode(PHP_EOL, [
    'APP_ENV=test',
    'DB_DRIVER=sqlite',
    'DB_PATH=' . $testDbRelative,
    'TOKEN_ENCRYPTION_KEY=' . base64_encode(random_bytes(32)),
    'INTEGRATION_OAUTH_STATE_SECRET=' . bin2hex(random_bytes(16)),
    'INTEGRATION_DRDR_CLIENT_ID=test-client',
    'INTEGRATION_DRDR_CLIENT_SECRET=test-secret',
    'INTEGRATION_DRDR_AUTH_URL=https://provider.example.com/oauth/authorize',
    'INTEGRATION_DRDR_TOKEN_URL=https://provider.example.com/oauth/token',
    'INTEGRATION_DRDR_REDIRECT_URI=https://example.com/integrations/drdr/callback',
    'INTEGRATION_DRDR_SCOPE=openid',
    'HAMDAST_API_KEY=test',
    'PAZIRESH24_OAUTH_URL=https://example.com/oauth',
    'PAZIRESH24_PROFILE_URL=https://example.com/profile',
    'PAZIRESH24_USER_INFO_URL=https://example.com/user',
    'PAZIRESH24_OPEN_USER_INFO_URL=https://example.com/open-user',
    'HAMDAST_WIDGETS_URL=https://example.com/widgets',
    'GOOGLE_CLIENT_ID=test',
    'GOOGLE_CLIENT_SECRET=test',
    'GOOGLE_OAUTH_CALLBACK_URI=https://example.com/callback',
    'GOOGLE_OAUTH_SCOPE=https://www.googleapis.com/auth/calendar.events',
    'REDIRECT_SETTINGS=https://example.com/settings',
]) . PHP_EOL);

if (is_file($testDb)) {
    unlink($testDb);
}

require_once $root . '/includes/Config.php';
Config::load($testEnv);

require_once $root . '/includes/RequestContext.php';
require_once $root . '/includes/Database.php';
require_once $root . '/includes/GoogleTokensRepository.php';
require_once $root . '/includes/TokenEncryption.php';
require_once $root . '/includes/OAuthStateSigner.php';
require_once $root . '/includes/IntegrationProviderConfig.php';
require_once $root . '/includes/DoctorExternalConnectionsRepository.php';
require_once $root . '/includes/ProviderIntegrationService.php';

$failed = 0;

function assertTrue(bool $condition, string $message): void
{
    global $failed;
    if (!$condition) {
        echo 'FAIL ' . $message . PHP_EOL;
        $failed++;
        return;
    }
    echo 'PASS ' . $message . PHP_EOL;
}

$sampleToken = 'sample-access-token-' . bin2hex(random_bytes(8));
$encrypted = TokenEncryption::encrypt($sampleToken);
$decrypted = TokenEncryption::decrypt($encrypted);
assertTrue($encrypted !== $sampleToken, 'encrypted token differs from plaintext');
assertTrue($decrypted === $sampleToken, 'token encryption round-trip');

$state = OAuthStateSigner::create('12345', 'drdr', ['return_to' => 'settings']);
$verified = OAuthStateSigner::verify($state, 'drdr');
assertTrue(is_array($verified), 'state verifies for matching provider');
assertTrue(($verified['doctor_id'] ?? '') === '12345', 'state contains doctor_id');
assertTrue(OAuthStateSigner::verify($state, 'other') === null, 'state rejects wrong provider');
assertTrue(OAuthStateSigner::verify($state . 'tampered', 'drdr') === null, 'state rejects tampered payload');

$pdo = Database::connection();
$pdo->exec('DELETE FROM doctor_external_connections');

DoctorExternalConnectionsRepository::upsert(
    doctorId: '999',
    provider: 'drdr',
    accessToken: 'access-' . bin2hex(random_bytes(4)),
    refreshToken: 'refresh-' . bin2hex(random_bytes(4)),
    expiresAt: time() + 3600
);

assertTrue(DoctorExternalConnectionsRepository::isConnected('999', 'drdr'), 'connection stored');
$status = DoctorExternalConnectionsRepository::getPublicStatus('999', 'drdr');
assertTrue(is_array($status) && ($status['has_refresh_token'] ?? false) === true, 'public status has refresh flag');

$tokens = DoctorExternalConnectionsRepository::getDecryptedTokens('999', 'drdr');
assertTrue(is_array($tokens) && str_starts_with((string) ($tokens['access_token'] ?? ''), 'access-'), 'decrypted access token readable internally');

DoctorExternalConnectionsRepository::delete('999', 'drdr');
assertTrue(!DoctorExternalConnectionsRepository::isConnected('999', 'drdr'), 'connection deleted');

$url = ProviderIntegrationService::connect('drdr', '42', 'settings');
assertTrue(str_contains($url, 'response_type=code'), 'connect URL uses authorization code flow');
assertTrue(str_contains($url, 'state='), 'connect URL includes signed state');

echo PHP_EOL . ($failed === 0 ? 'All integration tests passed.' : "Failed: {$failed}") . PHP_EOL;
exit($failed > 0 ? 1 : 0);
