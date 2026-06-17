<?php

declare(strict_types=1);

require_once __DIR__ . '/AppointmentWebhookException.php';
require_once __DIR__ . '/GoogleCalendarWatch.php';
require_once __DIR__ . '/GoogleEventParser.php';

final class AppointmentWebhookService
{
    /**
     * @param array<string, mixed> $booking
     * @return array{
     *   ok: true,
     *   created?: bool,
     *   updated?: bool,
     *   deleted?: bool,
     *   skipped?: string,
     *   google_event_id?: string
     * }
     */
    public static function handleCreate(array $booking, string $doctorUserId, string $bookId): array
    {
        if (GoogleCalendarBookingRepository::hasProcessedBooking($doctorUserId, $bookId)) {
            RequestContext::log('paziresh24-hamgam', 'skipped duplicate book_id=' . $bookId . ' doctor=' . $doctorUserId);
            return ['ok' => true, 'skipped' => 'duplicate_book_id'];
        }

        $context = self::resolveDoctorContext($doctorUserId, $bookId);
        if (isset($context['skip'])) {
            return ['ok' => true, 'skipped' => $context['skip']];
        }

        $appointmentRange = BookingAppointmentResolver::resolve(
            $booking,
            $bookId,
            $context['hamdast_access_token']
        );
        if ($appointmentRange === null) {
            RequestContext::log('paziresh24-hamgam', 'Appointment resolve failed doctor=' . $doctorUserId . ' book_id=' . $bookId);
            throw new AppointmentWebhookException('Appointment fetch failed', 502);
        }

        $event = CalendarEventBuilder::build($appointmentRange, $context['settings'], $booking);
        if ($event === null) {
            RequestContext::log('paziresh24-hamgam', 'Invalid appointment time range doctor=' . $doctorUserId . ' book_id=' . $bookId);
            throw new AppointmentWebhookException('Invalid appointment time range', 422);
        }

        $createdBody = GoogleCalendar::createEventReturningBody($context['google_access_token'], $event);
        if ($createdBody === null) {
            RequestContext::log('paziresh24-hamgam', 'Calendar event creation failed doctor=' . $doctorUserId . ' book_id=' . $bookId);
            throw new AppointmentWebhookException('Calendar event creation failed', 502);
        }

        $googleEventId = is_string($createdBody['id'] ?? null) ? $createdBody['id'] : '';
        GoogleCalendarBookingRepository::recordProcessedBooking($doctorUserId, $bookId, $googleEventId);

        RequestContext::log(
            'paziresh24-hamgam',
            'calendar event created doctor=' . $doctorUserId
            . ' book_id=' . $bookId
            . ' google_event_id=' . $googleEventId
        );

        $result = ['ok' => true, 'created' => true];
        if ($googleEventId !== '') {
            $result['google_event_id'] = $googleEventId;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $booking
     * @return array{ok: true, deleted?: bool, skipped?: string, deleted_count?: int}
     */
    public static function handleCancel(array $booking, string $doctorUserId, string $bookId): array
    {
        $context = self::resolveGoogleOnlyContext($doctorUserId, $bookId);
        if (isset($context['skip'])) {
            GoogleCalendarBookingRepository::removeProcessedBooking($doctorUserId, $bookId);
            return ['ok' => true, 'skipped' => $context['skip']];
        }

        $deletedCount = 0;
        $storedEventId = GoogleCalendarBookingRepository::getGoogleEventId($doctorUserId, $bookId);

        if ($storedEventId !== null) {
            if (GoogleCalendar::deleteEvent($context['google_access_token'], $storedEventId)) {
                $deletedCount++;
                RequestContext::log(
                    'paziresh24-hamgam',
                    'calendar event deleted via stored id doctor=' . $doctorUserId
                    . ' book_id=' . $bookId
                    . ' google_event_id=' . $storedEventId
                );
            } else {
                RequestContext::log(
                    'paziresh24-hamgam',
                    'stored calendar event delete failed doctor=' . $doctorUserId
                    . ' book_id=' . $bookId
                    . ' google_event_id=' . $storedEventId
                );
            }
        }

        if ($deletedCount === 0) {
            $existingEvents = self::findCalendarEvents($context['google_access_token'], $bookId);

            foreach ($existingEvents as $existingEvent) {
                $eventId = GoogleEventParser::extractEventId($existingEvent);
                if ($eventId === null || $eventId === '') {
                    continue;
                }

                if (GoogleCalendar::deleteEvent($context['google_access_token'], $eventId)) {
                    $deletedCount++;
                    RequestContext::log(
                        'paziresh24-hamgam',
                        'calendar event deleted doctor=' . $doctorUserId
                        . ' book_id=' . $bookId
                        . ' google_event_id=' . $eventId
                    );
                } else {
                    RequestContext::log(
                        'paziresh24-hamgam',
                        'calendar event delete failed doctor=' . $doctorUserId
                        . ' book_id=' . $bookId
                        . ' google_event_id=' . $eventId
                    );
                }
            }
        }

        GoogleCalendarBookingRepository::removeProcessedBooking($doctorUserId, $bookId);

        if ($deletedCount === 0) {
            RequestContext::log(
                'paziresh24-hamgam',
                'cancel webhook: no calendar event found doctor=' . $doctorUserId . ' book_id=' . $bookId
            );

            return ['ok' => true, 'skipped' => 'event_not_found', 'deleted_count' => 0];
        }

        return ['ok' => true, 'deleted' => true, 'deleted_count' => $deletedCount];
    }

    /**
     * @param array<string, mixed> $booking
     * @return array{ok: true, updated?: bool, created?: bool, skipped?: string, google_event_id?: string}
     */
    public static function handleUpdate(array $booking, string $doctorUserId, string $bookId): array
    {
        $context = self::resolveDoctorContext($doctorUserId, $bookId);
        if (isset($context['skip'])) {
            return ['ok' => true, 'skipped' => $context['skip']];
        }

        $appointmentRange = BookingAppointmentResolver::resolve(
            $booking,
            $bookId,
            $context['hamdast_access_token']
        );
        if ($appointmentRange === null) {
            RequestContext::log('paziresh24-hamgam', 'Update resolve failed doctor=' . $doctorUserId . ' book_id=' . $bookId);
            throw new AppointmentWebhookException('Appointment fetch failed', 502);
        }

        $event = CalendarEventBuilder::build($appointmentRange, $context['settings'], $booking);
        if ($event === null) {
            RequestContext::log('paziresh24-hamgam', 'Invalid update time range doctor=' . $doctorUserId . ' book_id=' . $bookId);
            throw new AppointmentWebhookException('Invalid appointment time range', 422);
        }

        $storedEventId = GoogleCalendarBookingRepository::getGoogleEventId($doctorUserId, $bookId);
        $existingEvents = self::findCalendarEvents($context['google_access_token'], $bookId);
        $existingEvent = $existingEvents[0] ?? null;

        if ($existingEvent === null && $storedEventId !== null) {
            $existingEvent = ['id' => $storedEventId];
        }

        if ($existingEvent === null) {
            RequestContext::log(
                'paziresh24-hamgam',
                'update webhook: event not found, creating doctor=' . $doctorUserId . ' book_id=' . $bookId
            );

            $createdBody = GoogleCalendar::createEventReturningBody($context['google_access_token'], $event);
            if ($createdBody === null) {
                throw new AppointmentWebhookException('Calendar event creation failed', 502);
            }

            $googleEventId = is_string($createdBody['id'] ?? null) ? $createdBody['id'] : '';
            GoogleCalendarBookingRepository::recordProcessedBooking($doctorUserId, $bookId, $googleEventId);

            return array_filter([
                'ok' => true,
                'created' => true,
                'google_event_id' => $googleEventId !== '' ? $googleEventId : null,
            ], static fn ($value) => $value !== null);
        }

        $eventId = GoogleEventParser::extractEventId($existingEvent);
        if ($eventId === null || $eventId === '') {
            throw new AppointmentWebhookException('Calendar event update failed', 502);
        }

        $updatedBody = GoogleCalendar::updateEventReturningBody($context['google_access_token'], $eventId, $event);
        if ($updatedBody === null) {
            RequestContext::log(
                'paziresh24-hamgam',
                'calendar event update failed doctor=' . $doctorUserId
                . ' book_id=' . $bookId
                . ' google_event_id=' . $eventId
            );
            throw new AppointmentWebhookException('Calendar event update failed', 502);
        }

        GoogleCalendarBookingRepository::recordProcessedBooking($doctorUserId, $bookId, $eventId);

        RequestContext::log(
            'paziresh24-hamgam',
            'calendar event updated doctor=' . $doctorUserId
            . ' book_id=' . $bookId
            . ' google_event_id=' . $eventId
        );

        return [
            'ok' => true,
            'updated' => true,
            'google_event_id' => $eventId,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function findCalendarEvents(string $googleAccessToken, string $bookId): array
    {
        $matches = GoogleCalendar::findEventsByBookId($googleAccessToken, $bookId);
        if ($matches !== []) {
            return $matches;
        }

        try {
            $tz = new DateTimeZone('Asia/Tehran');
            $now = new DateTimeImmutable('now', $tz);
            $timeMin = $now->modify('-90 days')->format('Y-m-d\TH:i:s\P');
            $timeMax = $now->modify('+365 days')->format('Y-m-d\TH:i:s\P');
        } catch (Throwable) {
            return [];
        }

        $events = GoogleCalendarWatch::listEventsInRange($googleAccessToken, $timeMin, $timeMax);
        $normalizedBookId = strtolower($bookId);
        $matches = [];

        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }

            $eventBookId = GoogleEventParser::extractBookId($event);
            if ($eventBookId !== null && strtolower($eventBookId) === $normalizedBookId) {
                $matches[] = $event;
            }
        }

        return $matches;
    }

    /**
     * @return array{
     *   google_access_token: string,
     *   skip?: string
     * }
     */
    private static function resolveGoogleOnlyContext(string $doctorUserId, string $bookId): array
    {
        $tokenRow = GoogleTokensRepository::findByUserId($doctorUserId);
        if (!GoogleTokensRepository::hasRefreshToken($tokenRow)) {
            RequestContext::log('paziresh24-hamgam', 'skipped not_connected doctor=' . $doctorUserId . ' book_id=' . $bookId);
            return ['skip' => 'not_connected'];
        }

        $refreshToken = (string) ($tokenRow['google_refresh_token'] ?? '');
        $googleTokenData = GoogleCalendar::refreshAccessToken($refreshToken);
        $googleAccessToken = is_array($googleTokenData) ? ($googleTokenData['access_token'] ?? '') : '';
        if (!is_string($googleAccessToken) || $googleAccessToken === '') {
            RequestContext::log('paziresh24-hamgam', 'Google token refresh failed doctor=' . $doctorUserId . ' book_id=' . $bookId);
            throw new AppointmentWebhookException('Google token refresh failed', 502);
        }

        return [
            'google_access_token' => $googleAccessToken,
        ];
    }

    /**
     * @return array{
     *   google_access_token: string,
     *   hamdast_access_token: string,
     *   settings: array<string, mixed>,
     *   skip?: string
     * }
     */
    private static function resolveDoctorContext(string $doctorUserId, string $bookId): array
    {
        $tokenRow = GoogleTokensRepository::findByUserId($doctorUserId);
        if (!GoogleTokensRepository::hasRefreshToken($tokenRow)) {
            RequestContext::log('paziresh24-hamgam', 'skipped not_connected doctor=' . $doctorUserId . ' book_id=' . $bookId);
            return ['skip' => 'not_connected'];
        }

        $refreshToken = (string) ($tokenRow['google_refresh_token'] ?? '');
        $hamdastAccessToken = (string) ($tokenRow['hamdast_access_token'] ?? '');

        if ($hamdastAccessToken === '') {
            RequestContext::log('paziresh24-hamgam', 'skipped missing_hamdast_token doctor=' . $doctorUserId . ' book_id=' . $bookId);
            return ['skip' => 'missing_hamdast_token'];
        }

        $googleTokenData = GoogleCalendar::refreshAccessToken($refreshToken);
        $googleAccessToken = is_array($googleTokenData) ? ($googleTokenData['access_token'] ?? '') : '';
        if (!is_string($googleAccessToken) || $googleAccessToken === '') {
            RequestContext::log('paziresh24-hamgam', 'Google token refresh failed doctor=' . $doctorUserId . ' book_id=' . $bookId);
            throw new AppointmentWebhookException('Google token refresh failed', 502);
        }

        return [
            'google_access_token' => $googleAccessToken,
            'hamdast_access_token' => $hamdastAccessToken,
            'settings' => GoogleTokensRepository::getSettings($tokenRow),
        ];
    }
}
