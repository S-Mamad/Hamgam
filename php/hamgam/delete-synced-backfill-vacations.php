<?php

declare(strict_types=1);

/**
 * حذف همه مرخصی‌های ثبت‌شده توسط همگام‌سازی Google Calendar در پذیرش۲۴.
 * POST /hamgam/delete-synced-backfill-vacations
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/vacation-bootstrap.php';

hamgam_load_vacation_modules();

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

    GoogleTokensRepository::upsertHamdastAccessToken($userId, $accessToken);

    $tokenRow = GoogleTokensRepository::findByUserId($userId);
    ImportFutureVacationsRepository::reconcileBackfillSlotsFromTrackedEvents($userId);
    $targets = ImportFutureVacationsRepository::listDeletableImportVacationTargets($userId);

    $fallbackCenterId = isset($tokenRow['center_id']) && is_string($tokenRow['center_id'])
        ? trim($tokenRow['center_id'])
        : null;
    if ($fallbackCenterId === '') {
        $fallbackCenterId = null;
    }

    $result = [
        'removed' => 0,
        'already_gone' => 0,
        'failed' => 0,
        'deleted' => 0,
        'not_found' => 0,
    ];

    if ($targets !== []) {
        $result = ImportFutureVacationsRepository::deleteActiveBackfillVacations(
            $userId,
            $accessToken,
            $fallbackCenterId
        );
    }

    GoogleVacationRepository::clearProcessedEvents($userId);
    GoogleTokensRepository::clearImportFutureVacationsCompletion($userId);

    $tokenRow = GoogleTokensRepository::findByUserId($userId);
    $settings = GoogleTokensRepository::getSettings($tokenRow);

    Response::json([
        'ok' => true,
        'removed' => $result['removed'],
        'already_gone' => $result['already_gone'],
        'deleted' => $result['deleted'],
        'failed' => $result['failed'],
        'not_found' => $result['not_found'],
        'tracked_before' => count($targets),
        'settings' => $settings,
    ]);
} catch (Throwable $e) {
    RequestContext::log('hamgam/delete-synced-backfill-vacations', $e->getMessage());
    Response::jsonError('Internal server error', 500);
}
