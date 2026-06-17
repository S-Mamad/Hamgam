<?php

declare(strict_types=1);

/**
 * وضعیت همگام‌سازی Google Calendar (برای polling فرانت بعد از OAuth یا ذخیره تنظیمات).
 */

require_once __DIR__ . '/../includes/bootstrap.php';

Request::applyCors();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    Response::jsonError('Method not allowed', 405);
}

try {
    $accessToken = Request::accessToken();
    if ($accessToken === '') {
        Response::jsonError('Unauthorized', 401);
    }

    $userId = Paziresh24Api::resolveUserId($accessToken);
    if ($userId === null) {
        Response::jsonError('User not found', 404);
    }

    $status = GoogleTokensRepository::getSyncStatus($userId);

    if ($status === null) {
        Response::json([
            'ok' => true,
            'sync_status' => [
                'pending' => false,
                'ok' => true,
                'warnings' => [],
            ],
        ]);
    }

    Response::json([
        'ok' => true,
        'sync_status' => $status,
    ]);
} catch (Throwable $e) {
    RequestContext::log('hamgam/sync-status', $e->getMessage());
    Response::jsonError('Internal server error', 500);
}
