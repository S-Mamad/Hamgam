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
}
