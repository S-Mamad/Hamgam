<?php

declare(strict_types=1);

final class BookingAppointmentResolver
{
    /**
     * @param array<string, mixed> $booking
     * @return array{from: int, to: int}|null
     */
    public static function resolve(array $booking, string $bookId, string $hamdastAccessToken): ?array
    {
        $appointment = GoogleCalendar::getAppointment($bookId, $hamdastAccessToken);
        $range = GoogleCalendar::extractAppointmentRange($appointment);
        if ($range !== null) {
            return $range;
        }

        error_log('[paziresh24-hamgam] appointment API failed for book_id ' . $bookId . ', trying booking fallback');

        $fromBooking = self::extractFromBooking($booking);
        if ($fromBooking !== null) {
            error_log('[paziresh24-hamgam] using booking fallback for book_id ' . $bookId);
            return $fromBooking;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $booking
     */
    public static function extractCenterId(array $booking): ?string
    {
        $keys = ['center_id', 'medical_center_id', 'medical_center'];
        foreach ($keys as $key) {
            $value = $booking[$key] ?? null;
            if (is_scalar($value) && (string) $value !== '') {
                return (string) $value;
            }
        }

        if (isset($booking['center']) && is_array($booking['center'])) {
            $id = $booking['center']['id'] ?? $booking['center']['center_id'] ?? null;
            if (is_scalar($id) && (string) $id !== '') {
                return (string) $id;
            }
        }

        return null;
    }

    /**
     * @param ?array<string, mixed> $googleEvent
     */
    public static function resolveAppointmentMedicalCenterId(
        string $bookId,
        string $hamdastAccessToken,
        ?array $googleEvent = null
    ): ?string {
        if ($googleEvent !== null) {
            $fromEvent = self::extractCenterIdFromGoogleEvent($googleEvent);
            if ($fromEvent !== null) {
                return $fromEvent;
            }
        }

        $appointment = GoogleCalendar::getAppointment($bookId, $hamdastAccessToken);
        if (is_array($appointment)) {
            $fromApi = self::extractCenterId($appointment);
            if ($fromApi !== null) {
                return $fromApi;
            }
        }

        error_log('[google-vacation] cannot resolve appointment center book_id=' . $bookId);

        return null;
    }

    /**
     * @param ?array<string, mixed> $googleEvent
     */
    public static function appointmentMatchesVacationCenter(
        string $bookId,
        string $vacationMedicalCenterId,
        string $hamdastAccessToken,
        ?array $googleEvent = null
    ): bool {
        $appointmentCenterId = self::resolveAppointmentMedicalCenterId(
            $bookId,
            $hamdastAccessToken,
            $googleEvent
        );

        if ($appointmentCenterId === null) {
            return false;
        }

        return $appointmentCenterId === $vacationMedicalCenterId;
    }

    /**
     * @param array<string, mixed> $googleEvent
     */
    private static function extractCenterIdFromGoogleEvent(array $googleEvent): ?string
    {
        foreach (['private', 'shared'] as $scope) {
            $props = $googleEvent['extendedProperties'][$scope] ?? null;
            if (!is_array($props)) {
                continue;
            }

            $centerId = $props['hamgam_center_id'] ?? null;
            if (is_scalar($centerId) && trim((string) $centerId) !== '') {
                return trim((string) $centerId);
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $booking
     * @return array{from: int, to: int}|null
     */
    private static function extractFromBooking(array $booking): ?array
    {
        $from = $booking['from'] ?? $booking['book_timestamp'] ?? $booking['start_timestamp'] ?? null;
        $to = $booking['to'] ?? $booking['end_timestamp'] ?? null;

        if (is_numeric($from) && is_numeric($to) && (int) $to > (int) $from) {
            return ['from' => (int) $from, 'to' => (int) $to];
        }

        return self::parseBookDateTime($booking);
    }

    /**
     * @param array<string, mixed> $booking
     * @return array{from: int, to: int}|null
     */
    private static function parseBookDateTime(array $booking): ?array
    {
        $date = $booking['book_date'] ?? '';
        $time = $booking['book_time'] ?? '';

        if (!is_string($date) || trim($date) === '' || !is_string($time) || trim($time) === '') {
            return null;
        }

        $duration = $booking['duration'] ?? $booking['book_duration'] ?? 30;
        if (!is_numeric($duration) || (int) $duration <= 0) {
            $duration = 30;
        }

        try {
            $tz = new DateTimeZone('Asia/Tehran');
            $start = new DateTimeImmutable(trim($date) . ' ' . trim($time), $tz);
            $end = $start->modify('+' . (int) $duration . ' minutes');

            return [
                'from' => $start->getTimestamp(),
                'to' => $end->getTimestamp(),
            ];
        } catch (Throwable) {
            return null;
        }
    }
}
