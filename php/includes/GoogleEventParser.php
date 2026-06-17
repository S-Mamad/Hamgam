<?php

declare(strict_types=1);

final class GoogleEventParser
{
    private const DEFAULT_TIMEZONE = 'Asia/Tehran';

    /**
     * @param array<string, mixed> $googleEvent
     */
    public static function extractEventId(array $googleEvent): ?string
    {
        $eventId = $googleEvent['id'] ?? '';

        if (!is_string($eventId) || $eventId === '') {
            return null;
        }

        return $eventId;
    }

    /**
     * @param array<string, mixed> $googleEvent
     * @return array{
     *   event_id: string,
     *   summary: string,
     *   status: string,
     *   start_ts: int,
     *   end_ts: int,
     *   timezone: string,
     *   created: ?string,
     *   updated: ?string,
     *   is_deleted: bool
     * }|null
     */
    public static function parseEvent(array $googleEvent): ?array
    {
        $eventId = $googleEvent['id'] ?? '';
        if (!is_string($eventId) || $eventId === '') {
            return null;
        }

        $status = is_string($googleEvent['status'] ?? null) ? $googleEvent['status'] : 'confirmed';
        $isDeleted = ($googleEvent['deleted'] ?? false) === true || $status === 'cancelled';

        $start = is_array($googleEvent['start'] ?? null) ? $googleEvent['start'] : [];
        $end = is_array($googleEvent['end'] ?? null) ? $googleEvent['end'] : [];

        $eventTimezone = self::resolveTimezone($start, $end);
        $startTs = self::parseBoundaryForIran($start, $eventTimezone);
        $endTs = self::parseBoundaryForIran($end, $eventTimezone);

        if ($startTs === null || $endTs === null) {
            return null;
        }

        if ($endTs <= $startTs) {
            return null;
        }

        $summary = $googleEvent['summary'] ?? '';
        $summary = is_string($summary) ? $summary : '';

        return [
            'event_id' => $eventId,
            'summary' => $summary,
            'status' => $status,
            'start_ts' => $startTs,
            'end_ts' => $endTs,
            'timezone' => self::DEFAULT_TIMEZONE,
            'created' => is_string($googleEvent['created'] ?? null) ? $googleEvent['created'] : null,
            'updated' => is_string($googleEvent['updated'] ?? null) ? $googleEvent['updated'] : null,
            'is_deleted' => $isDeleted,
        ];
    }

    public static function isHamgamGeneratedEvent(string $summary): bool
    {
        $normalized = self::normalizeTextForMatch(trim($summary));

        $patterns = [
            'پذیرش 24',
            'پذیرش۲۴',
            'نوبت پذیرش',
            'ویزیت آنلاین پذیرش',
            'نام بیمار',
        ];

        foreach ($patterns as $pattern) {
            if (str_contains($normalized, self::normalizeTextForMatch($pattern))) {
                return true;
            }
        }

        return false;
    }

    private static function normalizeTextForMatch(string $value): string
    {
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($value, 'UTF-8');
        }

        return strtolower($value);
    }

    /**
     * @param array{
     *   created: ?string,
     *   updated: ?string
     * } $parsed
     */
    public static function isEventNewerThanCutoff(array $parsed, int $cutoffTs): bool
    {
        if ($cutoffTs <= 0) {
            return true;
        }

        foreach (['created', 'updated'] as $field) {
            $iso = $parsed[$field] ?? null;
            if (!is_string($iso) || trim($iso) === '') {
                continue;
            }

            $ts = strtotime($iso);
            if ($ts !== false && $ts >= $cutoffTs) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $googleEvent
     */
    public static function extractBookId(array $googleEvent): ?string
    {
        $private = $googleEvent['extendedProperties']['private'] ?? null;
        if (is_array($private)) {
            $bookId = $private['hamgam_book_id'] ?? null;
            if (is_string($bookId) && self::isUuid($bookId)) {
                return $bookId;
            }
        }

        $shared = $googleEvent['extendedProperties']['shared'] ?? null;
        if (is_array($shared)) {
            $bookId = $shared['hamgam_book_id'] ?? null;
            if (is_string($bookId) && self::isUuid($bookId)) {
                return $bookId;
            }
        }

        $description = $googleEvent['description'] ?? '';
        if (!is_string($description) || trim($description) === '') {
            return null;
        }

        if (preg_match('/hamgam_book_id:\s*([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i', $description, $matches) === 1) {
            return strtolower($matches[1]);
        }

        return null;
    }

    public static function isHamgamAppointmentEvent(array $googleEvent): bool
    {
        if (self::extractBookId($googleEvent) !== null) {
            return true;
        }

        $summary = $googleEvent['summary'] ?? '';

        return is_string($summary) && self::isHamgamGeneratedEvent($summary);
    }

    private static function isUuid(string $value): bool
    {
        return preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $value
        ) === 1;
    }

    /**
     * @param array<string, mixed> $start
     * @param array<string, mixed> $end
     */
    private static function resolveTimezone(array $start, array $end): string
    {
        $tz = $start['timeZone'] ?? $end['timeZone'] ?? null;
        if (is_string($tz) && $tz !== '') {
            return $tz;
        }

        return self::DEFAULT_TIMEZONE;
    }

    /**
     * Parse event boundary for Paziresh24 vacation (always Asia/Tehran).
     * All-day dates and floating dateTimes use Iran local wall clock, not the calendar's timezone.
     *
     * @param array<string, mixed> $boundary
     */
    private static function parseBoundaryForIran(array $boundary, string $eventTimezone): ?int
    {
        $iranTz = new DateTimeZone(self::DEFAULT_TIMEZONE);

        if (isset($boundary['date']) && is_string($boundary['date']) && $boundary['date'] !== '') {
            try {
                return (new DateTimeImmutable($boundary['date'] . ' 00:00:00', $iranTz))->getTimestamp();
            } catch (Throwable) {
                return null;
            }
        }

        if (isset($boundary['dateTime']) && is_string($boundary['dateTime']) && $boundary['dateTime'] !== '') {
            try {
                $dateTime = $boundary['dateTime'];

                if (preg_match('/(?:[Zz]|[+-]\d{2}:?\d{2})$/', $dateTime)) {
                    $instant = new DateTimeImmutable($dateTime);
                    $iranLocal = $instant->setTimezone($iranTz);

                    return (new DateTimeImmutable($iranLocal->format('Y-m-d H:i:s'), $iranTz))->getTimestamp();
                }

                $eventTz = new DateTimeZone($eventTimezone);
                $parsed = new DateTimeImmutable($dateTime, $eventTz);
                $wallClock = $parsed->format('Y-m-d H:i:s');

                return (new DateTimeImmutable($wallClock, $iranTz))->getTimestamp();
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }
}
