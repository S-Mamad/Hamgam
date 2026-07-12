<?php

declare(strict_types=1);

final class GoogleCalendarWatch
{
    /**
     * @return array<string, mixed>|null
     */
    public static function registerWatch(string $accessToken, string $webhookUrl, string $channelId): ?array
    {
        $url = Config::get(
            'GOOGLE_CALENDAR_WATCH_URL',
            'https://www.googleapis.com/calendar/v3/calendars/primary/events/watch'
        );

        $response = HttpClient::request(
            'POST',
            $url,
            [
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type' => 'application/json',
            ],
            [
                'id' => $channelId,
                'type' => 'web_hook',
                'address' => $webhookUrl,
            ],
            'json'
        );

        if ($response['status'] < 200 || $response['status'] >= 300) {
            error_log('[google-vacation] registerWatch failed: HTTP ' . $response['status'] . ' ' . $response['raw']);
            return null;
        }

        return $response['body'];
    }

    public static function stopWatch(string $accessToken, string $channelId, string $resourceId): bool
    {
        $url = Config::get(
            'GOOGLE_CALENDAR_CHANNELS_STOP_URL',
            'https://www.googleapis.com/calendar/v3/channels/stop'
        );

        $response = HttpClient::request(
            'POST',
            $url,
            [
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type' => 'application/json',
            ],
            [
                'id' => $channelId,
                'resourceId' => $resourceId,
            ],
            'json'
        );

        return $response['status'] >= 200 && $response['status'] < 300;
    }

    /**
     * @param ?string $timeMin RFC3339 lower bound applied only on the initial listing
     *                         (no syncToken). Keeps the first scan bounded so large
     *                         calendars don't time out before a syncToken is captured.
     * @return array{events: array<int, array<string, mixed>>, nextSyncToken: ?string}
     */
    public static function listChangedEvents(string $accessToken, ?string $syncToken, ?string $timeMin = null): array
    {
        $baseUrl = Config::get(
            'GOOGLE_CALENDAR_EVENTS_LIST_URL',
            'https://www.googleapis.com/calendar/v3/calendars/primary/events'
        );

        $hasSyncToken = $syncToken !== null && $syncToken !== '';

        $events = [];
        $pageToken = null;
        $nextSyncToken = null;

        do {
            $query = [
                'maxResults' => 250,
                'singleEvents' => 'true',
                'showDeleted' => 'true',
            ];

            if ($hasSyncToken) {
                $query['syncToken'] = $syncToken;
            } elseif ($timeMin !== null && $timeMin !== '') {
                // timeMin cannot be combined with syncToken; only used for the initial scan.
                $query['timeMin'] = $timeMin;
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

            if ($response['status'] === 410) {
                error_log('[google-vacation] syncToken expired (410), full resync needed');
                return self::listChangedEvents($accessToken, null, $timeMin);
            }

            if ($response['status'] < 200 || $response['status'] >= 300) {
                error_log('[google-vacation] listChangedEvents failed: HTTP ' . $response['status']);
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

            if (isset($body['nextSyncToken']) && is_string($body['nextSyncToken']) && $body['nextSyncToken'] !== '') {
                $nextSyncToken = $body['nextSyncToken'];
            }
        } while ($pageToken !== null);

        return [
            'events' => $events,
            'nextSyncToken' => $nextSyncToken,
        ];
    }

    /**
     * Establish an incremental sync token without touching watch registration.
     * Lists only a bounded recent window; returned events are discarded.
     */
    public static function captureInitialSyncToken(string $accessToken, int $lookbackDays = 2): ?string
    {
        if ($lookbackDays < 1) {
            $lookbackDays = 1;
        }

        $timeMin = gmdate('Y-m-d\TH:i:s\Z', time() - ($lookbackDays * 86400));
        $result = self::listChangedEvents($accessToken, null, $timeMin);
        $syncToken = $result['nextSyncToken'];

        return is_string($syncToken) && $syncToken !== '' ? $syncToken : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function listEventsInRange(string $accessToken, string $timeMin, string $timeMax): array
    {
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
                'orderBy' => 'startTime',
                'timeMin' => $timeMin,
                'timeMax' => $timeMax,
            ];

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
                error_log('[google-vacation] listEventsInRange failed: HTTP ' . $response['status']);
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

    /**
     * @return array<string, mixed>|null
     */
    public static function getEvent(string $accessToken, string $eventId): ?array
    {
        $calendarId = rawurlencode('primary');
        $encodedEventId = rawurlencode($eventId);
        $url = Config::get(
            'GOOGLE_CALENDAR_EVENT_GET_URL',
            'https://www.googleapis.com/calendar/v3/calendars/' . $calendarId . '/events/' . $encodedEventId
        );

        $response = HttpClient::request(
            'GET',
            $url,
            ['Authorization' => 'Bearer ' . $accessToken]
        );

        if ($response['status'] === 404) {
            return null;
        }

        if ($response['status'] < 200 || $response['status'] >= 300) {
            error_log(
                '[google-vacation] getEvent failed: HTTP '
                . $response['status']
                . ' event=' . $eventId
            );

            return null;
        }

        $body = $response['body'] ?? null;

        return is_array($body) ? $body : null;
    }

    /**
     * Expanded instances of a recurring master event.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function listRecurringEventInstances(
        string $accessToken,
        string $recurringEventId,
        string $timeMin,
        string $timeMax
    ): array {
        $calendarId = rawurlencode('primary');
        $eventId = rawurlencode($recurringEventId);
        $baseUrl = Config::get(
            'GOOGLE_CALENDAR_EVENTS_INSTANCES_URL',
            'https://www.googleapis.com/calendar/v3/calendars/' . $calendarId . '/events/' . $eventId . '/instances'
        );

        $events = [];
        $pageToken = null;

        do {
            $query = [
                'maxResults' => 250,
                'singleEvents' => 'true',
                'orderBy' => 'startTime',
                'timeMin' => $timeMin,
                'timeMax' => $timeMax,
            ];

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
                    '[google-vacation] listRecurringEventInstances failed: HTTP '
                    . $response['status']
                    . ' event=' . $recurringEventId
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

    public static function establishSyncToken(string $accessToken): ?string
    {
        $baseUrl = Config::get(
            'GOOGLE_CALENDAR_EVENTS_LIST_URL',
            'https://www.googleapis.com/calendar/v3/calendars/primary/events'
        );

        $pageToken = null;
        $nextSyncToken = null;

        do {
            $query = [
                'maxResults' => 250,
                'singleEvents' => 'true',
            ];

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
                error_log('[google-vacation] establishSyncToken failed: HTTP ' . $response['status'] . ' ' . $response['raw']);
                return null;
            }

            $body = $response['body'] ?? [];
            $pageToken = isset($body['nextPageToken']) && is_string($body['nextPageToken'])
                ? $body['nextPageToken']
                : null;

            if (isset($body['nextSyncToken']) && is_string($body['nextSyncToken']) && $body['nextSyncToken'] !== '') {
                $nextSyncToken = $body['nextSyncToken'];
            }
        } while ($pageToken !== null);

        return $nextSyncToken;
    }

    public static function generateChannelId(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
