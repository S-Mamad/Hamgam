<?php



declare(strict_types=1);



final class GoogleVacationRepository

{

    public static function findByChannelId(string $channelId): ?array

    {

        $stmt = Database::connection()->prepare(

            'SELECT * FROM google_tokens WHERE google_channel_id = :channel_id LIMIT 1'

        );

        $stmt->execute(['channel_id' => $channelId]);

        $row = $stmt->fetch();



        return $row === false ? null : $row;

    }



    public static function findByResourceId(string $resourceId): ?array

    {

        $stmt = Database::connection()->prepare(

            'SELECT * FROM google_tokens WHERE google_resource_id = :resource_id LIMIT 1'

        );

        $stmt->execute(['resource_id' => $resourceId]);

        $row = $stmt->fetch();



        return $row === false ? null : $row;

    }



    public static function clearWatchData(string $userId): void

    {

        $userId = GoogleTokensRepository::normalizeUserId($userId);

        $stmt = Database::connection()->prepare(

            'UPDATE google_tokens SET

                google_channel_id = NULL,

                google_resource_id = NULL,

                google_watch_expiration = NULL,

                google_sync_token = NULL,

                updated_at = CURRENT_TIMESTAMP

             WHERE paziresh24_user_id = :user_id'

        );

        $stmt->execute(['user_id' => $userId]);

    }



    public static function clearProcessedEvents(string $userId): void

    {

        $userId = GoogleTokensRepository::normalizeUserId($userId);

        $stmt = Database::connection()->prepare(

            'DELETE FROM google_event_vacations WHERE paziresh24_user_id = :user_id'

        );

        $stmt->execute(['user_id' => $userId]);

    }



    public static function saveWatchData(

        string $userId,

        string $channelId,

        string $resourceId,

        int $expirationMs,

        ?string $syncToken = null

    ): void {

        $stmt = Database::connection()->prepare(

            'UPDATE google_tokens SET

                google_channel_id = :channel_id,

                google_resource_id = :resource_id,

                google_watch_expiration = :expiration,

                google_sync_token = COALESCE(:sync_token, google_sync_token),

                updated_at = CURRENT_TIMESTAMP

             WHERE paziresh24_user_id = :user_id'

        );



        $stmt->execute([

            'user_id' => $userId,

            'channel_id' => $channelId,

            'resource_id' => $resourceId,

            'expiration' => $expirationMs,

            'sync_token' => $syncToken,

        ]);

    }



    public static function saveSyncToken(string $userId, string $syncToken): void

    {

        $stmt = Database::connection()->prepare(

            'UPDATE google_tokens SET

                google_sync_token = :sync_token,

                updated_at = CURRENT_TIMESTAMP

             WHERE paziresh24_user_id = :user_id'

        );

        $stmt->execute([

            'user_id' => $userId,

            'sync_token' => $syncToken,

        ]);

    }



    /**

     * Saves medical center UUID for vacation API (GET /booking/medical-centers → data[].id).

     */

    public static function saveCenterId(string $userId, string $centerId): void

    {

        $stmt = Database::connection()->prepare(

            'UPDATE google_tokens SET

                center_id = :center_id,

                updated_at = CURRENT_TIMESTAMP

             WHERE paziresh24_user_id = :user_id'

        );

        $stmt->execute([

            'user_id' => $userId,

            'center_id' => $centerId,

        ]);

    }



    public static function isAutoVacationEnabled(?array $row): bool

    {

        if ($row === null) {

            return false;

        }



        return GoogleTokensRepository::toBoolPublic($row['auto_vacation'] ?? false);

    }



    public static function hasProcessedEvent(string $userId, string $eventId): bool

    {

        $stmt = Database::connection()->prepare(

            'SELECT id FROM google_event_vacations

             WHERE paziresh24_user_id = :user_id AND google_event_id = :event_id

             LIMIT 1'

        );

        $stmt->execute([

            'user_id' => $userId,

            'event_id' => $eventId,

        ]);



        return $stmt->fetch() !== false;

    }



    public static function hasProcessedRecurringSeries(string $userId, string $seriesKey): bool

