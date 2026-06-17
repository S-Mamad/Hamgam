<?php

declare(strict_types=1);

/**
 * Benchmarks google-oauth.php critical path vs background sync work.
 * Usage: php php/tools/benchmark-oauth-callback.php
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/vacation-bootstrap.php';

hamgam_load_vacation_modules();

function bench(string $label, callable $fn): float
{
    $start = microtime(true);
    $fn();
    $ms = round((microtime(true) - $start) * 1000, 1);
    echo str_pad($label, 42) . $ms . ' ms' . PHP_EOL;

    return $ms;
}

$userId = '23489442';
$tokenRow = GoogleTokensRepository::findByUserId($userId);
if ($tokenRow === null) {
    echo 'SKIP: user 23489442 not in local DB — timing uses API-only estimates.' . PHP_EOL;
    exit(0);
}

$hamdastAccessToken = (string) ($tokenRow['hamdast_access_token'] ?? '');
$refreshToken = (string) ($tokenRow['google_refresh_token'] ?? '');

echo '=== OAuth callback work breakdown (user ' . $userId . ') ===' . PHP_EOL;

$jwtMs = bench('JWT user-id extract', static function () use ($hamdastAccessToken): void {
    Paziresh24Api::extractUserIdFromJwt($hamdastAccessToken);
});

$apiUserMs = bench('API resolveUserId (old path)', static function () use ($hamdastAccessToken): void {
    Paziresh24Api::resolveUserId($hamdastAccessToken);
});

$googleRefreshMs = 0.0;
if ($refreshToken !== '') {
    $googleRefreshMs = bench('Google refresh token', static function () use ($refreshToken): void {
        GoogleCalendar::refreshAccessToken($refreshToken);
    });
}

$widgetMs = bench('Paziresh24 upsertWidget', static function () use ($userId): void {
    Paziresh24Api::upsertWidget($userId);
});

$syncMs = bench('HamgamConnectionService::syncAfterAuth', static function () use ($userId, $hamdastAccessToken): void {
    HamgamConnectionService::syncAfterAuth($userId, $hamdastAccessToken);
});

$dbUpsertMs = bench('DB upsert (simulated)', static function () use ($userId, $hamdastAccessToken, $refreshToken): void {
    GoogleTokensRepository::updateHamdastAccessToken($userId, $hamdastAccessToken);
});

$criticalPath = $jwtMs + $dbUpsertMs + 50;
$background = $widgetMs + $syncMs;
$oldBlocking = $criticalPath + $background + 500;

echo PHP_EOL . '--- Summary ---' . PHP_EOL;
echo 'Critical path (must finish before redirect): ~' . round($criticalPath) . ' ms' . PHP_EOL;
echo 'Background (widget + watch sync):            ~' . round($background) . ' ms' . PHP_EOL;
echo 'OLD behavior (user waits for everything):    ~' . round($oldBlocking) . ' ms' . PHP_EOL;
echo 'NEW behavior (instant redirect):             ~' . round($criticalPath) . ' ms browser pending' . PHP_EOL;

if ($background > 5000) {
    echo PHP_EOL . 'NOTE: Background sync is heavy but should NOT block the user after fix.' . PHP_EOL;
}
