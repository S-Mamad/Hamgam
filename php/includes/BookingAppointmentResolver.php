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
        return self::resolveFromPayloadThenApi($booking, $bookId, $hamdastAccessToken, 'create');
    }

    /**
     * Prefer webhook payload timestamps on update (API may lag behind Paziresh24).
     *
     * @param array<string, mixed> $booking
     * @return array{from: int, to: int}|null
     */
    public static function resolveForUpdate(array $booking, string $bookId, string $hamdastAccessToken): ?array
    {
        return self::resolveFromPayloadThenApi($booking, $bookId, $hamdastAccessToken, 'update');
    }

    /**
     * Webhook payload is authoritative when it includes a valid range; API is the fallback.
     *
     * @param array<string, mixed> $booking
     * @return array{from: int, to: int}|null
     */
    private static function resolveFromPayloadThenApi(
        array $booking,
        string $bookId,
        string $hamdastAccessToken,
        string $context
    ): ?array {
        $fromBooking = self::extractFromBooking($booking);
        if ($fromBooking !== null) {
            return $fromBooking;
        }

        $appointment = GoogleCalendar::getAppointment($bookId, $hamdastAccessToken);
        $range = GoogleCalendar::extractAppointmentRange($appointment);
        if ($range !== null) {
            if ($context === 'create') {
                error_log('[paziresh24-hamgam] using appointment API for book_id ' . $bookId);
            }

            return $range;
        }

        error_log('[paziresh24-hamgam] ' . $context . ' resolve failed for book_id ' . $bookId);

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
     * @param array<string, mixed> $booking
     */
    public static function extractUserCenterId(array $booking): ?string
    {
        $keys = ['user_center_id', 'userCenterId'];
        foreach ($keys as $key) {
            $value = $booking[$key] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        if (isset($booking['center']) && is_array($booking['center'])) {
            $center = $booking['center'];
            $id = $center['user_center_id'] ?? null;
            if (is_scalar($id) && trim((string) $id) !== '') {
                return trim((string) $id);
            }

            $centerId = $center['id'] ?? null;
            $medicalId = $center['medical_center_id'] ?? $center['center_id'] ?? null;
            if (
                is_scalar($centerId)
                && trim((string) $centerId) !== ''
                && ($medicalId === null || (string) $centerId !== (string) $medicalId)
            ) {
                return trim((string) $centerId);
            }
        }

        return null;
    }

    /**
     * Resolve user_center_id for slots/move APIs from appointment payload and medical centers list.
     *
     * @param ?array<string, mixed> $appointment
     */
    public static function resolveUserCenterIdForReschedule(
        ?array $appointment,
        string $accessToken,
        string $medicalCenterId,
        ?string $hintUserCenterId = null
    ): ?string {
        $hintUserCenterId = is_string($hintUserCenterId) ? trim($hintUserCenterId) : '';

        if (is_array($appointment)) {
            $fromAppointment = self::extractUserCenterId($appointment);
            if ($fromAppointment !== null && $fromAppointment !== '') {
                return $fromAppointment;
            }
        }

        foreach (Paziresh24VacationApi::normalizeMedicalCenters($accessToken) as $center) {
            if (($center['medical_center_id'] ?? '') !== $medicalCenterId) {
                continue;
            }

            $userCenterId = isset($center['user_center_id']) && is_string($center['user_center_id'])
                ? trim($center['user_center_id'])
                : '';
            if ($userCenterId !== '') {
                return $userCenterId;
            }
        }

        $rawCenters = Paziresh24VacationApi::listMedicalCenters($accessToken);
        if (is_array($rawCenters)) {
            foreach ($rawCenters as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $flat = Paziresh24VacationApi::flattenMedicalCenterRow($row);
                if (Paziresh24VacationApi::resolveMedicalCenterId($flat) !== $medicalCenterId) {
                    continue;
                }

                foreach (['user_center_id', 'id'] as $key) {
                    $value = $flat[$key] ?? null;
                    if (!is_scalar($value) || trim((string) $value) === '') {
                        continue;
                    }

                    $candidate = trim((string) $value);
                    if ($candidate !== $medicalCenterId) {
                        return $candidate;
                    }
                }
            }
        }

        if ($hintUserCenterId !== '') {
            return $hintUserCenterId;
        }

        return $medicalCenterId;
    }

    /**
     * @param array<string, mixed> $booking
     */
    public static function extractCenterName(array $booking): string
    {
        foreach (['center_name', 'medical_center_name', 'center_title'] as $key) {
            $value = $booking[$key] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        foreach (['center', 'medical_center'] as $nestedKey) {
            $nested = $booking[$nestedKey] ?? null;
            if (!is_array($nested)) {
                continue;
            }

            foreach (['name', 'title', 'center_name', 'display_name', 'local_name', 'fa_name'] as $key) {
                $value = $nested[$key] ?? null;
                if (is_scalar($value) && trim((string) $value) !== '') {
                    return trim((string) $value);
                }
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $booking
     * @return array<string, mixed>
     */
    public static function enrichBookingDetails(array $booking, string $bookId, string $hamdastAccessToken): array
    {
        if (self::extractCenterName($booking) !== '') {
            return $booking;
        }

        $appointment = GoogleCalendar::getAppointment($bookId, $hamdastAccessToken);
        if (!is_array($appointment)) {
            return $booking;
        }

        $centerName = self::extractCenterName($appointment);
        if ($centerName !== '') {
            $booking['center_name'] = $centerName;
        }

        return $booking;
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
     * Resolve appointment start/end from API or webhook payload (from/to, book_timestamp, dates, …).
     *
     * @param array<string, mixed> $booking
     * @return array{from: int, to: int}|null
     */
    public static function extractRangeFromPayload(array $booking): ?array
    {
        return self::extractFromBooking($booking);
    }

    /**
     * @param array<string, mixed> $booking
     * @return array{from: int, to: int}|null
     */
    private static function extractFromBooking(array $booking): ?array
    {
        $wallClockRange = self::extractWallClockRange($booking);
        $numericRange = self::extractNumericRange($booking);

        if ($wallClockRange !== null) {
            if ($numericRange !== null) {
                $startDiff = $numericRange['from'] - $wallClockRange['from'];
                if ($startDiff !== 0) {
                    error_log(
                        '[paziresh24-hamgam] wall-clock time preferred over numeric from='
                        . $numericRange['from']
                        . ' wall_clock_from=' . $wallClockRange['from']
                        . ' diff=' . $startDiff . 's'
                    );
                }
            }

            return self::finalizeWallClockRange($wallClockRange, $booking, $numericRange);
        }

        if ($numericRange !== null) {
            return self::applyExplicitDuration(
                self::correctNumericRange($booking, $numericRange),
                $booking
            );
        }

        return null;
    }

    /**
     * @param array{from: int, to: int} $range
     * @param array<string, mixed> $booking
     * @return array{from: int, to: int}
     */
    private static function applyExplicitDuration(array $range, array $booking): array
    {
        $duration = $booking['duration'] ?? $booking['book_duration'] ?? null;
        if (!is_numeric($duration) || (int) $duration <= 0) {
            return $range;
        }

        return [
            'from' => $range['from'],
            'to' => $range['from'] + ((int) $duration * 60),
        ];
    }

    /**
     * Human-readable date/time from Paziresh24 is the source of truth for calendar display.
     *
     * @param array<string, mixed> $booking
     * @return array{from: int, to: int}|null
     */
    private static function extractWallClockRange(array $booking): ?array
    {
        $range = self::parseFromDateHour($booking);
        if ($range !== null) {
            return $range;
        }

        $range = self::parseBookDateTime($booking);
        if ($range !== null) {
            return $range;
        }

        return self::parseBookDateWithTimeField($booking);
    }

    /**
     * @param array{from: int, to: int} $wallClockRange
     * @param array<string, mixed> $booking
     * @param ?array{from: int, to: int} $numericRange
     * @return array{from: int, to: int}
     */
    private static function finalizeWallClockRange(
        array $wallClockRange,
        array $booking,
        ?array $numericRange
    ): array {
        $durationMinutes = self::resolveDurationMinutes($booking, $numericRange);
        $from = $wallClockRange['from'];

        return [
            'from' => $from,
            'to' => $from + ($durationMinutes * 60),
        ];
    }

    /**
     * @param array<string, mixed> $booking
     * @param ?array{from: int, to: int} $numericRange
     */
    private static function resolveDurationMinutes(array $booking, ?array $numericRange): int
    {
        $duration = $booking['duration'] ?? $booking['book_duration'] ?? null;
        if (is_numeric($duration) && (int) $duration > 0) {
            return (int) $duration;
        }

        if ($numericRange !== null) {
            $numericDuration = (int) round(($numericRange['to'] - $numericRange['from']) / 60);
            if ($numericDuration > 0 && $numericDuration <= 240) {
                return $numericDuration;
            }
        }

        $from = $booking['from'] ?? $booking['book_timestamp'] ?? null;
        $to = $booking['to'] ?? $booking['end_timestamp'] ?? null;
        if (is_numeric($from) && is_numeric($to) && (int) $to > (int) $from) {
            $inferred = (int) round(((int) $to - (int) $from) / 60);
            if ($inferred > 0 && $inferred <= 240) {
                return $inferred;
            }
        }

        return 30;
    }

    /**
     * When only unix timestamps exist, correct common +15 minute slot overshoot.
     *
     * @param array<string, mixed> $booking
     * @param array{from: int, to: int} $numericRange
     * @return array{from: int, to: int}
     */
    private static function correctNumericRange(array $booking, array $numericRange): array
    {
        $bookTimestamp = self::normalizeUnixTimestamp($booking['book_timestamp'] ?? null);
        if ($bookTimestamp !== null && $bookTimestamp !== $numericRange['from']) {
            $diff = $numericRange['from'] - $bookTimestamp;
            if ($diff > 0 && $diff % 900 === 0 && $diff <= 3600) {
                error_log(
                    '[paziresh24-hamgam] book_timestamp preferred over numeric from='
                    . $numericRange['from']
                    . ' book_timestamp=' . $bookTimestamp
                    . ' diff=' . $diff . 's'
                );

                $duration = $numericRange['to'] - $numericRange['from'];

                return [
                    'from' => $bookTimestamp,
                    'to' => $bookTimestamp + $duration,
                ];
            }
        }

        $workhourTurn = self::normalizeUnixTimestamp($booking['workhour_turn_num'] ?? null);
        if ($workhourTurn !== null && $workhourTurn === $numericRange['from']) {
            foreach (['book_timestamp', 'turn_num'] as $key) {
                $candidate = self::normalizeUnixTimestamp($booking[$key] ?? null);
                if ($candidate === null || $candidate >= $workhourTurn) {
                    continue;
                }

                $diff = $workhourTurn - $candidate;
                if ($diff > 0 && $diff % 900 === 0 && $diff <= 3600) {
                    error_log(
                        '[paziresh24-hamgam] corrected workhour_turn_num overshoot from='
                        . $workhourTurn
                        . ' corrected_from=' . $candidate
                        . ' diff=' . $diff . 's'
                    );

                    $duration = $numericRange['to'] - $numericRange['from'];

                    return [
                        'from' => $candidate,
                        'to' => $candidate + $duration,
                    ];
                }
            }
        }

        return $numericRange;
    }

    private static function normalizeUnixTimestamp(mixed $value): ?int
    {
        if (!is_numeric($value)) {
            return null;
        }

        $int = (int) $value;
        if ($int > 1_000_000_000_000) {
            $int = (int) floor($int / 1000);
        }

        if ($int < 946_684_800) {
            return null;
        }

        return $int;
    }

    /**
     * @param array<string, mixed> $booking
     * @return array{from: int, to: int}|null
     */
    private static function extractNumericRange(array $booking): ?array
    {
        $from = $booking['book_timestamp']
            ?? $booking['from']
            ?? $booking['start_timestamp']
            ?? null;
        $to = $booking['to'] ?? $booking['end_timestamp'] ?? null;

        if (is_numeric($from) && is_numeric($to) && (int) $to > (int) $from) {
            return ['from' => (int) $from, 'to' => (int) $to];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $booking
     * @return array{from: int, to: int}|null
     */
    private static function parseBookDateWithTimeField(array $booking): ?array
    {
        $date = $booking['book_date'] ?? $booking['from_date'] ?? '';
        $time = $booking['time'] ?? '';

        if (!is_string($date) || trim($date) === '' || !is_string($time) || trim($time) === '') {
            return null;
        }

        if (!preg_match('/^\d{1,2}:\d{2}/', trim($time))) {
            return null;
        }

        $duration = self::resolveDurationMinutes($booking, null);

        try {
            $tz = new DateTimeZone('Asia/Tehran');
            $start = new DateTimeImmutable(trim($date) . ' ' . trim($time), $tz);
            $end = $start->modify('+' . $duration . ' minutes');

            return [
                'from' => $start->getTimestamp(),
                'to' => $end->getTimestamp(),
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $booking
     * @return array{from: int, to: int}|null
     */
    private static function parseFromDateHour(array $booking): ?array
    {
        $date = $booking['from_date'] ?? '';
        $time = $booking['from_hour'] ?? '';

        if (!is_string($date) || trim($date) === '' || !is_string($time) || trim($time) === '') {
            return null;
        }

        $duration = self::resolveDurationMinutes($booking, null);

        try {
            $tz = new DateTimeZone('Asia/Tehran');
            $start = new DateTimeImmutable(trim($date) . ' ' . trim($time), $tz);
            $end = $start->modify('+' . $duration . ' minutes');

            return [
                'from' => $start->getTimestamp(),
                'to' => $end->getTimestamp(),
            ];
        } catch (Throwable) {
            return null;
        }
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

        $duration = self::resolveDurationMinutes($booking, null);

        try {
            $tz = new DateTimeZone('Asia/Tehran');
            $start = new DateTimeImmutable(trim($date) . ' ' . trim($time), $tz);
            $end = $start->modify('+' . $duration . ' minutes');

            return [
                'from' => $start->getTimestamp(),
                'to' => $end->getTimestamp(),
            ];
        } catch (Throwable) {
            return null;
        }
    }
}
