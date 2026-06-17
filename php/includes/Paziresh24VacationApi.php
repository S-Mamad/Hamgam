<?php

declare(strict_types=1);

final class Paziresh24VacationApi
{
    /**
     * @return array<string, mixed>|null
     */
    public static function createVacation(
        string $accessToken,
        string $medicalCenterId,
        int $from,
        int $to,
        ?string $userCenterId = null
    ): ?array {
        $result = self::createVacationResult($accessToken, $medicalCenterId, $from, $to, $userCenterId);

        return $result['success'] ? $result['body'] : null;
    }

    /**
     * @return array{
     *   success: bool,
     *   body: ?array<string, mixed>,
     *   http_status: int,
     *   book_conflict: bool
     * }
     */
    public static function createVacationResult(
        string $accessToken,
        string $medicalCenterId,
        int $from,
        int $to,
        ?string $userCenterId = null
    ): array {
        $baseUrl = rtrim(
            Config::require('PAZIRESH24_VACATION_URL'),
            '/'
        );
        $url = $baseUrl . '/' . rawurlencode($medicalCenterId);

        $payload = [
            'from' => $from,
            'to' => $to,
        ];

        if (is_string($userCenterId) && trim($userCenterId) !== '') {
            $payload['user_center_id'] = trim($userCenterId);
        }

        $response = HttpClient::request(
            'POST',
            $url,
            [
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type' => 'application/json',
            ],
            $payload,
            'json'
        );

        $body = is_array($response['body']) ? $response['body'] : null;
        $bookConflict = self::isBookConflictResponse($response['status'], $body);

        if ($response['status'] >= 200 && $response['status'] < 300) {
            return [
                'success' => true,
                'body' => $body,
                'http_status' => $response['status'],
                'book_conflict' => false,
            ];
        }

        error_log(
            '[google-vacation] createVacation failed: HTTP ' . $response['status']
            . ' center=' . $medicalCenterId
            . ' from=' . $from
            . ' to=' . $to
            . ' body=' . $response['raw']
        );

        return [
            'success' => false,
            'body' => $body,
            'http_status' => $response['status'],
            'book_conflict' => $bookConflict,
        ];
    }

