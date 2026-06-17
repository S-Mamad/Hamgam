<?php

declare(strict_types=1);

/**
 * معادل PHP workflow n8n: POST /webhook/paziresh24-hamgam
 *
 * رویدادهای پشتیبانی‌شده:
 * - provider.appointment          → ساخت رویداد در Google Calendar
 * - provider.appointment.cancelled → حذف رویداد از Google Calendar
 * - provider.appointment.updated   → به‌روزرسانی زمان/جزئیات رویداد
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/AppointmentWebhookService.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    Response::jsonError('Method not allowed', 405);
}

$rawBody = file_get_contents('php://input');
if ($rawBody === false || trim($rawBody) === '') {
    Response::jsonError('Empty body', 400);
}

try {
    if (!WebhookVerifier::verifySvix($rawBody)) {
        RequestContext::log('paziresh24-hamgam', 'Webhook verification failed');
        Response::jsonError('Unauthorized', 401);
    }

    $payload = json_decode($rawBody, true);
    if (!is_array($payload)) {
        Response::jsonError('Invalid JSON', 400);
    }

    $booking = $payload['data'] ?? null;
    if (!is_array($booking)) {
        RequestContext::log('paziresh24-hamgam', 'skipped no_booking_data');
        Response::json(['ok' => true, 'skipped' => 'no_booking_data']);
    }

    $doctorUserId = $booking['doctor_user_id'] ?? null;
    $bookId = $booking['book_id'] ?? null;

    if ($doctorUserId === null || $doctorUserId === '' || $bookId === null || $bookId === '') {
        RequestContext::log(
            'paziresh24-hamgam',
            'skipped missing_ids doctor='
            . (is_scalar($doctorUserId) ? (string) $doctorUserId : '')
            . ' book_id=' . (is_scalar($bookId) ? (string) $bookId : '')
        );
        Response::json(['ok' => true, 'skipped' => 'missing_ids']);
    }

    $doctorUserId = GoogleTokensRepository::normalizeUserId((string) $doctorUserId);
    $bookId = (string) $bookId;

    $eventType = is_string($payload['event'] ?? null) ? trim($payload['event']) : 'provider.appointment';

    RequestContext::log(
        'paziresh24-hamgam',
        'webhook received event=' . $eventType . ' doctor=' . $doctorUserId . ' book_id=' . $bookId
    );

    $result = match ($eventType) {
        'provider.appointment.cancelled' => AppointmentWebhookService::handleCancel($booking, $doctorUserId, $bookId),
        'provider.appointment.updated' => AppointmentWebhookService::handleUpdate($booking, $doctorUserId, $bookId),
        'provider.appointment' => AppointmentWebhookService::handleCreate($booking, $doctorUserId, $bookId),
        default => (function () use ($eventType): never {
            RequestContext::log('paziresh24-hamgam', 'skipped unknown_event event=' . $eventType);
            Response::json(['ok' => true, 'skipped' => 'unknown_event']);
        })(),
    };

    Response::json($result);
} catch (Throwable $e) {
    RequestContext::log('paziresh24-hamgam', $e->getMessage());
    Response::jsonError('Internal server error', 500);
}
