<?php

declare(strict_types=1);

/**
 * معادل PHP workflow n8n: POST /hamgam/update
 *
 * جریان:
 * 1. دریافت access_token از هدر
 * 2. دریافت اطلاعات کاربر از Open Platform
 * 3. خواندن تنظیمات از google_tokens
 * 4. برگرداندن JSON تنظیمات
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/HamgamSyncMessages.php';

Request::applyCors();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    Response::jsonError('Method not allowed', 405);
}

try {
    $accessToken = Request::accessToken();
    if ($accessToken === '') {
        RequestContext::log('hamgam/update', '401: missing or invalid access_token header');
        Response::jsonError('Unauthorized', 401);
    }

    $userId = Paziresh24Api::resolveUserId($accessToken);
    if ($userId === null) {
        RequestContext::log('hamgam/update', '404: user id not found from access token');
        Response::jsonError('User not found', 404);
    }

    $tokenRow = GoogleTokensRepository::findByUserId($userId);
    $settings = GoogleTokensRepository::getSettings($tokenRow);
    $settings['connected'] = GoogleTokensRepository::hasRefreshToken($tokenRow);
    $settings['warnings'] = buildConnectionWarnings($tokenRow);

    Response::json($settings);
} catch (Throwable $e) {
    RequestContext::log('hamgam/update', $e->getMessage());
    Response::jsonError('Internal server error', 500);
}

/**
 * Warn when mandatory calendar sync credentials are missing for connected users.
 *
 * @param array<string, mixed>|null $tokenRow
 * @return list<array{code: string, message: string}>
 */
function buildConnectionWarnings(?array $tokenRow): array
{
    if (!GoogleTokensRepository::hasRefreshToken($tokenRow) || $tokenRow === null) {
        return [];
    }

    $warnings = [];
    $channelId = trim((string) ($tokenRow['google_channel_id'] ?? ''));
    $resourceId = trim((string) ($tokenRow['google_resource_id'] ?? ''));

    if ($channelId === '') {
        $warnings[] = HamgamSyncMessages::warning('calendar_channel_missing');
    }

    if ($resourceId === '') {
        $warnings[] = HamgamSyncMessages::warning('calendar_resource_missing');
    }

    return $warnings;
}