    {

        if (self::hasProcessedEvent($userId, $seriesKey)) {

            return true;

        }



        return self::findProcessedEventsRelatedToGoogleEvent($userId, $seriesKey) !== [];

    }



    /**

     * @return array<int, array<string, mixed>>

     */

    public static function findProcessedEventsForGoogleEvent(string $userId, string $eventId): array

    {

        $stmt = Database::connection()->prepare(

            'SELECT * FROM google_event_vacations

             WHERE paziresh24_user_id = :user_id AND google_event_id = :event_id

             ORDER BY id ASC'

        );

        $stmt->execute([

            'user_id' => $userId,

            'event_id' => $eventId,

        ]);

        $rows = $stmt->fetchAll();



        return is_array($rows) ? $rows : [];

    }



    /**

     * Resolves tracked vacation rows for a deleted Google event, including recurring instance ids.

     *

     * @return array<int, array<string, mixed>>

     */

    public static function findProcessedEventsRelatedToGoogleEvent(string $userId, string $eventId): array

    {

        $exact = self::findProcessedEventsForGoogleEvent($userId, $eventId);

        if ($exact !== []) {

            return $exact;

        }



        $baseId = $eventId;

        if (preg_match('/^(.+)_\d{8}T\d{6}Z$/', $eventId, $matches) === 1) {

            $baseId = $matches[1];

        }



        $stmt = Database::connection()->prepare(

            'SELECT * FROM google_event_vacations

             WHERE paziresh24_user_id = :user_id

               AND (google_event_id = :base_id OR google_event_id LIKE :instance_prefix)

             ORDER BY id ASC'

        );

        $stmt->execute([

            'user_id' => $userId,

            'base_id' => $baseId,

            'instance_prefix' => $baseId . '_%',

        ]);

        $rows = $stmt->fetchAll();



        return is_array($rows) ? $rows : [];

    }



    public static function findProcessedEvent(

        string $userId,

        string $eventId,

        ?string $medicalCenterId = null

    ): ?array {

        if ($medicalCenterId !== null && $medicalCenterId !== '') {

            $stmt = Database::connection()->prepare(

                'SELECT * FROM google_event_vacations

                 WHERE paziresh24_user_id = :user_id

                   AND google_event_id = :event_id

                   AND medical_center_id = :medical_center_id

                 LIMIT 1'

            );

            $stmt->execute([

                'user_id' => $userId,

                'event_id' => $eventId,

                'medical_center_id' => $medicalCenterId,

            ]);

            $row = $stmt->fetch();



            return $row === false ? null : $row;

        }



        $rows = self::findProcessedEventsForGoogleEvent($userId, $eventId);



        return $rows[0] ?? null;

    }



    public static function removeProcessedEvent(

        string $userId,

        string $eventId,

        ?string $medicalCenterId = null

    ): void {

        if ($medicalCenterId !== null && $medicalCenterId !== '') {

            $stmt = Database::connection()->prepare(

                'DELETE FROM google_event_vacations

                 WHERE paziresh24_user_id = :user_id

                   AND google_event_id = :event_id

                   AND medical_center_id = :medical_center_id'

            );

            $stmt->execute([

                'user_id' => $userId,

                'event_id' => $eventId,

                'medical_center_id' => $medicalCenterId,

            ]);



            return;

        }



        $stmt = Database::connection()->prepare(

            'DELETE FROM google_event_vacations

             WHERE paziresh24_user_id = :user_id AND google_event_id = :event_id'

        );

        $stmt->execute([

            'user_id' => $userId,

            'event_id' => $eventId,

        ]);

    }



    public static function removeProcessedEventsByTimeSlot(

        string $userId,

        string $medicalCenterId,

        int $vacationFrom,

        int $vacationTo

    ): void {

        $medicalCenterId = trim($medicalCenterId);

        if ($medicalCenterId === '' || $vacationFrom <= 0 || $vacationTo <= 0) {

            return;

        }



        $stmt = Database::connection()->prepare(

            'DELETE FROM google_event_vacations

             WHERE paziresh24_user_id = :user_id

               AND medical_center_id = :medical_center_id

               AND vacation_from = :vacation_from

               AND vacation_to = :vacation_to'

        );

        $stmt->execute([

            'user_id' => $userId,

            'medical_center_id' => $medicalCenterId,

            'vacation_from' => $vacationFrom,

            'vacation_to' => $vacationTo,

        ]);

    }

