<?php

declare(strict_types=1);

/**
 * معادل PHP workflow n8n: POST /webhook/paziresh24-hamgam
 *
 * ایونت‌های پشتیبانی‌شده:
 * - provider.appointment          → ساخت ایونت‌ در Google Calendar
 * - provider.appointment.cancelled → حذف ایونت‌ از Google Calendar
 * - provider.appointment.updated   → به‌روزرسانی زمان/جزئیات ایونت‌
 */

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/AppointmentWebhookException.php';
require_once __DIR__ . '/../includes/AppointmentWebhookService.php';
require_once __DIR__ . '/../includes/Paziresh24WebhookPayload.php';

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

    $booking = Paziresh24WebhookPayload::extractBooking($payload);
    if ($booking === null) {
        RequestContext::log('paziresh24-hamgam', 'skipped no_booking_data');
        Response::json(['ok' => true, 'skipped' => 'no_booking_data']);
    }

    $doctorUserId = Paziresh24WebhookPayload::extractDoctorUserId($booking);
    $bookId = Paziresh24WebhookPayload::extractBookId($booking);

    if ($doctorUserId === null || $doctorUserId === '' || $bookId === null || $bookId === '') {
        RequestContext::log(
            'paziresh24-hamgam',
            'skipped missing_ids doctor='
            . ($doctorUserId ?? '')
            . ' book_id=' . ($bookId ?? '')
        );
        Response::json(['ok' => true, 'skipped' => 'missing_ids']);
    }

    $doctorUserId = GoogleTokensRepository::normalizeUserId($doctorUserId);
    $bookId = (string) $bookId;
    $eventType = Paziresh24WebhookPayload::extractEventType($payload);

    RequestContext::log(
        'paziresh24-hamgam',
        'webhook accepted event=' . $eventType . ' doctor=' . $doctorUserId . ' book_id=' . $bookId
    );

    Response::jsonThenContinue(
        ['ok' => true, 'accepted' => true, 'event' => $eventType],
        static function () use ($eventType, $booking, $doctorUserId, $bookId): void {
            try {
                $result = match ($eventType) {
                    'provider.appointment.cancelled' => AppointmentWebhookService::handleCancel($booking, $doctorUserId, $bookId),
                    'provider.appointment.updated' => AppointmentWebhookService::handleUpdate($booking, $doctorUserId, $bookId),
                    'provider.appointment' => AppointmentWebhookService::handleCreate($booking, $doctorUserId, $bookId),
                    default => ['ok' => true, 'skipped' => 'unknown_event'],
                };

                RequestContext::log(
                    'paziresh24-hamgam',
                    'webhook processed event=' . $eventType
                    . ' doctor=' . $doctorUserId
                    . ' book_id=' . $bookId
                    . ' result=' . json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                );
            } catch (AppointmentWebhookException $e) {
                RequestContext::log(
                    'paziresh24-hamgam',
                    'webhook processing failed event=' . $eventType
                    . ' doctor=' . $doctorUserId
                    . ' book_id=' . $bookId
                    . ' error=' . $e->getMessage()
                );
            } catch (Throwable $e) {
                RequestContext::log(
                    'paziresh24-hamgam',
                    'webhook processing error event=' . $eventType
                    . ' doctor=' . $doctorUserId
                    . ' book_id=' . $bookId
                    . ' error=' . $e->getMessage()
                );
            }
        }
    );
} catch (Throwable $e) {
    RequestContext::log('paziresh24-hamgam', $e->getMessage());
    Response::jsonError('Internal server error', 500);
}
