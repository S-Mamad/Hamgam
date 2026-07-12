<?php

declare(strict_types=1);

final class GoogleCalendar
{
    /**
     * @return array<string, mixed>|null
     */
    public static function refreshAccessToken(string $refreshToken): ?array
    {
        $response = HttpClient::request(
            'POST',
            Config::get('GOOGLE_TOKEN_URL', 'https://oauth2.googleapis.com/token'),
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            [
                'client_id' => Config::require('GOOGLE_CLIENT_ID'),
                'client_secret' => Config::require('GOOGLE_CLIENT_SECRET'),
                'refresh_token' => $refreshToken,
                'grant_type' => 'refresh_token',
            ]
        );

        if ($response['status'] < 200 || $response['status'] >= 300) {
            $error = is_array($response['body']) ? ($response['body']['error'] ?? '') : '';
            if ($error === 'invalid_grant') {
                error_log(
                    '[GoogleCalendar] wrong google client for refresh_token — '
                    . 'GOOGLE_CLIENT_ID/SECRET must match the OAuth app that issued the token (n8n vs Hamgam)'
                );
            }
            error_log('[GoogleCalendar] refreshAccessToken failed: HTTP ' . $response['status'] . ' ' . $response['raw']);
            return null;
        }

        return $response['body'];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function getAppointment(string $bookId, string $hamdastAccessToken): ?array
    {
        $url = rtrim(Config::require('PAZIRESH24_APPOINTMENT_URL'), '/')
            . '/' . rawurlencode($bookId);

        $response = HttpClient::request(
            'GET',
            $url,
            ['Authorization' => 'Bearer ' . $hamdastAccessToken]
        );

        if ($response['status'] < 200 || $response['status'] >= 300) {
            error_log('[GoogleCalendar] getAppointment failed book_id=' . $bookId . ' HTTP ' . $response['status']);
            return null;
        }

        $body = $response['body'];
        if (!is_array($body)) {
            return null;
        }

        return self::normalizeAppointmentPayload($body);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private static function normalizeAppointmentPayload(array $payload): array
    {
        if (isset($payload['data']) && is_array($payload['data'])) {
            $payload = $payload['data'];
        }

        $bookId = $payload['book_id'] ?? $payload['id'] ?? null;
        if (is_scalar($bookId) && trim((string) $bookId) !== '') {
            $payload['book_id'] = trim((string) $bookId);
        }

        return $payload;
    }

    /**
     * @param array<string, mixed>|null $data
     * @return array{from: int, to: int}|null
     */
    public static function extractAppointmentRange(?array $data): ?array
    {
        if ($data === null) {
            return null;
        }

        return BookingAppointmentResolver::extractRangeFromPayload($data);
    }

    /**
     * @param array<string, mixed> $event
     */
    public static function createEvent(string $accessToken, array $event): bool
    {
        return self::createEventReturningBody($accessToken, $event) !== null;
    }

    /**
     * @param array<string, mixed> $event
     * @return array<string, mixed>|null
     */
    public static function createEventReturningBody(string $accessToken, array $event): ?array
    {
        $url = Config::get(
            'GOOGLE_CALENDAR_EVENTS_URL',
            'https://www.googleapis.com/calendar/v3/calendars/primary/events'
        );

        $response = HttpClient::request(
            'POST',
            $url,
            [
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type' => 'application/json',
            ],
            $event,
            'json'
        );

        if ($response['status'] >= 200 && $response['status'] < 300 && is_array($response['body'])) {
            return $response['body'];
        }

        error_log('[GoogleCalendar] createEvent failed HTTP ' . $response['status'] . ' ' . $response['raw']);

        return null;
    }

    public static function deleteEvent(string $accessToken, string $eventId): bool
    {
        $baseUrl = Config::get(
            'GOOGLE_CALENDAR_EVENTS_URL',
            'https://www.googleapis.com/calendar/v3/calendars/primary/events'
        );
        $url = rtrim($baseUrl, '/') . '/' . rawurlencode($eventId);

        $response = HttpClient::request(
            'DELETE',
            $url,
            ['Authorization' => 'Bearer ' . $accessToken]
        );

        return ($response['status'] >= 200 && $response['status'] < 300)
            || $response['status'] === 404
            || $response['status'] === 410;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function updateEventReturningBody(string $accessToken, string $eventId, array $event): ?array
    {
        $baseUrl = Config::get(
            'GOOGLE_CALENDAR_EVENTS_URL',
            'https://www.googleapis.com/calendar/v3/calendars/primary/events'
        );
        $url = rtrim($baseUrl, '/') . '/' . rawurlencode($eventId);

        $response = HttpClient::request(
            'PATCH',
            $url,
            [
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type' => 'application/json',
            ],
            $event,
            'json'
        );

        if ($response['status'] >= 200 && $response['status'] < 300 && is_array($response['body'])) {
            return $response['body'];
        }

        error_log(
            '[GoogleCalendar] updateEvent failed event_id=' . $eventId
            . ' HTTP ' . $response['status'] . ' ' . $response['raw']
        );

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function findEventsByBookId(string $accessToken, string $bookId): array
    {
        $bookId = trim($bookId);
        if ($bookId === '') {
            return [];
        }

        try {
            $tz = new DateTimeZone('Asia/Tehran');
            $now = new DateTimeImmutable('now', $tz);
            $timeMin = $now->modify('-90 days')->format('Y-m-d\TH:i:s\P');
            $timeMax = $now->modify('+365 days')->format('Y-m-d\TH:i:s\P');
        } catch (Throwable) {
            $timeMin = null;
            $timeMax = null;
        }

        $events = self::listEventsByExtendedProperty(
            $accessToken,
            'hamgam_book_id=' . $bookId,
            $timeMin,
            $timeMax
        );

        if ($events === [] && strtolower($bookId) !== $bookId) {
            $events = self::listEventsByExtendedProperty(
                $accessToken,
                'hamgam_book_id=' . strtolower($bookId),
                $timeMin,
                $timeMax
            );
        }

        return $events;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function listEventsByExtendedProperty(
        string $accessToken,
        string $propertyFilter,
        ?string $timeMin = null,
        ?string $timeMax = null
    ): array {
        $baseUrl = Config::get(
            'GOOGLE_CALENDAR_EVENTS_LIST_URL',
            'https://www.googleapis.com/calendar/v3/calendars/primary/events'
        );

        $events = [];
        $pageToken = null;

        do {
            $query = [
                'maxResults' => 250,
                'singleEvents' => 'true',
                'privateExtendedProperty' => $propertyFilter,
            ];

            if ($timeMin !== null && $timeMin !== '') {
                $query['timeMin'] = $timeMin;
            }

            if ($timeMax !== null && $timeMax !== '') {
                $query['timeMax'] = $timeMax;
            }

            if ($pageToken !== null) {
                $query['pageToken'] = $pageToken;
            }

            $url = $baseUrl . '?' . http_build_query($query);
            $response = HttpClient::request(
                'GET',
                $url,
                ['Authorization' => 'Bearer ' . $accessToken]
            );

            if ($response['status'] < 200 || $response['status'] >= 300) {
                error_log(
                    '[GoogleCalendar] listEventsByExtendedProperty failed HTTP '
                    . $response['status'] . ' filter=' . $propertyFilter
                );
                break;
            }

            $body = $response['body'] ?? [];
            $items = $body['items'] ?? [];
            if (is_array($items)) {
                foreach ($items as $item) {
                    if (is_array($item)) {
                        $events[] = $item;
                    }
                }
            }

            $pageToken = isset($body['nextPageToken']) && is_string($body['nextPageToken'])
                ? $body['nextPageToken']
                : null;
        } while ($pageToken !== null);

        return $events;
    }

    public static function resolveAccountEmail(string $accessToken): ?string
    {
        $userinfoUrl = Config::get(
            'GOOGLE_OAUTH_USERINFO_URL',
            'https://www.googleapis.com/oauth2/v3/userinfo'
        );

        $userinfoResponse = HttpClient::request(
            'GET',
            $userinfoUrl,
            ['Authorization' => 'Bearer ' . $accessToken]
        );

        if ($userinfoResponse['status'] >= 200 && $userinfoResponse['status'] < 300) {
            $body = $userinfoResponse['body'];
            if (is_array($body)) {
                $email = $body['email'] ?? '';
                if (is_string($email) && trim($email) !== '' && filter_var(trim($email), FILTER_VALIDATE_EMAIL)) {
                    return trim($email);
                }
            }
        }

        $calendarUrl = Config::get(
            'GOOGLE_CALENDAR_PRIMARY_URL',
            'https://www.googleapis.com/calendar/v3/calendars/primary'
        );

        $calendarResponse = HttpClient::request(
            'GET',
            $calendarUrl,
            ['Authorization' => 'Bearer ' . $accessToken]
        );

        if ($calendarResponse['status'] >= 200 && $calendarResponse['status'] < 300) {
            $body = $calendarResponse['body'];
            if (is_array($body)) {
                $calendarId = $body['id'] ?? '';
                if (is_string($calendarId) && str_contains($calendarId, '@') && filter_var($calendarId, FILTER_VALIDATE_EMAIL)) {
                    return trim($calendarId);
                }
            }
        }

        $url = Config::get(
            'GOOGLE_CALENDAR_EVENTS_LIST_URL',
            'https://www.googleapis.com/calendar/v3/calendars/primary/events'
        ) . '?maxResults=1&singleEvents=true&orderBy=startTime';

        $response = HttpClient::request(
            'GET',
            $url,
            ['Authorization' => 'Bearer ' . $accessToken]
        );

        if ($response['status'] < 200 || $response['status'] >= 300) {
            return null;
        }

        $body = $response['body'];
        if (!is_array($body)) {
            return null;
        }

        $items = $body['items'] ?? null;
        if (!is_array($items) || !isset($items[0]) || !is_array($items[0])) {
            return null;
        }

        $organizer = $items[0]['organizer'] ?? null;
        if (!is_array($organizer)) {
            return null;
        }

        $email = $organizer['email'] ?? '';
        if (!is_string($email) || trim($email) === '') {
            return null;
        }

        return trim($email);
    }
}
