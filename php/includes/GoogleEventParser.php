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

        if (preg_match('/^([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})\s*$/im', $description, $matches) === 1) {
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

    /**
     * Master id for a recurring series (recurringEventId on instances, or own id on the master row).
     */
    public static function extractRecurringSeriesKey(array $googleEvent): ?string
    {
        $recurringEventId = $googleEvent['recurringEventId'] ?? null;
        if (is_string($recurringEventId) && $recurringEventId !== '') {
            return $recurringEventId;
        }

        $recurrence = $googleEvent['recurrence'] ?? null;
        if (is_array($recurrence) && $recurrence !== []) {
            return self::extractEventId($googleEvent);
        }

        return null;
    }

    public static function isRecurringGoogleEvent(array $googleEvent): bool
    {
        return self::extractRecurringSeriesKey($googleEvent) !== null;
    }

    /**
     * @param array<int, array<string, mixed>> $googleEvents
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
    public static function aggregateRecurringInstances(array $googleEvents, string $seriesKey): ?array
    {
        $parsedList = [];

        foreach ($googleEvents as $googleEvent) {
            if (!is_array($googleEvent)) {
                continue;
            }

            if (($googleEvent['deleted'] ?? false) === true) {
                continue;
            }

            $rawStatus = $googleEvent['status'] ?? null;
            if (is_string($rawStatus) && $rawStatus === 'cancelled') {
                continue;
            }

            $parsed = self::parseEvent($googleEvent);
            if ($parsed === null || $parsed['status'] !== 'confirmed') {
                continue;
            }

            $parsedList[] = $parsed;
        }

        if ($parsedList === []) {
            return null;
        }

        $minStart = PHP_INT_MAX;
        $maxEnd = 0;
        $summary = '';
        $created = null;
        $updated = null;

        foreach ($parsedList as $parsed) {
            $minStart = min($minStart, $parsed['start_ts']);
            $maxEnd = max($maxEnd, $parsed['end_ts']);

            if ($summary === '' && $parsed['summary'] !== '') {
                $summary = $parsed['summary'];
            }

            foreach (['created', 'updated'] as $field) {
                $value = $parsed[$field] ?? null;
                if (!is_string($value) || $value === '') {
                    continue;
                }

                if ($field === 'created' && ($created === null || strcmp($value, $created) < 0)) {
                    $created = $value;
                }

                if ($field === 'updated' && ($updated === null || strcmp($value, $updated) > 0)) {
                    $updated = $value;
                }
            }
        }

        if ($minStart === PHP_INT_MAX || $maxEnd <= $minStart) {
            return null;
        }

        $base = $parsedList[0];

        return [
            'event_id' => $seriesKey,
            'summary' => $summary !== '' ? $summary : $base['summary'],
            'status' => 'confirmed',
            'start_ts' => $minStart,
            'end_ts' => $maxEnd,
            'timezone' => $base['timezone'],
            'created' => $created,
            'updated' => $updated,
            'is_deleted' => false,
        ];
    }

    /**
     * Full-series query window for recurring instances (not limited to the 30-day backfill slice).
     *
     * @param array<string, mixed>|null $masterEvent
     * @param array<int, array<string, mixed>>|null $prefetchedInstances
     * @return array{time_min: string, time_max: string, time_min_ts: int, time_max_ts: int}
     */
    public static function resolveRecurringInstancesQueryWindow(
        ?array $masterEvent,
        ?array $prefetchedInstances,
        int $horizonSeconds
    ): array {
        $now = time();
        $timeMinTs = $now;
        $timeMaxTs = $now + $horizonSeconds;
        $parsedMaster = $masterEvent !== null ? self::parseEvent($masterEvent) : null;

        if ($parsedMaster !== null) {
            $timeMinTs = $parsedMaster['start_ts'];
            $timeMaxTs = max(
                $timeMaxTs,
                self::estimateRecurringSeriesEndTs($masterEvent, $parsedMaster)
            );
        }

        if ($prefetchedInstances !== null) {
            foreach ($prefetchedInstances as $googleEvent) {
                if (!is_array($googleEvent)) {
                    continue;
                }

                $parsed = self::parseEvent($googleEvent);
                if ($parsed === null) {
                    continue;
                }

                $timeMinTs = min($timeMinTs, $parsed['start_ts']);
                $timeMaxTs = max($timeMaxTs, $parsed['end_ts']);
            }
        }

        $timeMinTs = max(0, $timeMinTs - 3600);
        $timeMaxTs += 86400;

        return [
            'time_min' => gmdate('Y-m-d\TH:i:s\Z', $timeMinTs),
            'time_max' => gmdate('Y-m-d\TH:i:s\Z', $timeMaxTs),
            'time_min_ts' => $timeMinTs,
            'time_max_ts' => $timeMaxTs,
        ];
    }

    /**
     * @param array<string, mixed> $masterEvent
     */
    private static function estimateRecurringSeriesEndTs(array $masterEvent, array $parsedMaster): int
    {
        $fallbackEndTs = max($parsedMaster['end_ts'], $parsedMaster['start_ts'] + 86400);
        $recurrence = $masterEvent['recurrence'] ?? null;
        if (!is_array($recurrence) || $recurrence === []) {
            return $fallbackEndTs;
        }

        foreach ($recurrence as $rule) {
            if (!is_string($rule) || !str_starts_with($rule, 'RRULE:')) {
                continue;
            }

            if (preg_match('/UNTIL=([^;]+)/i', $rule, $matches) === 1) {
                $untilTs = self::parseRruleUntilTimestamp($matches[1]);
                if ($untilTs !== null) {
                    return max($fallbackEndTs, $untilTs + 86400);
                }
            }

            if (preg_match('/COUNT=(\d+)/i', $rule, $matches) === 1) {
                $count = (int) $matches[1];
                if ($count > 0) {
                    $freq = 'WEEKLY';
                    if (preg_match('/FREQ=([A-Z]+)/i', $rule, $freqMatch) === 1) {
                        $freq = strtoupper($freqMatch[1]);
                    }

                    $lastStart = self::estimateLastOccurrenceStartTs($parsedMaster['start_ts'], $freq, $count);
                    if ($lastStart !== null) {
                        $duration = max(86400, $parsedMaster['end_ts'] - $parsedMaster['start_ts']);

                        return max($fallbackEndTs, $lastStart + $duration + 3600);
                    }
                }
            }
        }

        return $fallbackEndTs;
    }

    private static function parseRruleUntilTimestamp(string $untilRaw): ?int
    {
        $untilRaw = trim($untilRaw);
        if ($untilRaw === '') {
            return null;
        }

        if (preg_match('/^\d{8}$/', $untilRaw) === 1) {
            try {
                return (new DateTimeImmutable($untilRaw . ' 23:59:59', new DateTimeZone('UTC')))->getTimestamp();
            } catch (Throwable) {
                return null;
            }
        }

        $ts = strtotime($untilRaw);

        return $ts === false ? null : $ts;
    }

    private static function estimateLastOccurrenceStartTs(int $startTs, string $freq, int $count): ?int
    {
        if ($count < 1) {
            return null;
        }

        $offset = $count - 1;

        try {
            $dt = (new DateTimeImmutable('@' . $startTs))
                ->setTimezone(new DateTimeZone(self::DEFAULT_TIMEZONE));

            $modifier = match ($freq) {
                'DAILY' => '+' . $offset . ' days',
                'WEEKLY' => '+' . $offset . ' weeks',
                'MONTHLY' => '+' . $offset . ' months',
                'YEARLY' => '+' . $offset . ' years',
                default => '+' . $offset . ' weeks',
            };

            return $dt->modify($modifier)->getTimestamp();
        } catch (Throwable) {
            return null;
        }
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
