<?php

declare(strict_types=1);

final class Paziresh24AppointmentApi
{
    /** @var array<string, array<string, mixed>|null> */
    private static array $appointmentCacheByBookId = [];

    public static function resetAppointmentCache(): void
    {
        self::$appointmentCacheByBookId = [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function fetchAppointmentForMove(string $bookId, string $accessToken): ?array
    {
        $bookId = trim($bookId);
        if ($bookId === '') {
            return null;
        }

        if (!array_key_exists($bookId, self::$appointmentCacheByBookId)) {
            self::$appointmentCacheByBookId[$bookId] = GoogleCalendar::getAppointment($bookId, $accessToken);
        }

        return self::$appointmentCacheByBookId[$bookId];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function deleteAppointment(string $accessToken, string $bookId): ?array
    {
        $result = self::deleteAppointmentResult($accessToken, $bookId);

        return $result['success'] ? $result['body'] : null;
    }

    /**
     * @return array{
     *   success: bool,
     *   body: ?array<string, mixed>,
     *   http_status: int,
     *   permission_denied: bool
     * }
     */
    public static function deleteAppointmentResult(string $accessToken, string $bookId): array
    {
        $bookId = trim($bookId);
        if ($bookId === '') {
            return [
                'success' => false,
                'body' => null,
                'http_status' => 0,
                'permission_denied' => false,
            ];
        }

        $baseUrl = rtrim(Config::require('PAZIRESH24_APPOINTMENT_URL'), '/');
        $url = $baseUrl . '/' . rawurlencode($bookId);

        $response = HttpClient::request(
            'DELETE',
            $url,
            ['Authorization' => 'Bearer ' . $accessToken]
        );

        $body = is_array($response['body']) ? $response['body'] : null;
        $permissionDenied = self::isPermissionDeniedResponse($response['status'], $body);

        if ($response['status'] >= 200 && $response['status'] < 300) {
            return [
                'success' => true,
                'body' => $body ?? [],
                'http_status' => $response['status'],
                'permission_denied' => false,
            ];
        }

        if ($response['status'] === 404) {
            error_log(
                '[hamgam-appointment] deleteAppointment: not found (idempotent) HTTP 404 book_id='
                . $bookId
            );

            return [
                'success' => true,
                'body' => [],
                'http_status' => 404,
                'permission_denied' => false,
            ];
        }

        if ($permissionDenied) {
            error_log(
                '[hamgam-appointment] deleteAppointment: missing provider.appointment.write scope book_id='
                . $bookId
            );
        } else {
            error_log(
                '[hamgam-appointment] deleteAppointment failed: HTTP ' . $response['status']
                . ' book_id=' . $bookId
                . ' body=' . $response['raw']
            );
        }

        return [
            'success' => false,
            'body' => $body,
            'http_status' => $response['status'],
            'permission_denied' => $permissionDenied,
        ];
    }

    /**
     * @param ?array<string, mixed> $body
     */
    public static function isPermissionDeniedResponse(int $httpStatus, ?array $body): bool
    {
        if ($httpStatus !== 403) {
            return false;
        }

        $message = is_string($body['message'] ?? null) ? strtolower($body['message']) : '';

        return str_contains($message, 'permission denied')
            || str_contains($message, 'provider.appointment.write');
    }

    /**
     * @return array<int, string>
     */
    public static function decodeTokenScopes(string $accessToken): array
    {
        $parts = explode('.', $accessToken);
        if (count($parts) < 2) {
            return [];
        }

        $payload = $parts[1];
        $payload .= str_repeat('=', (4 - strlen($payload) % 4) % 4);
        $decoded = base64_decode(strtr($payload, '-_', '+/'), true);
        if ($decoded === false) {
            return [];
        }

        $json = json_decode($decoded, true);
        if (!is_array($json)) {
            return [];
        }

        $scope = $json['scope'] ?? [];
        if (!is_array($scope)) {
            return [];
        }

        $scopes = [];
        foreach ($scope as $item) {
            if (is_string($item) && $item !== '') {
                $scopes[] = $item;
            }
        }

        return $scopes;
    }

    public static function hasAppointmentWriteScope(string $accessToken): bool
    {
        return in_array('provider.appointment.write', self::decodeTokenScopes($accessToken), true);
    }

    /**
     * @return array{
     *   success: bool,
     *   body: ?array<string, mixed>,
     *   http_status: int,
     *   permission_denied: bool
     * }
     */
    public static function moveAppointmentResult(
        string $accessToken,
        string $centerId,
        int $bookFrom,
        int $bookTo,
        int $targetFrom
    ): array {
        $centerId = trim($centerId);
        if ($centerId === '' || $bookFrom <= 0 || $bookTo <= $bookFrom || $targetFrom <= 0) {
            return [
                'success' => false,
                'body' => null,
                'http_status' => 0,
                'permission_denied' => false,
            ];
        }

        $baseUrl = rtrim(
            Config::get(
                'PAZIRESH24_BOOKING_MOVE_URL',
                'https://openapi.paziresh24.com/v1/booking/move'
            ),
            '/'
        );
        $url = $baseUrl . '/' . rawurlencode($centerId);

        $response = HttpClient::request(
            'POST',
            $url,
            [
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type' => 'application/json',
                'Accept' => '*/*',
            ],
            [
                'book_from' => $bookFrom,
                'book_to' => $bookTo,
                'target_from' => $targetFrom,
                'confirmed' => true,
            ],
            'json'
        );

        $body = is_array($response['body']) ? $response['body'] : null;
        $permissionDenied = self::isPermissionDeniedResponse($response['status'], $body);

        if ($response['status'] >= 200 && $response['status'] < 300) {
            return [
                'success' => true,
                'body' => $body ?? [],
                'http_status' => $response['status'],
                'permission_denied' => false,
            ];
        }

        if ($permissionDenied) {
            error_log(
                '[hamgam-appointment] moveAppointment: missing provider.appointment.write center_id='
                . $centerId
            );
        } else {
            error_log(
                '[hamgam-appointment] moveAppointment failed: HTTP ' . $response['status']
                . ' center_id=' . $centerId
                . ' book_from=' . $bookFrom
                . ' target_from=' . $targetFrom
                . ' body=' . $response['raw']
            );
        }

        return [
            'success' => false,
            'body' => $body,
            'http_status' => $response['status'],
            'permission_denied' => $permissionDenied,
        ];
    }

    /**
     * Fetch the first available slot (workhour_turn_num) within the next 3 months (Asia/Tehran).
     * Search is anchored to start-of-today and scans the full range (chunked API calls).
     *
     * @param ?int $excludeRangeFrom When set with $excludeRangeTo, slots inside this range are skipped.
     * @param ?int $excludeRangeTo
     * @param ?int $excludeExactTimestamp Skip the appointment's current slot (book_from).
     */
    public static function getFirstAvailableSlot(
        string $accessToken,
        string $centerId,
        string $userCenterId,
        ?int $excludeRangeFrom = null,
        ?int $excludeRangeTo = null,
        ?int $excludeExactTimestamp = null
    ): ?int {
        $centerId = trim($centerId);
        $userCenterId = trim($userCenterId);
        if ($centerId === '' || $userCenterId === '') {
            return null;
        }

        $searchRange = self::resolveSlotSearchRange();
        if ($searchRange === null) {
            return null;
        }

        $pickMinTs = max($searchRange['now'], $searchRange['range_start']);

        $chunkSeconds = 30 * 86400;
        $chunkFrom = $searchRange['range_start'];

        while ($chunkFrom < $searchRange['range_end']) {
            $chunkTo = min($chunkFrom + $chunkSeconds, $searchRange['range_end']);

            $slot = self::fetchFirstAvailableSlotInWindow(
                $accessToken,
                $centerId,
                $userCenterId,
                $chunkFrom,
                $chunkTo,
                $pickMinTs,
                $excludeRangeFrom,
                $excludeRangeTo,
                $excludeExactTimestamp
            );

            if ($slot !== null) {
                return $slot;
            }

            $chunkFrom = $chunkTo;
        }

        return null;
    }

    /**
     * @return array{now: int, range_start: int, range_end: int}|null
     */
    public static function resolveSlotSearchRange(): ?array
    {
        try {
            $tz = new DateTimeZone('Asia/Tehran');
            $now = new DateTimeImmutable('now', $tz);
            $startOfToday = $now->setTime(0, 0, 0);

            return [
                'now' => $now->getTimestamp(),
                'range_start' => $startOfToday->getTimestamp(),
                'range_end' => $startOfToday->modify('+3 months')->getTimestamp(),
            ];
        } catch (Throwable) {
            return null;
        }
    }

    private static function fetchFirstAvailableSlotInWindow(
        string $accessToken,
        string $centerId,
        string $userCenterId,
        int $windowFrom,
        int $windowTo,
        int $minTimestamp,
        ?int $excludeRangeFrom,
        ?int $excludeRangeTo,
        ?int $excludeExactTimestamp
    ): ?int {
        $baseUrl = rtrim(
            Config::get(
                'PAZIRESH24_BOOKING_SLOTS_URL',
                'https://apigw.paziresh24.com/open-platform/v1/booking/slots'
            ),
            '/'
        );

        $centerPairs = [[$centerId, $userCenterId]];
        if ($userCenterId !== $centerId) {
            $centerPairs[] = [$userCenterId, $userCenterId];
        }

        foreach ($centerPairs as [$queryCenterId, $queryUserCenterId]) {
            $url = $baseUrl . '?' . http_build_query([
                'center_id' => $queryCenterId,
                'user_center_id' => $queryUserCenterId,
                'from' => (string) $windowFrom,
                'to' => (string) $windowTo,
            ]);

            $response = HttpClient::request(
                'GET',
                $url,
                [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Accept' => '*/*',
                ]
            );

            if ($response['status'] < 200 || $response['status'] >= 300) {
                error_log(
                    '[hamgam-appointment] getAvailableSlots failed: HTTP ' . $response['status']
                    . ' center_id=' . $queryCenterId
                    . ' user_center_id=' . $queryUserCenterId
                    . ' from=' . $windowFrom
                    . ' to=' . $windowTo
                    . ' body=' . $response['raw']
                );

                continue;
            }

            $body = is_array($response['body']) ? $response['body'] : null;
            $slot = self::getFirstAvailableSlotFromBody(
                $body,
                max($minTimestamp, $windowFrom),
                $excludeRangeFrom,
                $excludeRangeTo,
                $excludeExactTimestamp
            );

            if ($slot !== null) {
                return $slot;
            }
        }

        return null;
    }

    private static function getFirstAvailableSlotFromBody(
        ?array $body,
        int $fromTs,
        ?int $excludeRangeFrom = null,
        ?int $excludeRangeTo = null,
        ?int $excludeExactTimestamp = null
    ): ?int {
        return self::extractFirstWorkhourTurnNum(
            $body,
            $fromTs,
            $excludeRangeFrom,
            $excludeRangeTo,
            $excludeExactTimestamp
        );
    }

    /**
     * @param ?array<string, mixed> $body
     */
    public static function extractFirstWorkhourTurnNum(
        ?array $body,
        int $minTimestamp = 0,
        ?int $excludeRangeFrom = null,
        ?int $excludeRangeTo = null,
        ?int $excludeExactTimestamp = null
    ): ?int {
        if ($body === null) {
            return null;
        }

        $candidates = [];
        self::collectSlotTimestamps($body, $candidates);

        if ($candidates === []) {
            return null;
        }

        $candidates = array_values(array_unique($candidates));
        sort($candidates, SORT_NUMERIC);

        foreach ($candidates as $candidate) {
            if ($candidate < $minTimestamp) {
                continue;
            }

            if ($excludeExactTimestamp !== null && $candidate === $excludeExactTimestamp) {
                continue;
            }

            if (
                $excludeRangeFrom !== null
                && $excludeRangeTo !== null
                && $excludeRangeTo > $excludeRangeFrom
                && $candidate >= $excludeRangeFrom
                && $candidate < $excludeRangeTo
            ) {
                continue;
            }

            return $candidate;
        }

        return null;
    }

    /**
     * @param ?array<string, mixed> $body
     */
    public static function extractNextWorkhourTurnNumAfter(
        ?array $body,
        int $afterTimestamp,
        ?int $excludeExactTimestamp = null
    ): ?int {
        return self::extractFirstWorkhourTurnNum(
            $body,
            $afterTimestamp + 1,
            null,
            null,
            $excludeExactTimestamp
        );
    }

    /**
     * @return array{from: int, to: int}
     */
    public static function resolveMoveRange(
        string $accessToken,
        string $bookId,
        int $fallbackFrom,
        int $fallbackTo
    ): array {
        $bookId = trim($bookId);
        if ($bookId === '') {
            return ['from' => $fallbackFrom, 'to' => $fallbackTo];
        }

        $appointment = self::fetchAppointmentForMove($bookId, $accessToken);
        if (!is_array($appointment)) {
            return ['from' => $fallbackFrom, 'to' => $fallbackTo];
        }

        $range = BookingAppointmentResolver::extractRangeFromPayload($appointment);
        if ($range !== null) {
            return self::normalizeMoveRange($range, $appointment);
        }

        return ['from' => $fallbackFrom, 'to' => $fallbackTo];
    }

    /**
     * @param array{from: int, to: int} $range
     * @param array<string, mixed> $appointment
     * @return array{from: int, to: int}
     */
    private static function normalizeMoveRange(array $range, array $appointment): array
    {
        $from = $range['from'];
        $to = $range['to'];

        $duration = $appointment['duration'] ?? $appointment['book_duration'] ?? null;
        if (is_numeric($duration) && (int) $duration > 0) {
            $expectedTo = $from + ((int) $duration * 60);
            if ($to <= $from || abs($to - $expectedTo) > 120) {
                $to = $expectedTo;
            }
        } elseif ($to <= $from) {
            $to = $from + (30 * 60);
        }

        return ['from' => $from, 'to' => $to];
    }

    /**
     * @param array<string, mixed> $node
     * @param array<int, int> $candidates
     */
    private static function collectSlotTimestamps(array $node, array &$candidates): void
    {
        if (self::isListArray($node)) {
            foreach ($node as $item) {
                if (is_array($item)) {
                    self::collectSlotTimestamps($item, $candidates);
                }
            }

            return;
        }

        $slotTs = self::extractSlotTimestampFromItem($node);
        if ($slotTs !== null) {
            $candidates[] = $slotTs;
        }

        foreach ($node as $key => $value) {
            if (!is_array($value)) {
                continue;
            }

            if (is_string($key) && self::shouldSkipSlotRecursionKey($key)) {
                continue;
            }

            if (
                $key === 'data'
                || $key === 'slots'
                || $key === 'items'
                || $key === 'result'
                || $key === 'days'
                || $key === 'dates'
                || $key === 'turns'
                || $key === 'free_turns'
                || $key === 'calendar'
                || (is_string($key) && self::isUuid($key))
                || (is_string($key) && preg_match('/^\d{4}[-\/]\d{1,2}[-\/]\d{1,2}/', $key) === 1)
                || self::looksLikeSlotContainer($value)
            ) {
                self::collectSlotTimestamps($value, $candidates);
            }
        }
    }

    private static function shouldSkipSlotRecursionKey(string $key): bool
    {
        static $skip = [
            'meta' => true,
            'pagination' => true,
            'message' => true,
            'status' => true,
            'errors' => true,
            'error' => true,
            'total' => true,
            'count' => true,
            'success' => true,
        ];

        return isset($skip[strtolower($key)]);
    }

    /**
     * @param array<mixed> $value
     */
    private static function looksLikeSlotContainer(array $value): bool
    {
        if (self::isListArray($value)) {
            foreach ($value as $item) {
                if (is_array($item) && self::extractSlotTimestampFromItem($item) !== null) {
                    return true;
                }
            }

            return false;
        }

        return self::extractSlotTimestampFromItem($value) === null
            && (
                ($value['slots'] ?? null) !== null
                || ($value['items'] ?? null) !== null
                || ($value['turns'] ?? null) !== null
            );
    }

    /**
     * @param array<string, mixed> $item
     */
    private static function extractSlotTimestampFromItem(array $item): ?int
    {
        foreach (['workhour_turn_num', 'turn_num'] as $key) {
            $ts = self::normalizeUnixTimestamp($item[$key] ?? null);
            if ($ts !== null) {
                return $ts;
            }
        }

        foreach (['from', 'start_timestamp', 'book_timestamp'] as $key) {
            $ts = self::normalizeUnixTimestamp($item[$key] ?? null);
            if ($ts !== null) {
                return $ts;
            }
        }

        return null;
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
     * @return array<int, string>
     */
    private static function moveCenterPathCandidates(string $medicalCenterId, ?string $userCenterId): array
    {
        $candidates = [];
        foreach ([$userCenterId, $medicalCenterId] as $candidate) {
            $candidate = is_string($candidate) ? trim($candidate) : '';
            if ($candidate === '' || in_array($candidate, $candidates, true)) {
                continue;
            }

            $candidates[] = $candidate;
        }

        return $candidates;
    }

    /**
     * Move appointment trying medical_center_id and user_center_id in the URL path.
     *
     * @return array{
     *   success: bool,
     *   body: ?array<string, mixed>,
     *   http_status: int,
     *   permission_denied: bool,
     *   center_id_used: ?string
     * }
     */
    public static function moveAppointmentWithCenterFallback(
        string $accessToken,
        string $medicalCenterId,
        ?string $userCenterId,
        int $bookFrom,
        int $bookTo,
        int $targetFrom
    ): array {
        $last = [
            'success' => false,
            'body' => null,
            'http_status' => 0,
            'permission_denied' => false,
            'center_id_used' => null,
        ];

        foreach (self::moveCenterPathCandidates($medicalCenterId, $userCenterId) as $centerPathId) {
            $result = self::moveAppointmentResult($accessToken, $centerPathId, $bookFrom, $bookTo, $targetFrom);
            $last = array_merge($result, ['center_id_used' => $centerPathId]);

            if ($result['success']) {
                return $last;
            }

            if ($result['permission_denied']) {
                return $last;
            }
        }

        return $last;
    }

    /**
     * Full reschedule flow: slots → move.
     *
     * @return array{
     *   success: bool,
     *   stage: string,
     *   target_from: ?int,
     *   book_from: ?int,
     *   book_to: ?int,
     *   user_center_id: ?string,
     *   center_id_used: ?string,
     *   http_status: int,
     *   permission_denied: bool,
     *   body: ?array<string, mixed>
     * }
     */
    public static function rescheduleToFirstAvailableSlot(
        string $accessToken,
        string $bookId,
        string $medicalCenterId,
        ?string $hintUserCenterId,
        int $fallbackBookFrom,
        int $fallbackBookTo,
        ?int $vacationFrom = null,
        ?int $vacationTo = null
    ): array {
        $bookId = trim($bookId);
        $medicalCenterId = trim($medicalCenterId);
        $empty = [
            'success' => false,
            'stage' => 'init',
            'target_from' => null,
            'book_from' => null,
            'book_to' => null,
            'user_center_id' => null,
            'center_id_used' => null,
            'http_status' => 0,
            'permission_denied' => false,
            'body' => null,
        ];

        if ($bookId === '' || $medicalCenterId === '') {
            return array_merge($empty, ['stage' => 'invalid_input']);
        }

        $appointment = self::fetchAppointmentForMove($bookId, $accessToken);
        $moveRange = self::resolveMoveRange($accessToken, $bookId, $fallbackBookFrom, $fallbackBookTo);
        $bookFrom = $moveRange['from'];
        $bookTo = $moveRange['to'];

        $userCenterId = BookingAppointmentResolver::resolveUserCenterIdForReschedule(
            is_array($appointment) ? $appointment : null,
            $accessToken,
            $medicalCenterId,
            $hintUserCenterId
        );

        if ($userCenterId === null || $userCenterId === '') {
            error_log(
                '[hamgam-appointment] reschedule failed stage=user_center_id book_id='
                . $bookId
                . ' center=' . $medicalCenterId
            );

            return array_merge($empty, [
                'stage' => 'user_center_id',
                'book_from' => $bookFrom,
                'book_to' => $bookTo,
            ]);
        }

        $targetFrom = self::getFirstAvailableSlot(
            $accessToken,
            $medicalCenterId,
            $userCenterId,
            $vacationFrom,
            $vacationTo,
            $bookFrom > 0 ? $bookFrom : null
        );

        if ($targetFrom === null) {
            error_log(
                '[hamgam-appointment] reschedule failed stage=slots book_id='
                . $bookId
                . ' center=' . $medicalCenterId
                . ' user_center_id=' . $userCenterId
                . ' vacation_from=' . ($vacationFrom ?? '')
                . ' vacation_to=' . ($vacationTo ?? '')
            );

            return array_merge($empty, [
                'stage' => 'slots',
                'book_from' => $bookFrom,
                'book_to' => $bookTo,
                'user_center_id' => $userCenterId,
            ]);
        }

        $moveResult = self::moveAppointmentWithCenterFallback(
            $accessToken,
            $medicalCenterId,
            $userCenterId,
            $bookFrom,
            $bookTo,
            $targetFrom
        );

        if (!$moveResult['success']) {
            $retryTarget = self::getFirstAvailableSlot(
                $accessToken,
                $medicalCenterId,
                $userCenterId,
                $vacationFrom,
                $vacationTo,
                max($bookFrom, $targetFrom)
            );

            if ($retryTarget !== null && $retryTarget !== $targetFrom) {
                $targetFrom = $retryTarget;
                $moveResult = self::moveAppointmentWithCenterFallback(
                    $accessToken,
                    $medicalCenterId,
                    $userCenterId,
                    $bookFrom,
                    $bookTo,
                    $targetFrom
                );
            }
        }

        if (!$moveResult['success']) {
            error_log(
                '[hamgam-appointment] reschedule failed stage=move book_id='
                . $bookId
                . ' center_path=' . ($moveResult['center_id_used'] ?? '')
                . ' http=' . ($moveResult['http_status'] ?? 0)
                . ' book_from=' . $bookFrom
                . ' book_to=' . $bookTo
                . ' target_from=' . $targetFrom
            );

            return [
                'success' => false,
                'stage' => 'move',
                'target_from' => $targetFrom,
                'book_from' => $bookFrom,
                'book_to' => $bookTo,
                'user_center_id' => $userCenterId,
                'center_id_used' => $moveResult['center_id_used'] ?? null,
                'http_status' => $moveResult['http_status'] ?? 0,
                'permission_denied' => $moveResult['permission_denied'] ?? false,
                'body' => $moveResult['body'] ?? null,
            ];
        }

        error_log(
            '[hamgam-appointment] reschedule ok book_id=' . $bookId
            . ' center_path=' . ($moveResult['center_id_used'] ?? '')
            . ' target_from=' . $targetFrom
        );

        return [
            'success' => true,
            'stage' => 'done',
            'target_from' => $targetFrom,
            'book_from' => $bookFrom,
            'book_to' => $bookTo,
            'user_center_id' => $userCenterId,
            'center_id_used' => $moveResult['center_id_used'] ?? null,
            'http_status' => $moveResult['http_status'] ?? 200,
            'permission_denied' => false,
            'body' => $moveResult['body'] ?? null,
        ];
    }

    /**
     * @param array<mixed> $value
     */
    private static function isListArray(array $value): bool
    {
        if ($value === []) {
            return true;
        }

        return array_keys($value) === range(0, count($value) - 1);
    }

    private static function isUuid(string $value): bool
    {
        return preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $value
        ) === 1;
    }
}