    /**
     * @param ?array<string, mixed> $body
     */
    public static function isBookConflictResponse(int $httpStatus, ?array $body): bool
    {
        if ($httpStatus === 409) {
            return true;
        }

        $status = is_string($body['status'] ?? null) ? strtoupper($body['status']) : '';

        return $status === 'BOOK_CONFLICT';
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function deleteVacation(
        string $accessToken,
        string $medicalCenterId,
        int $from,
        int $to
    ): ?array {
        $baseUrl = rtrim(
            Config::require('PAZIRESH24_VACATION_URL'),
            '/'
        );
        $url = $baseUrl . '/' . rawurlencode($medicalCenterId);

        $payload = [
            'from' => $from,
            'to' => $to,
        ];

        $response = HttpClient::request(
            'DELETE',
            $url,
            [
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type' => 'application/json',
            ],
            $payload,
            'json'
        );

        if ($response['status'] >= 200 && $response['status'] < 300) {
            return is_array($response['body']) ? $response['body'] : [];
        }

        if ($response['status'] === 404) {
            error_log(
                '[google-vacation] deleteVacation: vacation not found (idempotent) HTTP 404'
                . ' center=' . $medicalCenterId
                . ' from=' . $from
                . ' to=' . $to
            );

            return [];
        }

        error_log(
            '[google-vacation] deleteVacation failed: HTTP ' . $response['status']
            . ' center=' . $medicalCenterId
            . ' from=' . $from
            . ' to=' . $to
            . ' body=' . $response['raw']
        );

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function updateVacation(
        string $accessToken,
        string $medicalCenterId,
        int $from,
        int $to,
        int $oldFrom,
        int $oldTo
    ): ?array {
        $result = self::updateVacationResult(
            $accessToken,
            $medicalCenterId,
            $from,
            $to,
            $oldFrom,
            $oldTo
        );

        if ($result['success']) {
            return $result['body'];
        }

        if ($result['not_found']) {
            return [];
        }

        return null;
    }

    /**
     * @return array{
     *   success: bool,
     *   body: ?array<string, mixed>,
     *   http_status: int,
     *   book_conflict: bool,
     *   not_found: bool
     * }
     */
    public static function updateVacationResult(
        string $accessToken,
        string $medicalCenterId,
        int $from,
        int $to,
        int $oldFrom,
        int $oldTo
    ): array {
        $baseUrl = rtrim(
            Config::require('PAZIRESH24_VACATION_URL'),
            '/'
        );
        $url = $baseUrl . '/' . rawurlencode($medicalCenterId);

        $payload = [
            'from' => $from,
            'to' => $to,
            'old_from' => $oldFrom,
            'old_to' => $oldTo,
        ];

        $response = HttpClient::request(
            'PUT',
            $url,
            [
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type' => 'application/json',
            ],
            $payload,
            'json'
        );

        $body = is_array($response['body']) ? $response['body'] : null;
        $bookConflict = self::isBookConflictResponse($response['status'], $body);

        if ($response['status'] >= 200 && $response['status'] < 300) {
            return [
                'success' => true,
                'body' => $body ?? [],
                'http_status' => $response['status'],
                'book_conflict' => false,
                'not_found' => false,
            ];
        }

        if ($response['status'] === 404) {
            error_log(
                '[google-vacation] updateVacation: vacation not found (idempotent) HTTP 404'
                . ' center=' . $medicalCenterId
                . ' old_from=' . $oldFrom
                . ' old_to=' . $oldTo
                . ' from=' . $from
                . ' to=' . $to
            );

            return [
                'success' => false,
                'body' => null,
                'http_status' => 404,
                'book_conflict' => false,
                'not_found' => true,
            ];
        }

        error_log(
            '[google-vacation] updateVacation failed: HTTP ' . $response['status']
            . ' center=' . $medicalCenterId
            . ' old_from=' . $oldFrom
            . ' old_to=' . $oldTo
            . ' from=' . $from
            . ' to=' . $to
            . ' body=' . $response['raw']
        );

        return [
            'success' => false,
            'body' => $body,
            'http_status' => $response['status'],
            'book_conflict' => $bookConflict,
            'not_found' => false,
        ];
    }

    /**
     * Medical center UUID for POST .../vacations/{medical_center_id}.
     * Backward-compatible alias of resolveVacationCenter().
     */
    public static function resolveCenterId(string $accessToken): ?string
    {
        $center = self::resolveVacationCenter($accessToken);

        return $center['medical_center_id'] ?? null;
    }

    /**
     * @return array{medical_center_id: string, user_center_id: ?string}|null
     */
    public static function resolveVacationCenter(string $accessToken): ?array
    {
        $centers = self::listMedicalCenters($accessToken);
        if ($centers === null || $centers === []) {
            error_log('[google-vacation] no medical centers returned from API');
            return null;
        }

        $selected = self::pickMedicalCenter($centers);
        if ($selected === null) {
            error_log('[google-vacation] no usable medical center in API response');
            return null;
        }

        $medicalCenterId = self::resolveMedicalCenterId($selected);
        if ($medicalCenterId === null) {
            error_log('[google-vacation] medical center row missing id');
            return null;
        }

        $userCenterId = self::stringUuid($selected['user_center_id'] ?? null);

        error_log(
            '[google-vacation] vacation center resolved medical_center_id='
            . $medicalCenterId
            . ' user_center_id=' . ($userCenterId ?? 'null')
        );

        return [
            'medical_center_id' => $medicalCenterId,
            'user_center_id' => $userCenterId,
        ];
    }

    /**
     * @return array<int, array{
     *   medical_center_id: string,
     *   user_center_id: ?string,
     *   name: string,
     *   is_active_booking: bool
     * }>
     */
    public static function normalizeMedicalCenters(string $accessToken): array
    {
        $raw = self::listMedicalCenters($accessToken);
        if ($raw === null || $raw === []) {
            return [];
        }

        $normalized = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }

            $item = self::normalizeMedicalCenterRow($row);
            if ($item !== null) {
                $normalized[] = $item;
            }
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function flattenMedicalCenterRow(array $row): array
    {
        $flat = $row;

        foreach (['medical_center', 'center'] as $nestedKey) {
            if (!isset($row[$nestedKey]) || !is_array($row[$nestedKey])) {
                continue;
            }

            foreach ($row[$nestedKey] as $key => $value) {
                if (!array_key_exists($key, $flat) || $flat[$key] === null || $flat[$key] === '') {
                    $flat[$key] = $value;
                }
            }
        }

        return $flat;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{
     *   medical_center_id: string,
     *   user_center_id: ?string,
     *   name: string,
     *   is_active_booking: bool
     * }|null
     */
    public static function normalizeMedicalCenterRow(array $row): ?array
    {
        $flat = self::flattenMedicalCenterRow($row);
        $medicalCenterId = self::resolveMedicalCenterId($flat);
        if ($medicalCenterId === null) {
            return null;
        }

        $userCenterId = self::stringUuid($flat['user_center_id'] ?? null);
        $name = self::resolveCenterDisplayName($flat, $medicalCenterId);

        return [
            'medical_center_id' => $medicalCenterId,
            'user_center_id' => $userCenterId,
            'name' => $name,
            'is_active_booking' => self::isTruthy($flat['is_active_booking'] ?? false),
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function resolveMedicalCenterId(array $row): ?string
    {
        foreach (['id', 'medical_center_id', 'center_id', 'uuid'] as $key) {
            $id = self::stringMedicalCenterId($row[$key] ?? null);
            if ($id !== null) {
                return $id;
            }
        }

        foreach (['medical_center', 'center'] as $nestedKey) {
            if (!isset($row[$nestedKey]) || !is_array($row[$nestedKey])) {
                continue;
            }

            $id = self::resolveMedicalCenterId($row[$nestedKey]);
            if ($id !== null) {
                return $id;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function resolveCenterDisplayName(array $row, string $fallbackId): string
    {
        $candidates = [];

        foreach ([
            'name',
            'title',
            'center_name',
            'display_name',
            'medical_center_name',
            'local_name',
            'fa_name',
        ] as $key) {
            if (!isset($row[$key]) || !is_scalar($row[$key])) {
                continue;
            }

            $candidates[] = (string) $row[$key];
        }

        foreach (['medical_center', 'center'] as $nestedKey) {
            if (!isset($row[$nestedKey]) || !is_array($row[$nestedKey])) {
                continue;
            }

            foreach (['name', 'title', 'center_name', 'display_name', 'local_name', 'fa_name'] as $key) {
                if (!isset($row[$nestedKey][$key]) || !is_scalar($row[$nestedKey][$key])) {
                    continue;
                }

                $candidates[] = (string) $row[$nestedKey][$key];
            }
        }

        foreach ($candidates as $value) {
            $value = trim($value);
            if ($value !== '') {
                return $value;
            }
        }

        return 'مرکز ' . substr($fallbackId, 0, 8);
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    public static function listMedicalCenters(string $accessToken): ?array
    {
        $url = Config::get(
            'PAZIRESH24_MEDICAL_CENTERS_URL',
            self::defaultMedicalCentersUrl()
        );

        $response = HttpClient::request(
            'GET',
            $url,
            ['Authorization' => 'Bearer ' . $accessToken]
        );

        if ($response['status'] < 200 || $response['status'] >= 300) {
            error_log('[google-vacation] listMedicalCenters failed HTTP ' . $response['status']);
            return null;
        }

        $body = $response['body'];
        if (!is_array($body)) {
            return null;
        }

        $items = $body['data'] ?? null;
        if (!is_array($items)) {
            return null;
        }

        $centers = [];
        foreach ($items as $item) {
            if (is_array($item)) {
                $centers[] = $item;
            }
        }

        return $centers;
    }

    private static function defaultMedicalCentersUrl(): string
    {
        $vacationUrl = rtrim(Config::require('PAZIRESH24_VACATION_URL'), '/');

        if (str_ends_with($vacationUrl, '/vacations')) {
            return substr($vacationUrl, 0, -strlen('/vacations')) . '/medical-centers';
        }

        return 'https://apigw.paziresh24.com/open-platform/v1/booking/medical-centers';
    }

    /**
     * @param array<int, array<string, mixed>> $centers
     * @return array<string, mixed>|null
     */
    private static function pickMedicalCenter(array $centers): ?array
    {
        foreach ($centers as $center) {
            if (!is_array($center)) {
                continue;
            }

            if (self::isTruthy($center['is_active_booking'] ?? false)) {
                return $center;
            }
        }

        return $centers[0] ?? null;
    }

    private static function isTruthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));

            return in_array($normalized, ['1', 'true', 'yes'], true);
        }

        return false;
    }

    private static function stringUuid(mixed $value): ?string
    {
        if (!is_scalar($value) || (string) $value === '') {
            return null;
        }

        $value = (string) $value;

        return preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $value
        ) === 1 ? $value : null;
    }

    /** UUID or numeric id (e.g. online visit center type_id=3 → "5532"). */
    private static function stringMedicalCenterId(mixed $value): ?string
    {
        $uuid = self::stringUuid($value);
        if ($uuid !== null) {
            return $uuid;
        }

        if (!is_scalar($value) || (string) $value === '') {
            return null;
        }

        $value = trim((string) $value);

        return preg_match('/^\d+$/', $value) === 1 ? $value : null;
    }
}
