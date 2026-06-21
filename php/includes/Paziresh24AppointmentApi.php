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
     */
    public static function getFirstAvailableSlot(
        string $accessToken,
        string $centerId,
        string $userCenterId
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

        return self::extractFirstWorkhourTurnNum($body, $fromTs);
    }

    /**
     * @param ?array<string, mixed> $body
     */
    public static function extractFirstWorkhourTurnNum(?array $body, int $minTimestamp = 0): ?int
    {
        if ($body === null) {
            return null;
        }

        $candidates = [];
        self::collectWorkhourTurnNums($body, $candidates);

        if ($candidates === []) {
            return null;
        }

        sort($candidates, SORT_NUMERIC);

        foreach ($candidates as $candidate) {
            if ($candidate >= $minTimestamp) {
                return $candidate;
            }
        }

        return $candidates[0] ?? null;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<int, int> $candidates
     */
    private static function collectWorkhourTurnNums(array $node, array &$candidates): void
    {
        foreach ($node as $key => $value) {
            if ($key === 'workhour_turn_num' && is_numeric($value)) {
                $candidates[] = (int) $value;
                continue;
            }

            if (is_array($value)) {
                self::collectWorkhourTurnNums($value, $candidates);
            }
        }
    }
}