    /**
     * @return array<int, array{
     *   google_event_id: string,
     *   medical_center_id: string,
     *   vacation_from: int,
     *   vacation_to: int
     * }>
     */
    public static function listTrackedVacationDeletionTargets(string $userId): array
    {
        $userId = GoogleTokensRepository::normalizeUserId($userId);
        if ($userId === '') {
            return [];
        }

        $stmt = Database::connection()->prepare(
            'SELECT google_event_id, medical_center_id, vacation_from, vacation_to
             FROM google_event_vacations
             WHERE paziresh24_user_id = :user_id
               AND vacation_from > 0
               AND vacation_to > vacation_from
             ORDER BY vacation_from ASC, id ASC'
        );
        $stmt->execute(['user_id' => $userId]);
        $rows = $stmt->fetchAll();

        if (!is_array($rows)) {
            return [];
        }

        $targets = [];
        $seen = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $medicalCenterId = isset($row['medical_center_id']) && is_string($row['medical_center_id'])
                ? trim($row['medical_center_id'])
                : '';
            $vacationFrom = (int) ($row['vacation_from'] ?? 0);
            $vacationTo = (int) ($row['vacation_to'] ?? 0);
            $eventId = is_string($row['google_event_id'] ?? null) ? trim($row['google_event_id']) : '';

            if ($medicalCenterId === '' || $vacationFrom <= 0 || $vacationTo <= $vacationFrom) {
                continue;
            }

            $dedupeKey = $medicalCenterId . '|' . $vacationFrom . '|' . $vacationTo;
            if (isset($seen[$dedupeKey])) {
                continue;
            }

            $seen[$dedupeKey] = true;
            $targets[] = [
                'google_event_id' => $eventId,
                'medical_center_id' => $medicalCenterId,
                'vacation_from' => $vacationFrom,
                'vacation_to' => $vacationTo,
            ];
        }

