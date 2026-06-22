<?php

declare(strict_types=1);

final class Paziresh24AppointmentApi
{
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
                'https://apigw.paziresh24.com/open-platform/v1/booking/move'
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
     *
     * @param ?int $excludeRangeFrom When set with $excludeRangeTo, slots inside this range are skipped.
     * @param ?int $excludeRangeTo
     */
    public static function getFirstAvailableSlot(
        string $accessToken,
        string $centerId,
        string $userCenterId,
        ?int $excludeRangeFrom = null,
        ?int $excludeRangeTo = null
    ): ?int {
        $centerId = trim($centerId);
        $userCenterId = trim($userCenterId);
        if ($centerId === '' || $userCenterId === '') {
            return null;
        }

        try {
            $tz = new DateTimeZone('Asia/Tehran');
            $now = new DateTimeImmutable('now', $tz);
        } catch (Throwable) {
            return null;
        }

        $fromTs = $now->getTimestamp();
        $toTs = $now->modify('+3 months')->getTimestamp();

        $baseUrl = rtrim(
            Config::get(
                'PAZIRESH24_BOOKING_SLOTS_URL',
                'https://apigw.paziresh24.com/open-platform/v1/booking/slots'
            ),
            '/'
        );
        $url = $baseUrl . '?' . http_build_query([
            'center_id' => $centerId,
            'user_center_id' => $userCenterId,
            'from' => (string) $fromTs,
            'to' => (string) $toTs,
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
                . ' center_id=' . $centerId
                . ' user_center_id=' . $userCenterId
                . ' body=' . $response['raw']
            );

            return null;
        }

        $body = is_array($response['body']) ? $response['body'] : null;

        $slot = self::extractFirstWorkhourTurnNum($body, $fromTs, $excludeRangeFrom, $excludeRangeTo);
        if ($slot !== null) {
            return $slot;
        }

        if ($excludeRangeTo !== null && $excludeRangeTo > $fromTs) {
            $slot = self::extractFirstWorkhourTurnNum($body, $excludeRangeTo, null, null);
            if ($slot !== null) {
                return $slot;
            }
        }

        return self::extractFirstWorkhourTurnNum($body, $fromTs, null, null);
    }

    /**
     * @param ?array<string, mixed> $body
     */
    public static function extractFirstWorkhourTurnNum(
        ?array $body,
        int $minTimestamp = 0,
        ?int $excludeRangeFrom = null,
        ?int $excludeRangeTo = null
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

        $appointment = GoogleCalendar::getAppointment($bookId, $accessToken);
        if (!is_array($appointment)) {
            return ['from' => $fallbackFrom, 'to' => $fallbackTo];
        }

        $range = GoogleCalendar::extractAppointmentRange($appointment);
        if ($range !== null) {
            return $range;
        }

        return ['from' => $fallbackFrom, 'to' => $fallbackTo];
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

            if ($key === 'data' || $key === 'slots' || $key === 'items' || $key === 'result') {
                self::collectSlotTimestamps($value, $candidates);
                continue;
            }

            if (!is_string($key) || !self::isUuid($key)) {
                self::collectSlotTimestamps($value, $candidates);
            }
        }
    }

    /**
     * @param array<string, mixed> $item
     */
    private static function extractSlotTimestampFromItem(array $item): ?int
    {
        foreach (['from', 'start_timestamp', 'book_timestamp'] as $key) {
            $ts = self::normalizeUnixTimestamp($item[$key] ?? null);
            if ($ts !== null) {
                return $ts;
            }
        }

        foreach (['workhour_turn_num', 'turn_num'] as $key) {
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
        foreach ([$medicalCenterId, $userCenterId] as $candidate) {
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

        $appointment = GoogleCalendar::getAppointment($bookId, $accessToken);
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
            $vacationTo
        );

        if ($targetFrom === null) {
            error_log(
                '[hamgam-appointment] reschedule failed stage=slots book_id='
                . $bookId
                . ' center=' . $medicalCenterId
                . ' user_center_id=' . $userCenterId
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
