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
            $recreateResult = self::tryRecreateMissingCalendarEvent($booking, $doctorUserId, $bookId);
            if ($recreateResult !== null) {
                return $recreateResult;
            }

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

        $booking = BookingAppointmentResolver::enrichBookingDetails(
            $booking,
            $bookId,
            $context['hamdast_access_token']
        );
        $booking = self::normalizeApiAppointmentBooking($booking, $bookId);

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
            return ['ok' => true, 'skipped' => $context['skip']];
        }

        $storedEventId = GoogleCalendarBookingRepository::getGoogleEventId($doctorUserId, $bookId);
        $existingEvents = self::findCalendarEvents(
            $context['google_access_token'],
            $bookId,
            $doctorUserId,
            $storedEventId
        );
        $existingEvents = self::ensureStoredEventIncluded($existingEvents, $storedEventId);
        $deletedCount = self::deleteCalendarEvents(
            $context['google_access_token'],
            $existingEvents,
            null,
            $doctorUserId,
            $bookId
        );

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

        $appointmentRange = BookingAppointmentResolver::resolveForUpdate(
            $booking,
            $bookId,
            $context['hamdast_access_token']
        );
        if ($appointmentRange === null) {
            RequestContext::log('paziresh24-hamgam', 'Update resolve failed doctor=' . $doctorUserId . ' book_id=' . $bookId);
            throw new AppointmentWebhookException('Appointment fetch failed', 502);
        }

        $booking = self::ensureBookIdInBooking($booking, $bookId);
        $booking = self::normalizeApiAppointmentBooking($booking, $bookId);
        $booking = BookingAppointmentResolver::enrichBookingDetails(
            $booking,
            $bookId,
            $context['hamdast_access_token']
        );

        $event = CalendarEventBuilder::build($appointmentRange, $context['settings'], $booking);
        if ($event === null) {
            RequestContext::log('paziresh24-hamgam', 'Invalid update time range doctor=' . $doctorUserId . ' book_id=' . $bookId);
            throw new AppointmentWebhookException('Invalid appointment time range', 422);
        }

        $storedEventId = GoogleCalendarBookingRepository::getGoogleEventId($doctorUserId, $bookId);
        $existingEvents = self::findCalendarEvents(
            $context['google_access_token'],
            $bookId,
            $doctorUserId,
            $storedEventId
        );
        $existingEvents = self::ensureStoredEventIncluded($existingEvents, $storedEventId);
        $targetEvent = self::pickEventForUpdate($existingEvents, $storedEventId);

        if ($targetEvent === null) {
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

        $eventId = GoogleEventParser::extractEventId($targetEvent);
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

        $duplicateDeleted = self::deleteCalendarEvents(
            $context['google_access_token'],
            $existingEvents,
            $eventId,
            $doctorUserId,
            $bookId
        );

        GoogleCalendarBookingRepository::recordProcessedBooking($doctorUserId, $bookId, $eventId);

        RequestContext::log(
            'paziresh24-hamgam',
            'calendar event updated doctor=' . $doctorUserId
            . ' book_id=' . $bookId
            . ' google_event_id=' . $eventId
            . ' duplicate_deleted=' . $duplicateDeleted
        );

        return [
            'ok' => true,
            'updated' => true,
            'google_event_id' => $eventId,
            'duplicate_deleted' => $duplicateDeleted,
        ];
    }

    /**
     * Fast calendar sync after API-side appointment move (vacation reschedule path).
     * Uses stored google_event_id first, then book_id lookup — never creates duplicates.
     *
     * @param ?array{from: int, to: int} $forcedRange Known post-move slot from the move API
     * @return array{ok: true, updated?: bool, created?: bool, skipped?: string, google_event_id?: string}
     */
    public static function syncCalendarFromApiMove(
        string $doctorUserId,
        string $bookId,
        ?array $forcedRange = null
    ): array {
        $doctorUserId = GoogleTokensRepository::normalizeUserId($doctorUserId);
        $bookId = trim($bookId);
        if ($bookId === '') {
            return ['ok' => true, 'skipped' => 'missing_book_id'];
        }

        $context = self::resolveDoctorContext($doctorUserId, $bookId);
        if (isset($context['skip'])) {
            return ['ok' => true, 'skipped' => $context['skip']];
        }

        Paziresh24AppointmentApi::invalidateAppointmentCache($bookId);
        $appointment = GoogleCalendar::getAppointment($bookId, $context['hamdast_access_token']);
        if (!is_array($appointment)) {
            RequestContext::log(
                'paziresh24-hamgam',
                'syncCalendarFromApiMove: appointment fetch failed doctor=' . $doctorUserId . ' book_id=' . $bookId
            );

            return ['ok' => true, 'skipped' => 'appointment_fetch_failed'];
        }

        $appointmentRange = self::resolveCalendarMoveRange(
            $appointment,
            $bookId,
            $context['hamdast_access_token'],
            $forcedRange
        );
        if ($appointmentRange === null) {
            return ['ok' => true, 'skipped' => 'invalid_range'];
        }

        $booking = self::normalizeApiAppointmentBooking($appointment, $bookId);
        $booking = BookingAppointmentResolver::enrichBookingDetails(
            $booking,
            $bookId,
            $context['hamdast_access_token']
        );

        $event = CalendarEventBuilder::build($appointmentRange, $context['settings'], $booking);
        if ($event === null) {
            return ['ok' => true, 'skipped' => 'invalid_event'];
        }

        $storedEventId = GoogleCalendarBookingRepository::getGoogleEventId($doctorUserId, $bookId);
        $existingEvents = self::findCalendarEvents(
            $context['google_access_token'],
            $bookId,
            $doctorUserId,
            $storedEventId,
            $appointmentRange['from']
        );
        $existingEvents = self::ensureStoredEventIncluded($existingEvents, $storedEventId);
        $targetEvent = self::pickEventForUpdate($existingEvents, $storedEventId);

        if ($targetEvent === null) {
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

        $eventId = GoogleEventParser::extractEventId($targetEvent);
        if ($eventId === null || $eventId === '') {
            throw new AppointmentWebhookException('Calendar event update failed', 502);
        }

        $updatedBody = GoogleCalendar::updateEventReturningBody($context['google_access_token'], $eventId, $event);
        if ($updatedBody === null) {
            throw new AppointmentWebhookException('Calendar event update failed', 502);
        }

        $duplicateDeleted = self::deleteCalendarEvents(
            $context['google_access_token'],
            $existingEvents,
            $eventId,
            $doctorUserId,
            $bookId
        );

        GoogleCalendarBookingRepository::recordProcessedBooking($doctorUserId, $bookId, $eventId);

        RequestContext::log(
            'paziresh24-hamgam',
            'syncCalendarFromApiMove updated doctor=' . $doctorUserId
            . ' book_id=' . $bookId
            . ' google_event_id=' . $eventId
            . ' from=' . $appointmentRange['from']
            . ' duplicate_deleted=' . $duplicateDeleted
        );

        return [
            'ok' => true,
            'updated' => true,
            'google_event_id' => $eventId,
            'duplicate_deleted' => $duplicateDeleted,
        ];
    }

    /**
     * Prefer a known move target over stale date/hour fields from the appointment API.
     *
     * @param array<string, mixed> $appointment
     * @param ?array{from: int, to: int} $forcedRange
     * @return array{from: int, to: int}|null
     */
    private static function resolveCalendarMoveRange(
        array $appointment,
        string $bookId,
        string $hamdastAccessToken,
        ?array $forcedRange = null
    ): ?array {
        if (
            is_array($forcedRange)
            && isset($forcedRange['from'], $forcedRange['to'])
            && (int) $forcedRange['from'] > 0
            && (int) $forcedRange['to'] > (int) $forcedRange['from']
        ) {
            return [
                'from' => (int) $forcedRange['from'],
                'to' => (int) $forcedRange['to'],
            ];
        }

        return BookingAppointmentResolver::resolveForUpdate(
            $appointment,
            $bookId,
            $hamdastAccessToken
        );
    }

    /**
     * @param array<int, array<string, mixed>> $existingEvents
     * @return array<int, array<string, mixed>>
     */
    private static function ensureStoredEventIncluded(array $existingEvents, ?string $storedEventId): array
    {
        if ($storedEventId === null || $storedEventId === '') {
            return $existingEvents;
        }

        foreach ($existingEvents as $existingEvent) {
            if (GoogleEventParser::extractEventId($existingEvent) === $storedEventId) {
                return $existingEvents;
            }
        }

        $existingEvents[] = ['id' => $storedEventId];

        return $existingEvents;
    }

    /**
     * @param array<int, array<string, mixed>> $existingEvents
     */
    private static function pickEventForUpdate(array $existingEvents, ?string $storedEventId): ?array
    {
        if ($storedEventId !== null) {
            foreach ($existingEvents as $existingEvent) {
                if (GoogleEventParser::extractEventId($existingEvent) === $storedEventId) {
                    return $existingEvent;
                }
            }

            return ['id' => $storedEventId];
        }

        return $existingEvents[0] ?? null;
    }

    /**
     * @param array<int, array<string, mixed>> $events
     */
    private static function deleteCalendarEvents(
        string $googleAccessToken,
        array $events,
        ?string $keepEventId,
        string $doctorUserId,
        string $bookId
    ): int {
        $deletedCount = 0;
        $seen = [];

        foreach ($events as $event) {
            $eventId = GoogleEventParser::extractEventId($event);
            if ($eventId === null || $eventId === '') {
                continue;
            }

            if ($keepEventId !== null && $eventId === $keepEventId) {
                continue;
            }

            if (isset($seen[$eventId])) {
                continue;
            }

            $seen[$eventId] = true;

            if (GoogleCalendar::deleteEvent($googleAccessToken, $eventId)) {
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

        return $deletedCount;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function findCalendarEvents(
        string $googleAccessToken,
        string $bookId,
        ?string $doctorUserId = null,
        ?string $storedEventId = null,
        ?int $appointmentFrom = null
    ): array {
        $bookId = trim($bookId);
        if ($bookId === '') {
            return [];
        }

        $matches = [];
        $seen = [];

        $add = static function (?array $event) use (&$matches, &$seen, $bookId): void {
            if ($event === null) {
                return;
            }

            $eventId = GoogleEventParser::extractEventId($event);
            if ($eventId === null || $eventId === '' || isset($seen[$eventId])) {
                return;
            }

            $eventBookId = GoogleEventParser::extractBookId($event);
            if ($eventBookId !== null && strcasecmp($eventBookId, $bookId) !== 0) {
                return;
            }

            $seen[$eventId] = true;
            $matches[] = $event;
        };

        foreach (GoogleCalendar::findEventsByBookId($googleAccessToken, $bookId) as $event) {
            $add(is_array($event) ? $event : null);
        }

        if ($storedEventId === null && $doctorUserId !== null && $doctorUserId !== '') {
            $storedEventId = GoogleCalendarBookingRepository::getGoogleEventId($doctorUserId, $bookId);
        }

        if (is_string($storedEventId) && $storedEventId !== '' && !isset($seen[$storedEventId])) {
            $add(GoogleCalendarWatch::getEvent($googleAccessToken, $storedEventId));
        }

        if ($matches !== []) {
            return $matches;
        }

        if ($appointmentFrom !== null && $appointmentFrom > 0) {
            try {
                $tz = new DateTimeZone('Asia/Tehran');
                $dayStart = (new DateTimeImmutable('@' . $appointmentFrom))->setTimezone($tz)->setTime(0, 0, 0);
                $dayEnd = $dayStart->modify('+1 day');
                $dayMin = $dayStart->format('Y-m-d\TH:i:s\P');
                $dayMax = $dayEnd->format('Y-m-d\TH:i:s\P');
            } catch (Throwable) {
                $dayMin = null;
                $dayMax = null;
            }

            if ($dayMin !== null && $dayMax !== null) {
                $normalizedBookId = strtolower($bookId);
                foreach (GoogleCalendarWatch::listEventsInRange($googleAccessToken, $dayMin, $dayMax) as $event) {
                    if (!is_array($event)) {
                        continue;
                    }

                    $eventBookId = GoogleEventParser::extractBookId($event);
                    if ($eventBookId !== null && strtolower($eventBookId) === $normalizedBookId) {
                        $add($event);
                        continue;
                    }

                    $description = $event['description'] ?? '';
                    if (is_string($description) && stripos($description, $bookId) !== false) {
                        $add($event);
                    }
                }
            }

            if ($matches !== []) {
                return $matches;
            }
        }

        try {
            $tz = new DateTimeZone('Asia/Tehran');
            $now = new DateTimeImmutable('now', $tz);
            $timeMin = $now->modify('-7 days')->format('Y-m-d\TH:i:s\P');
            $timeMax = $now->modify('+120 days')->format('Y-m-d\TH:i:s\P');
        } catch (Throwable) {
            return [];
        }

        $normalizedBookId = strtolower($bookId);
        foreach (GoogleCalendarWatch::listEventsInRange($googleAccessToken, $timeMin, $timeMax) as $event) {
            if (!is_array($event)) {
                continue;
            }

            $eventBookId = GoogleEventParser::extractBookId($event);
            if ($eventBookId !== null && strtolower($eventBookId) === $normalizedBookId) {
                $add($event);
                continue;
            }

            $description = $event['description'] ?? '';
            if (is_string($description) && stripos($description, $bookId) !== false) {
                $add($event);
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

    /**
     * Open-platform appointment payloads expose `id`; calendar sync needs `book_id`.
     *
     * @param array<string, mixed> $booking
     * @return array<string, mixed>
     */
    private static function ensureBookIdInBooking(array $booking, string $bookId): array
    {
        $bookId = trim($bookId);
        if ($bookId === '') {
            return $booking;
        }

        $existing = $booking['book_id'] ?? null;
        if (is_scalar($existing) && trim((string) $existing) !== '') {
            return $booking;
        }

        $booking['book_id'] = $bookId;

        return $booking;
    }

    /**
     * Map open-platform appointment field names to calendar builder fields.
     *
     * @param array<string, mixed> $booking
     * @return array<string, mixed>
     */
    private static function normalizeApiAppointmentBooking(array $booking, string $bookId): array
    {
        $booking = self::ensureBookIdInBooking($booking, $bookId);

        $aliases = [
            'patient_name' => ['name'],
            'patient_family' => ['family'],
            'patient_national_code' => ['national_code'],
            'patient_cell' => ['cell'],
            'medical_center_id' => ['center_id'],
        ];

        foreach ($aliases as $target => $sources) {
            $current = $booking[$target] ?? null;
            if (is_scalar($current) && trim((string) $current) !== '') {
                continue;
            }

            foreach ($sources as $source) {
                $value = $booking[$source] ?? null;
                if (is_scalar($value) && trim((string) $value) !== '') {
                    $booking[$target] = trim((string) $value);
                    break;
                }
            }
        }

        return $booking;
    }

    /**
     * DB may still track a book_id after the Google event was deleted manually.
     * Recreate the calendar event instead of permanently skipping create webhooks.
     *
     * @param array<string, mixed> $booking
     * @return array<string, mixed>|null
     */
    private static function tryRecreateMissingCalendarEvent(
        array $booking,
        string $doctorUserId,
        string $bookId
    ): ?array {
        try {
            $context = self::resolveDoctorContext($doctorUserId, $bookId);
        } catch (AppointmentWebhookException) {
            return null;
        }

        if (isset($context['skip'])) {
            return null;
        }

        $storedEventId = GoogleCalendarBookingRepository::getGoogleEventId($doctorUserId, $bookId);
        $existingEvents = self::findCalendarEvents(
            $context['google_access_token'],
            $bookId,
            $doctorUserId,
            $storedEventId
        );

        if ($existingEvents !== []) {
            return null;
        }

        RequestContext::log(
            'paziresh24-hamgam',
            'duplicate book_id without live calendar event — recreating doctor='
            . $doctorUserId
            . ' book_id=' . $bookId
        );

        return self::handleUpdate($booking, $doctorUserId, $bookId);
    }
}