        return $targets;
    }

    public static function countTrackedVacations(string $userId): int
    {
        $userId = GoogleTokensRepository::normalizeUserId($userId);
        if ($userId === '') {
            return 0;
        }

        $stmt = Database::connection()->prepare(
            'SELECT COUNT(*) FROM google_event_vacations
             WHERE paziresh24_user_id = :user_id
               AND vacation_from > 0
               AND vacation_to > vacation_from'
        );
        $stmt->execute(['user_id' => $userId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Every tracked Google event row that maps to a Paziresh24 vacation delete call.
     *
     * @return array<int, array{
     *   google_event_id: string,
     *   medical_center_id: string,
     *   vacation_from: int,
     *   vacation_to: int
     * }>
     */
    public static function listAllTrackedVacationsForDeletion(string $userId, ?string $fallbackCenterId = null): array
    {
        $userId = GoogleTokensRepository::normalizeUserId($userId);
        if ($userId === '') {
            return [];
        }

        $stmt = Database::connection()->prepare(
            'SELECT google_event_id, medical_center_id, vacation_from, vacation_to, paziresh24_response
             FROM google_event_vacations
             WHERE paziresh24_user_id = :user_id
               AND vacation_from > 0
               AND vacation_to > vacation_from
             ORDER BY vacation_from ASC, id ASC'
        );
        $stmt->execute(['user_id' => $userId]);
        $rows = $stmt->fetchAll();

        if (!is_array($rows)) {
            return [];
        }

        $targets = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $medicalCenterId = self::resolveTrackedMedicalCenterId($row, $fallbackCenterId);
            $vacationFrom = (int) ($row['vacation_from'] ?? 0);
            $vacationTo = (int) ($row['vacation_to'] ?? 0);
            $eventId = is_string($row['google_event_id'] ?? null) ? trim($row['google_event_id']) : '';

            if ($medicalCenterId === '' || $vacationFrom <= 0 || $vacationTo <= $vacationFrom) {
                continue;
            }

            $targets[] = [
                'google_event_id' => $eventId,
                'medical_center_id' => $medicalCenterId,
                'vacation_from' => $vacationFrom,
                'vacation_to' => $vacationTo,
            ];
        }

        return $targets;
    }

    /**

     * @param array<string, mixed>|null $response

     */

    public static function recordProcessedEvent(

        string $userId,

        string $eventId,

        string $summary,

        int $from,

        int $to,

        ?array $response,

        string $medicalCenterId = ''

    ): void {

        $driver = Config::get('DB_DRIVER', 'sqlite');

        $responseJson = $response !== null

            ? json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)

            : null;

        $medicalCenterId = trim($medicalCenterId);



        if ($driver === 'mysql') {

            $stmt = Database::connection()->prepare(

                'INSERT INTO google_event_vacations (

                    paziresh24_user_id,

                    google_event_id,

                    medical_center_id,

                    event_summary,

                    vacation_from,

                    vacation_to,

                    paziresh24_response

                ) VALUES (

                    :user_id,

                    :event_id,

                    :medical_center_id,

                    :summary,

                    :vacation_from,

                    :vacation_to,

                    :response

                )

                ON DUPLICATE KEY UPDATE

                    event_summary = VALUES(event_summary),

                    vacation_from = VALUES(vacation_from),

                    vacation_to = VALUES(vacation_to),

                    paziresh24_response = VALUES(paziresh24_response)'

            );

        } else {

            $stmt = Database::connection()->prepare(

                'INSERT INTO google_event_vacations (

                    paziresh24_user_id,

                    google_event_id,

                    medical_center_id,

                    event_summary,

                    vacation_from,

                    vacation_to,

                    paziresh24_response

                ) VALUES (

                    :user_id,

                    :event_id,

                    :medical_center_id,

                    :summary,

                    :vacation_from,

                    :vacation_to,

                    :response

                )

                ON CONFLICT(paziresh24_user_id, google_event_id, medical_center_id) DO UPDATE SET

                    event_summary = excluded.event_summary,

                    vacation_from = excluded.vacation_from,

                    vacation_to = excluded.vacation_to,

                    paziresh24_response = excluded.paziresh24_response'

            );

        }



        $stmt->execute([

            'user_id' => $userId,

            'event_id' => $eventId,

            'medical_center_id' => $medicalCenterId,

            'summary' => $summary,

            'vacation_from' => $from,

            'vacation_to' => $to,

            'response' => $responseJson,

        ]);

    }



    /**

     * @return array<int, array<string, mixed>>

     */

    public static function findUsersNeedingWatchRegistration(): array

    {

        $stmt = Database::connection()->prepare(

            'SELECT * FROM google_tokens

             WHERE google_refresh_token IS NOT NULL

               AND google_refresh_token != \'\'

               AND (

                    google_channel_id IS NULL

                    OR google_channel_id = \'\'

                    OR google_watch_expiration IS NULL

               )'

        );

        $stmt->execute();

        $rows = $stmt->fetchAll();



        return is_array($rows) ? $rows : [];

    }



    /**

     * @return array<int, array<string, mixed>>

     */

    public static function findExpiringWatches(int $withinMs = 86400000): array

    {

        $threshold = (int) (microtime(true) * 1000) + $withinMs;



        $stmt = Database::connection()->prepare(

            'SELECT * FROM google_tokens

             WHERE google_refresh_token IS NOT NULL

               AND google_refresh_token != \'\'

               AND google_watch_expiration IS NOT NULL

               AND google_watch_expiration <= :threshold'

        );

        $stmt->execute(['threshold' => $threshold]);

        $rows = $stmt->fetchAll();



        return is_array($rows) ? $rows : [];

    }



    public static function resolveTrackedMedicalCenterId(array $tracked, ?string $fallbackCenterId = null): string

    {

        $stored = isset($tracked['medical_center_id']) && is_string($tracked['medical_center_id'])

            ? trim($tracked['medical_center_id'])

            : '';



        if ($stored !== '') {

            return $stored;

        }



        if (is_string($fallbackCenterId) && trim($fallbackCenterId) !== '') {

            return trim($fallbackCenterId);

        }



        return '';

    }

}


