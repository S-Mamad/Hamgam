<?php



declare(strict_types=1);



if (!class_exists('Config', false)) {
    require_once __DIR__ . '/../includes/bootstrap.php';
}



final class VacationSyncService

{

    public static function handleNotification(string $channelId, string $resourceId, string $resourceState): void

    {

        error_log('[google-vacation] webhook received channel=' . $channelId . ' state=' . $resourceState);



        if ($resourceState === 'sync') {

            return;

        }



        $tokenRow = GoogleVacationRepository::findByChannelId($channelId);

        if ($tokenRow === null) {

            $tokenRow = GoogleVacationRepository::findByResourceId($resourceId);

        }



        if ($tokenRow === null) {

            error_log('[google-vacation] doctor not found for channel=' . $channelId);

            return;

        }



        $userId = (string) ($tokenRow['paziresh24_user_id'] ?? '');

        $refreshToken = (string) ($tokenRow['google_refresh_token'] ?? '');

        $hamdastAccessToken = (string) ($tokenRow['hamdast_access_token'] ?? '');

        $syncToken = isset($tokenRow['google_sync_token']) && is_string($tokenRow['google_sync_token'])

            ? $tokenRow['google_sync_token']

            : null;



        if ($refreshToken === '') {

            error_log('[google-vacation] missing refresh token for user ' . $userId);

            return;

        }



        $googleTokenData = GoogleCalendar::refreshAccessToken($refreshToken);

        $googleAccessToken = is_array($googleTokenData) ? ($googleTokenData['access_token'] ?? '') : '';

        if (!is_string($googleAccessToken) || $googleAccessToken === '') {

            error_log('[google-vacation] token refresh failed for user ' . $userId);

            return;

        }



        $listResult = GoogleCalendarWatch::listChangedEvents($googleAccessToken, $syncToken);

        $events = $listResult['events'];



        if ($listResult['nextSyncToken'] !== null) {

            GoogleVacationRepository::saveSyncToken($userId, $listResult['nextSyncToken']);

        }



        $autoVacation = GoogleVacationRepository::isAutoVacationEnabled($tokenRow);

        $vacationCenters = self::resolveVacationCentersForUser($userId, $tokenRow, $hamdastAccessToken);



        foreach ($events as $event) {

            if (!is_array($event)) {

                continue;

            }



            self::syncSingleEvent($userId, $tokenRow, $event, $autoVacation, $vacationCenters, $hamdastAccessToken);

        }

    }



    /**

     * @return array{ran: bool, imported: int, skipped: int, failed: int}

     */

    public static function runFutureEventsBackfill(

        string $userId,

        string $hamdastAccessToken,

        bool $forceRun = false

    ): array {

        $empty = ['ran' => false, 'imported' => 0, 'skipped' => 0, 'failed' => 0];



        $tokenRow = GoogleTokensRepository::findByUserId($userId);

        if ($tokenRow === null) {

            return $empty;

        }



        if (!GoogleVacationRepository::isAutoVacationEnabled($tokenRow)) {

            return $empty;

        }



        if (!GoogleTokensRepository::toBoolPublic($tokenRow['import_future_vacations'] ?? false)) {

            return $empty;

        }



        if (!$forceRun && !GoogleTokensRepository::shouldRunFutureVacationsBackfill($tokenRow)) {

            return $empty;

        }



        $refreshToken = (string) ($tokenRow['google_refresh_token'] ?? '');

        if ($refreshToken === '') {

            error_log('[google-vacation] backfill skipped: missing refresh token user=' . $userId);

            return $empty;

        }



        $now = time();

        $windowEnd = $now + (30 * 86400);

        GoogleTokensRepository::saveImportBackfillWindowEnd($userId, $windowEnd);



        $googleTokenData = GoogleCalendar::refreshAccessToken($refreshToken);

        $googleAccessToken = is_array($googleTokenData) ? ($googleTokenData['access_token'] ?? '') : '';

        if (!is_string($googleAccessToken) || $googleAccessToken === '') {

            error_log('[google-vacation] backfill token refresh failed user=' . $userId);

            return $empty;

        }

        GoogleTokensRepository::updateSyncProgress($userId, [
            'phase' => 'fetching',
            'processed' => 0,
            'total' => 0,
            'percent' => 8,
        ]);



        $timeMin = gmdate('Y-m-d\TH:i:s\Z', $now);

        $timeMax = gmdate('Y-m-d\TH:i:s\Z', $windowEnd);



        $events = GoogleCalendarWatch::listEventsInRange($googleAccessToken, $timeMin, $timeMax);

        $vacationCenters = self::ensureVacationCentersForBackfill($userId, $tokenRow, $hamdastAccessToken);

        if ($vacationCenters === []) {

            error_log('[google-vacation] backfill aborted: no vacation centers user=' . $userId);

            return $empty;

        }



        $eventTotal = count($events);

        GoogleTokensRepository::updateSyncProgress($userId, [
            'phase' => 'processing',
            'processed' => 0,
            'total' => $eventTotal,
            'percent' => 12,
        ]);



        $imported = 0;

        $skipped = 0;

        $failed = 0;

        $cutoffTs = GoogleTokensRepository::getImportBackfillCutoffTs($tokenRow);
        if ($cutoffTs !== null && GoogleVacationRepository::countTrackedVacations($userId) === 0) {
            $cutoffTs = null;
        }



        foreach ($events as $eventIndex => $event) {

            if (!is_array($event)) {

                self::reportBackfillProgress($userId, (int) $eventIndex + 1, $eventTotal);
                continue;

            }

            $parsedForCutoff = GoogleEventParser::parseEvent($event);
            if ($parsedForCutoff === null) {
                $skipped++;
                self::reportBackfillProgress($userId, (int) $eventIndex + 1, $eventTotal);
                continue;
            }

            if (
                $cutoffTs !== null
                && !GoogleEventParser::isEventNewerThanCutoff($parsedForCutoff, $cutoffTs)
            ) {
                $skipped++;
                self::reportBackfillProgress($userId, (int) $eventIndex + 1, $eventTotal);
                continue;
            }



            $result = self::syncSingleEvent(

                $userId,

                $tokenRow,

                $event,

                true,

                $vacationCenters,

                $hamdastAccessToken,

                true

            );

            if ($result === 'created') {
                $imported++;
            } elseif ($result === 'failed') {

                $failed++;

            } else {

                $skipped++;

            }

            self::reportBackfillProgress($userId, (int) $eventIndex + 1, $eventTotal);

        }



        if ($failed > 0) {

            error_log(

                '[google-vacation] backfill partial fail user=' . $userId

                . ' events=' . count($events)

                . ' created=' . $imported

                . ' skipped=' . $skipped

                . ' failed=' . $failed

            );

            ImportFutureVacationsRepository::reconcileBackfillSlotsFromTrackedEvents($userId);

            return self::withBackfillSlotCount($userId, [

                'ran' => true,

                'imported' => $imported,

                'skipped' => $skipped,

                'failed' => $failed,

            ]);

        }



        // If the user resets/deletes the 30-day import while this job is still running,
        // we must not mark completion again; otherwise the UI keeps showing "reset required".
        $latestTokenRow = GoogleTokensRepository::findByUserId($userId);
        if ($latestTokenRow === null || !GoogleTokensRepository::shouldRunFutureVacationsBackfill($latestTokenRow)) {
            error_log('[google-vacation] backfill aborted: state changed during run user=' . $userId);
            return $empty;
        }

        GoogleTokensRepository::markImportBackfillDone($userId);

        ImportFutureVacationsRepository::reconcileBackfillSlotsFromTrackedEvents($userId);

        error_log(

            '[google-vacation] backfill completed user=' . $userId

            . ' events=' . count($events)

            . ' created=' . $imported

            . ' skipped=' . $skipped

        );



        return self::withBackfillSlotCount($userId, [

            'ran' => true,

            'imported' => $imported,

            'skipped' => $skipped,

            'failed' => $failed,

        ]);

    }



    /**
     * @param array{ran: bool, imported: int, skipped: int, failed: int} $result
     * @return array{ran: bool, imported: int, skipped: int, failed: int, slot_count: int}
     */
    private static function withBackfillSlotCount(string $userId, array $result): array
    {
        ImportFutureVacationsRepository::reconcileBackfillSlotsFromTrackedEvents($userId);
        $trackedCount = GoogleVacationRepository::countTrackedVacations($userId);
        $result['slot_count'] = $trackedCount;
        $result['tracked_count'] = $trackedCount;

        return $result;
    }

    private static function reportBackfillProgress(string $userId, int $processed, int $total): void
    {
        static $lastByUser = [];

        if ($total <= 0) {
            GoogleTokensRepository::updateSyncProgress($userId, [
                'phase' => 'processing',
                'processed' => 0,
                'total' => 0,
                'percent' => 90,
            ]);

            return;
        }

        $percent = (int) min(98, max(12, round(12 + ($processed / $total) * 86)));
        $prev = $lastByUser[$userId] ?? null;
        $now = microtime(true);
        $isDone = $processed >= $total;
        $shouldWrite = $isDone
            || $processed <= 1
            || $total <= 40
            || $prev === null
            || ($now - (float) ($prev['at'] ?? 0.0)) >= 0.1
            || $percent > (int) ($prev['percent'] ?? 0);

        if (!$shouldWrite) {
            return;
        }

        $lastByUser[$userId] = ['percent' => $percent, 'at' => $now];

        GoogleTokensRepository::updateSyncProgress($userId, [
            'phase' => 'processing',
            'processed' => $processed,
            'total' => $total,
            'percent' => $percent,
        ]);
    }



    /**

     * @param array<string, mixed> $tokenRow

     * @return array<int, array{medical_center_id: string, user_center_id: ?string, name: string}>

     */

    private static function ensureVacationCentersForBackfill(

        string $userId,

        array $tokenRow,

        string $hamdastAccessToken,

        bool $trackAsBackfill = false

    ): array {

        $vacationCenters = self::resolveVacationCentersForUser($userId, $tokenRow, $hamdastAccessToken);

        if ($vacationCenters !== []) {

            return $vacationCenters;

        }



        $resolvedCenter = Paziresh24VacationApi::resolveVacationCenter($hamdastAccessToken);

        if ($resolvedCenter === null) {

            return [];

        }



        GoogleVacationRepository::saveCenterId($userId, $resolvedCenter['medical_center_id']);

        $tokenRow = GoogleTokensRepository::findByUserId($userId) ?? $tokenRow;



        return self::resolveVacationCentersForUser($userId, $tokenRow, $hamdastAccessToken);

    }



    /**

     * @param array<string, mixed> $tokenRow

     * @return array<int, array{medical_center_id: string, user_center_id: ?string, name: string}>

     */

    private static function resolveVacationCentersForUser(

        string $userId,

        array $tokenRow,

        string $hamdastAccessToken

    ): array {

        if ($hamdastAccessToken === '') {

            return [];

        }



        $availableCenters = Paziresh24VacationApi::normalizeMedicalCenters($hamdastAccessToken);

        if ($availableCenters !== []) {

            $filtered = GoogleTokensRepository::filterVacationCentersForSync($tokenRow, $availableCenters);

            if ($filtered !== []) {

                GoogleVacationRepository::saveCenterId($userId, $filtered[0]['medical_center_id']);

                error_log(

                    '[google-vacation] vacation centers resolved count=' . count($filtered)

                    . ' user=' . $userId

                );



                return $filtered;

            }

        }



        $storedCenterId = isset($tokenRow['center_id']) && is_string($tokenRow['center_id'])

            ? trim($tokenRow['center_id'])

            : '';



        if ($storedCenterId === '') {

            return [];

        }



        $userCenterId = null;

        foreach ($availableCenters as $center) {

            if (($center['medical_center_id'] ?? '') === $storedCenterId) {

                $userCenterId = isset($center['user_center_id']) && is_string($center['user_center_id'])

                    ? trim($center['user_center_id'])

                    : null;

                break;

            }

        }



        error_log('[google-vacation] vacation centers fallback legacy center_id=' . $storedCenterId);



        return [

            [

                'medical_center_id' => $storedCenterId,

                'user_center_id' => $userCenterId !== '' ? $userCenterId : null,

                'name' => 'مرکز ' . substr($storedCenterId, 0, 8),

            ],

        ];

    }



    /**

     * @param array<string, mixed> $tokenRow

     * @param array<string, mixed> $googleEvent

     * @param array<int, array{medical_center_id: string, user_center_id: ?string, name: string}> $vacationCenters

     * @return 'created'|'updated'|'skipped'|'failed'

     */

    public static function syncSingleEvent(

        string $userId,

        array $tokenRow,

        array $googleEvent,

        bool $autoVacation,

        array $vacationCenters,

        string $hamdastAccessToken,

        bool $trackAsBackfill = false

    ): string {

        $eventId = GoogleEventParser::extractEventId($googleEvent);

        $parsed = GoogleEventParser::parseEvent($googleEvent);

        $isDeleted = self::isDeletedGoogleEvent($googleEvent, $parsed);



        if ($isDeleted) {

            self::processDeletedEvent($userId, $eventId, $parsed, $tokenRow, $hamdastAccessToken);



            if (GoogleEventParser::isHamgamAppointmentEvent($googleEvent)) {

                self::processDeletedAppointmentEvent($userId, $tokenRow, $googleEvent, $hamdastAccessToken);

            }



            return 'skipped';

        }



        if ($parsed === null) {

            return 'skipped';

        }



        error_log(

            '[google-vacation] event parsed id=' . $parsed['event_id']

            . ' from=' . $parsed['start_ts'] . ' to=' . $parsed['end_ts']

            . ' deleted=' . ($parsed['is_deleted'] ? '1' : '0')

        );



        if ($parsed['status'] !== 'confirmed') {

            error_log('[google-vacation] skipped non-confirmed event ' . $parsed['event_id']);

            return 'skipped';

        }



        if (GoogleEventParser::isHamgamAppointmentEvent($googleEvent)) {

            error_log('[google-vacation] skipped appointment event ' . $parsed['event_id']);

            return 'skipped';

        }



        if (GoogleVacationRepository::hasProcessedEvent($userId, $parsed['event_id'])) {

            $updated = self::processUpdatedEvent(

                $userId,

                $parsed,

                $autoVacation,

                $vacationCenters,

                $tokenRow,

                $hamdastAccessToken,

                $trackAsBackfill

            );



            return $updated ? 'updated' : 'skipped';

        }



        if (!$autoVacation) {

            error_log('[google-vacation] auto_vacation off, no Paziresh24 action for ' . $parsed['event_id']);

            return 'skipped';

        }



        if ($hamdastAccessToken === '') {

            error_log('[google-vacation] missing hamdast token for user ' . $userId);

            return 'failed';

        }



        if ($vacationCenters === []) {

            error_log('[google-vacation] missing medical centers for user ' . $userId);

            return 'failed';

        }



        $createdCount = 0;

        $failedCount = 0;



        foreach ($vacationCenters as $vacationCenter) {

            $response = self::createVacationWithConflictResolution(

                $userId,

                $tokenRow,

                $parsed['start_ts'],

                $parsed['end_ts'],

                $vacationCenter,

                $hamdastAccessToken

            );



            if ($response === null) {

                error_log(

                    '[google-vacation] vacation create failed for event ' . $parsed['event_id']

                    . ' center=' . $vacationCenter['medical_center_id']

                );

                $failedCount++;

                continue;

            }



            GoogleVacationRepository::recordProcessedEvent(

                $userId,

                $parsed['event_id'],

                $parsed['summary'],

                $parsed['start_ts'],

                $parsed['end_ts'],

                $response,

                $vacationCenter['medical_center_id']

            );

            if ($trackAsBackfill) {
                ImportFutureVacationsRepository::upsertBackfillSlotForEvent(
                    $userId,
                    $parsed['event_id'],
                    $vacationCenter['medical_center_id'],
                    $parsed['start_ts'],
                    $parsed['end_ts']
                );
            }



            error_log(

                '[google-vacation] vacation created for event ' . $parsed['event_id']

                . ' center=' . $vacationCenter['medical_center_id']

            );

            $createdCount++;

        }



        if ($createdCount > 0) {

            return 'created';

        }



        return $failedCount > 0 ? 'failed' : 'skipped';

    }



    /**

     * @param array{

     *   event_id: string,

     *   summary: string,

     *   status: string,

     *   start_ts: int,

     *   end_ts: int,

     *   timezone: string,

     *   created: ?string,

     *   updated: ?string,

     *   is_deleted: bool

     * } $parsed

     * @param array<int, array{medical_center_id: string, user_center_id: ?string, name: string}> $vacationCenters

     * @param array<string, mixed> $tokenRow

     */

    private static function processUpdatedEvent(

        string $userId,

        array $parsed,

        bool $autoVacation,

        array $vacationCenters,

        array $tokenRow,

        string $hamdastAccessToken,

        bool $trackAsBackfill = false

    ): bool {

        $eventId = $parsed['event_id'];

        $trackedRows = GoogleVacationRepository::findProcessedEventsForGoogleEvent($userId, $eventId);

        if ($trackedRows === []) {

            error_log('[google-vacation] update skipped: tracked event missing for ' . $eventId);

            return false;

        }



        $fallbackCenterId = isset($tokenRow['center_id']) && is_string($tokenRow['center_id'])

            ? trim($tokenRow['center_id'])

            : null;



        $newFrom = $parsed['start_ts'];

        $newTo = $parsed['end_ts'];

        $anyUpdated = false;



        foreach ($trackedRows as $tracked) {

            $oldFrom = isset($tracked['vacation_from']) ? (int) $tracked['vacation_from'] : 0;

            $oldTo = isset($tracked['vacation_to']) ? (int) $tracked['vacation_to'] : 0;

            $medicalCenterId = GoogleVacationRepository::resolveTrackedMedicalCenterId($tracked, $fallbackCenterId);



            if ($oldFrom <= 0 || $oldTo <= 0 || $newFrom <= 0 || $newTo <= $newFrom) {

                error_log(

                    '[google-vacation] update skipped: invalid timestamps for event ' . $eventId

                    . ' center=' . $medicalCenterId

                );

                continue;

            }



            if ($oldFrom === $newFrom && $oldTo === $newTo) {

                $oldSummary = is_string($tracked['event_summary'] ?? null) ? $tracked['event_summary'] : '';

                if ($oldSummary !== $parsed['summary']) {

                    GoogleVacationRepository::recordProcessedEvent(

                        $userId,

                        $eventId,

                        $parsed['summary'],

                        $newFrom,

                        $newTo,

                        self::decodeStoredResponse($tracked),

                        $medicalCenterId

                    );

                }

                if ($trackAsBackfill) {
                    ImportFutureVacationsRepository::upsertBackfillSlotForEvent(
                        $userId,
                        $eventId,
                        $medicalCenterId,
                        $newFrom,
                        $newTo
                    );
                    $anyUpdated = true;
                } elseif (
                    ImportFutureVacationsRepository::hasActiveBackfillSlotForEvent($userId, $eventId, $medicalCenterId)
                ) {
                    ImportFutureVacationsRepository::syncBackfillSlotForEvent(
                        $userId,
                        $eventId,
                        $medicalCenterId,
                        $newFrom,
                        $newTo,
                        $oldFrom,
                        $oldTo
                    );
                }

                continue;

            }



            if (!$autoVacation) {

                error_log('[google-vacation] auto_vacation off, no update for event ' . $eventId);

                continue;

            }



            if ($hamdastAccessToken === '') {

                error_log('[google-vacation] missing hamdast token for update user ' . $userId);

                continue;

            }



            if ($medicalCenterId === '') {

                error_log('[google-vacation] missing medical_center_id for update user ' . $userId);

                continue;

            }



            $vacationCenter = self::findVacationCenterByMedicalId($vacationCenters, $medicalCenterId);

            if ($vacationCenter === null) {

                $vacationCenter = [

                    'medical_center_id' => $medicalCenterId,

                    'user_center_id' => null,

                    'name' => '',

                ];

            }



            $response = self::updateVacationWithConflictResolution(

                $userId,

                $tokenRow,

                $newFrom,

                $newTo,

                $oldFrom,

                $oldTo,

                $vacationCenter,

                $hamdastAccessToken

            );



            if ($response === null) {

                error_log(

                    '[google-vacation] vacation update failed for event ' . $eventId

                    . ' center=' . $medicalCenterId

                );

                continue;

            }



            GoogleVacationRepository::recordProcessedEvent(

                $userId,

                $eventId,

                $parsed['summary'],

                $newFrom,

                $newTo,

                $response,

                $medicalCenterId

            );

            if ($trackAsBackfill) {
                ImportFutureVacationsRepository::syncBackfillSlotForEvent(
                    $userId,
                    $eventId,
                    $medicalCenterId,
                    $newFrom,
                    $newTo,
                    $oldFrom,
                    $oldTo
                );
            }



            error_log(

                '[google-vacation] vacation updated for event ' . $eventId

                . ' center=' . $medicalCenterId

                . ' old_from=' . $oldFrom

                . ' old_to=' . $oldTo

                . ' from=' . $newFrom

                . ' to=' . $newTo

            );

            $anyUpdated = true;

        }



        if (!$anyUpdated && $trackedRows !== [] && $trackAsBackfill) {
            error_log('[google-vacation] vacation update skipped: no time change for event ' . $eventId);

            foreach ($trackedRows as $tracked) {
                $medicalCenterId = GoogleVacationRepository::resolveTrackedMedicalCenterId(
                    $tracked,
                    $fallbackCenterId
                );
                $slotFrom = isset($tracked['vacation_from']) ? (int) $tracked['vacation_from'] : 0;
                $slotTo = isset($tracked['vacation_to']) ? (int) $tracked['vacation_to'] : 0;

                if ($medicalCenterId === '' || $slotFrom <= 0 || $slotTo <= $slotFrom) {
                    continue;
                }

                ImportFutureVacationsRepository::upsertBackfillSlotForEvent(
                    $userId,
                    $eventId,
                    $medicalCenterId,
                    $slotFrom,
                    $slotTo
                );
                $anyUpdated = true;
            }
        } elseif (!$anyUpdated && $trackedRows !== []) {
            error_log('[google-vacation] vacation update skipped: no time change for event ' . $eventId);
        }

        return $anyUpdated;

    }



    /**

     * @param array<string, mixed> $tracked

     * @return array<string, mixed>|null

     */

    private static function decodeStoredResponse(array $tracked): ?array

    {

        if (!isset($tracked['paziresh24_response']) || !is_string($tracked['paziresh24_response'])) {

            return null;

        }



        $decoded = json_decode($tracked['paziresh24_response'], true);



        return is_array($decoded) ? $decoded : null;

    }



    /**

     * @param array<string, mixed> $googleEvent

     * @param array{

     *   event_id: string,

     *   summary: string,

     *   status: string,

     *   start_ts: int,

     *   end_ts: int,

     *   timezone: string,

     *   created: ?string,

     *   updated: ?string,

     *   is_deleted: bool

     * }|null $parsed

     */

    private static function isDeletedGoogleEvent(array $googleEvent, ?array $parsed): bool

    {

        if (($googleEvent['deleted'] ?? false) === true) {

            return true;

        }



        $rawStatus = $googleEvent['status'] ?? null;

        if (is_string($rawStatus) && $rawStatus === 'cancelled') {

            return true;

        }



        return $parsed !== null && $parsed['is_deleted'];

    }



    /**

     * Fallback targets when a deleted Google event has no tracked row (e.g. legacy backfill).

     *

     * @param array{

     *   event_id: string,

     *   summary: string,

     *   status: string,

     *   start_ts: int,

     *   end_ts: int,

     *   timezone: string,

     *   created: ?string,

     *   updated: ?string,

     *   is_deleted: bool

     * }|null $parsed

     * @param array<string, mixed> $tokenRow

     * @return array<int, array<string, mixed>>

     */

    private static function buildUntrackedDeletedVacationTargets(

        string $userId,

        string $eventId,

        ?array $parsed,

        array $tokenRow,

        string $hamdastAccessToken

    ): array {

        if ($parsed === null || $parsed['start_ts'] <= 0 || $parsed['end_ts'] <= $parsed['start_ts']) {

            return [];

        }



        if ($hamdastAccessToken === '') {

            return [];

        }



        $centers = self::resolveVacationCentersForUser($userId, $tokenRow, $hamdastAccessToken);

        if ($centers === []) {

            return [];

        }



        $targets = [];

        foreach ($centers as $center) {

            $targets[] = [

                'google_event_id' => $eventId,

                'event_summary' => $parsed['summary'],

                'vacation_from' => $parsed['start_ts'],

                'vacation_to' => $parsed['end_ts'],

                'medical_center_id' => $center['medical_center_id'],

            ];

        }



        error_log(

            '[google-vacation] delete fallback using parsed timestamps for untracked event '

            . $eventId

            . ' centers=' . count($targets)

        );



        return $targets;

    }



    /**

     * @param array{

     *   event_id: string,

     *   summary: string,

     *   status: string,

     *   start_ts: int,

     *   end_ts: int,

     *   timezone: string,

     *   created: ?string,

     *   updated: ?string,

     *   is_deleted: bool

     * }|null $parsed

     * @param array<string, mixed> $tokenRow

     */

    private static function processDeletedEvent(

        string $userId,

        ?string $eventId,

        ?array $parsed,

        array $tokenRow,

        string $hamdastAccessToken

    ): void {

        if ($eventId === null || $eventId === '') {

            error_log('[google-vacation] delete skipped: missing event_id');

            return;

        }



        error_log('[google-vacation] processing deleted event id=' . $eventId);



        $trackedRows = GoogleVacationRepository::findProcessedEventsRelatedToGoogleEvent($userId, $eventId);

        if ($trackedRows === []) {

            $trackedRows = self::buildUntrackedDeletedVacationTargets(

                $userId,

                $eventId,

                $parsed,

                $tokenRow,

                $hamdastAccessToken

            );

        }



        if ($trackedRows === []) {

            error_log('[google-vacation] delete skipped: event not tracked ' . $eventId);

            return;

        }



        $fallbackCenterId = isset($tokenRow['center_id']) && is_string($tokenRow['center_id'])

            ? trim($tokenRow['center_id'])

            : null;



        if ($hamdastAccessToken === '') {

            error_log('[google-vacation] missing hamdast token for delete user ' . $userId);

            return;

        }



        $deletedAny = false;



        foreach ($trackedRows as $tracked) {

            $from = isset($tracked['vacation_from']) ? (int) $tracked['vacation_from'] : 0;

            $to = isset($tracked['vacation_to']) ? (int) $tracked['vacation_to'] : 0;

            $medicalCenterId = GoogleVacationRepository::resolveTrackedMedicalCenterId($tracked, $fallbackCenterId);



            if ($from <= 0 || $to <= 0) {

                if ($parsed !== null && $parsed['start_ts'] > 0 && $parsed['end_ts'] > $parsed['start_ts']) {

                    $from = $parsed['start_ts'];

                    $to = $parsed['end_ts'];

                    error_log('[google-vacation] delete using parsed timestamps fallback for event ' . $eventId);

                } else {

                    error_log('[google-vacation] delete failed: missing timestamps for event ' . $eventId);

                    continue;

                }

            }



            if ($medicalCenterId === '') {

                error_log('[google-vacation] missing medical_center_id for delete user ' . $userId);

                continue;

            }



            $response = Paziresh24VacationApi::deleteVacation(

                $hamdastAccessToken,

                $medicalCenterId,

                $from,

                $to

            );



            if ($response === null) {

                error_log(

                    '[google-vacation] vacation delete failed for event ' . $eventId

                    . ' center=' . $medicalCenterId

                );

                continue;

            }



            GoogleVacationRepository::removeProcessedEvent(

                $userId,

                is_string($tracked['google_event_id'] ?? null) && $tracked['google_event_id'] !== ''

                    ? $tracked['google_event_id']

                    : $eventId,

                $medicalCenterId

            );

            ImportFutureVacationsRepository::markBackfillSlotsDeletedByEvent(
                $userId,
                is_string($tracked['google_event_id'] ?? null) && $tracked['google_event_id'] !== ''
                    ? $tracked['google_event_id']
                    : $eventId,
                $medicalCenterId
            );

            error_log(

                '[google-vacation] vacation deleted for event ' . $eventId

                . ' center=' . $medicalCenterId

                . ' from=' . $from

                . ' to=' . $to

            );

            $deletedAny = true;

        }



        if (!$deletedAny) {

            error_log('[google-vacation] vacation delete failed for event ' . $eventId);

        }

    }



    /**

     * @param array<string, mixed> $tokenRow

     * @param array<string, mixed> $googleEvent

     */

    private static function processDeletedAppointmentEvent(

        string $userId,

        array $tokenRow,

        array $googleEvent,

        string $hamdastAccessToken

    ): void {

        $eventId = GoogleEventParser::extractEventId($googleEvent);

        $bookId = GoogleEventParser::extractBookId($googleEvent);



        error_log(

            '[google-vacation] processing deleted appointment event id=' . ($eventId ?? 'null')

            . ' book_id=' . ($bookId ?? 'null')

        );



        if ($bookId === null || $bookId === '') {

            error_log('[google-vacation] appointment delete skipped: missing book_id event=' . ($eventId ?? ''));

            return;

        }



        if (!GoogleTokensRepository::isCancelAppointmentOnEventDeleteEnabled($tokenRow)) {

            error_log(

                '[google-vacation] appointment delete disabled in settings book_id=' . $bookId

            );

            GoogleCalendarBookingRepository::removeProcessedBooking($userId, $bookId);



            if ($eventId !== null && $eventId !== '') {

                GoogleVacationRepository::removeProcessedEvent($userId, $eventId);

            }



            return;

        }



        if ($hamdastAccessToken === '') {

            error_log('[google-vacation] missing hamdast token for appointment delete user ' . $userId);

            return;

        }



        $response = Paziresh24AppointmentApi::deleteAppointmentResult($hamdastAccessToken, $bookId);

        if (!$response['success']) {

            if ($response['permission_denied']) {

                error_log(

                    '[google-vacation] appointment delete blocked: re-auth required for provider.appointment.write book_id='

                    . $bookId

                );

            } else {

                error_log('[google-vacation] appointment delete failed book_id=' . $bookId);

            }



            return;

        }



        GoogleCalendarBookingRepository::removeProcessedBooking($userId, $bookId);



        if ($eventId !== null && $eventId !== '') {

            GoogleVacationRepository::removeProcessedEvent($userId, $eventId);

        }



        error_log('[google-vacation] appointment cancelled book_id=' . $bookId);

    }



    /**

     * @param array<string, mixed> $tokenRow

     * @param array{medical_center_id: string, user_center_id: ?string, name: string} $vacationCenter

     * @return array<string, mixed>|null

     */

    private static function createVacationWithConflictResolution(

        string $userId,

        array $tokenRow,

        int $from,

        int $to,

        array $vacationCenter,

        string $hamdastAccessToken

    ): ?array {

        self::resolveOverlappingAppointments(

            $userId,

            $tokenRow,

            $from,

            $to,

            $vacationCenter,

            $hamdastAccessToken

        );



        $result = Paziresh24VacationApi::createVacationResult(

            $hamdastAccessToken,

            $vacationCenter['medical_center_id'],

            $from,

            $to,

            $vacationCenter['user_center_id'] ?? null

        );



        if ($result['success']) {

            return $result['body'];

        }



        if (!$result['book_conflict']) {

            return null;

        }



        $resolved = self::resolveOverlappingAppointments(

            $userId,

            $tokenRow,

            $from,

            $to,

            $vacationCenter,

            $hamdastAccessToken

        );



        if ($resolved <= 0) {

            $resolved = self::resolveOverlappingAppointmentsFromConflictBody(

                $userId,

                $tokenRow,

                $from,

                $to,

                $vacationCenter,

                $hamdastAccessToken,

                $result['body']

            );

        }



        if ($resolved <= 0) {

            return null;

        }



        $retry = Paziresh24VacationApi::createVacationResult(

            $hamdastAccessToken,

            $vacationCenter['medical_center_id'],

            $from,

            $to,

            $vacationCenter['user_center_id'] ?? null

        );



        return $retry['success'] ? $retry['body'] : null;

    }



    /**

     * @param array<string, mixed> $tokenRow

     * @param array{medical_center_id: string, user_center_id: ?string, name: string} $vacationCenter

     * @return array<string, mixed>|null

     */

    private static function updateVacationWithConflictResolution(

        string $userId,

        array $tokenRow,

        int $from,

        int $to,

        int $oldFrom,

        int $oldTo,

        array $vacationCenter,

        string $hamdastAccessToken

    ): ?array {

        $medicalCenterId = $vacationCenter['medical_center_id'];



        self::resolveOverlappingAppointments(

            $userId,

            $tokenRow,

            $from,

            $to,

            $vacationCenter,

            $hamdastAccessToken

        );



        $result = Paziresh24VacationApi::updateVacationResult(

            $hamdastAccessToken,

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

            return self::createVacationWithConflictResolution(

                $userId,

                $tokenRow,

                $from,

                $to,

                $vacationCenter,

                $hamdastAccessToken

            );

        }



        if (!$result['book_conflict']) {

            return null;

        }



        $resolved = self::resolveOverlappingAppointments(

            $userId,

            $tokenRow,

            $from,

            $to,

            $vacationCenter,

            $hamdastAccessToken

        );



        if ($resolved <= 0) {

            $resolved = self::resolveOverlappingAppointmentsFromConflictBody(

                $userId,

                $tokenRow,

                $from,

                $to,

                $vacationCenter,

                $hamdastAccessToken,

                $result['body']

            );

        }



        if ($resolved <= 0) {

            return null;

        }



        $retry = Paziresh24VacationApi::updateVacationResult(

            $hamdastAccessToken,

            $medicalCenterId,

            $from,

            $to,

            $oldFrom,

            $oldTo

        );



        if ($retry['success']) {

            return $retry['body'];

        }



        if ($retry['not_found']) {

            return self::createVacationWithConflictResolution(

                $userId,

                $tokenRow,

                $from,

                $to,

                $vacationCenter,

                $hamdastAccessToken

            );

        }



        return null;

    }



    /**

     * @param array<int, array{medical_center_id: string, user_center_id: ?string, name: string}> $vacationCenters

     * @return array{medical_center_id: string, user_center_id: ?string, name: string}|null

     */

    private static function findVacationCenterByMedicalId(array $vacationCenters, string $medicalCenterId): ?array

    {

        foreach ($vacationCenters as $center) {

            if (($center['medical_center_id'] ?? '') === $medicalCenterId) {

                return $center;

            }

        }



        return null;

    }



    /**

     * @param array<string, mixed> $tokenRow

     * @param array{medical_center_id: string, user_center_id: ?string, name: string} $vacationCenter

     */

    private static function resolveOverlappingAppointments(

        string $userId,

        array $tokenRow,

        int $from,

        int $to,

        array $vacationCenter,

        string $hamdastAccessToken

    ): int {

        if (GoogleTokensRepository::isCancelConflictingAppointmentsEnabled($tokenRow)) {

            $cancelled = self::cancelOverlappingAppointments(

                $userId,

                $tokenRow,

                $from,

                $to,

                $vacationCenter,

                $hamdastAccessToken

            );



            error_log(

                '[google-vacation] book conflict resolved cancelled=' . $cancelled

                . ' from=' . $from

                . ' to=' . $to

                . ' center=' . $vacationCenter['medical_center_id']

            );



            return $cancelled;

        }



        $rescheduled = self::rescheduleOverlappingAppointments(

            $userId,

            $tokenRow,

            $from,

            $to,

            $vacationCenter,

            $hamdastAccessToken

        );



        error_log(

            '[google-vacation] book conflict resolved rescheduled=' . $rescheduled

            . ' from=' . $from

            . ' to=' . $to

            . ' center=' . $vacationCenter['medical_center_id']

        );



        return $rescheduled;

    }



    /**

     * @param array<string, mixed> $tokenRow

     * @param array{medical_center_id: string, user_center_id: ?string, name: string} $vacationCenter

     */

    private static function rescheduleOverlappingAppointments(

        string $userId,

        array $tokenRow,

        int $from,

        int $to,

        array $vacationCenter,

        string $hamdastAccessToken

    ): int {

        $googleAccessToken = self::refreshGoogleAccessToken($tokenRow);

        if ($googleAccessToken === null) {

            return 0;

        }



        $targets = self::findOverlappingAppointments(

            $userId,

            $googleAccessToken,

            $hamdastAccessToken,

            $from,

            $to,

            $vacationCenter

        );



        $rescheduled = 0;

        foreach ($targets as $target) {

            if (

                self::rescheduleSingleOverlappingAppointment(

                    $userId,

                    $target['book_id'],

                    $target['from'],

                    $target['to'],

                    $target['medical_center_id'],

                    $vacationCenter,

                    $hamdastAccessToken,

                    $from,

                    $to

                )

            ) {

                $rescheduled++;

            }

        }



        return $rescheduled;

    }



    /**

     * @param array{medical_center_id: string, user_center_id: ?string, name: string} $vacationCenter

     */

    private static function rescheduleSingleOverlappingAppointment(

        string $userId,

        string $bookId,

        int $bookFrom,

        int $bookTo,

        string $medicalCenterId,

        array $vacationCenter,

        string $hamdastAccessToken,

        int $vacationFrom,

        int $vacationTo

    ): bool {

        $hintUserCenterId = isset($vacationCenter['user_center_id']) && is_string($vacationCenter['user_center_id'])

            ? trim($vacationCenter['user_center_id'])

            : null;



        $result = Paziresh24AppointmentApi::rescheduleToFirstAvailableSlot(

            $hamdastAccessToken,

            $bookId,

            $medicalCenterId,

            $hintUserCenterId !== '' ? $hintUserCenterId : null,

            $bookFrom,

            $bookTo,

            $vacationFrom,

            $vacationTo

        );



        if (!$result['success']) {

            error_log(

                '[google-vacation] reschedule failed book_id=' . $bookId

                . ' stage=' . ($result['stage'] ?? 'unknown')

            );

            return false;

        }



        self::syncAppointmentCalendarAfterReschedule($userId, $bookId, $hamdastAccessToken);



        error_log(

            '[google-vacation] rescheduled overlapping appointment book_id=' . $bookId

            . ' target_from=' . ($result['target_from'] ?? '')

        );



        return true;

    }



    private static function syncAppointmentCalendarAfterReschedule(

        string $userId,

        string $bookId,

        string $hamdastAccessToken

    ): void {

        $appointment = GoogleCalendar::getAppointment($bookId, $hamdastAccessToken);

        if (!is_array($appointment)) {

            error_log('[google-vacation] calendar sync after reschedule skipped: appointment fetch book_id=' . $bookId);

            return;

        }



        try {

            $updateResult = AppointmentWebhookService::handleUpdate($appointment, $userId, $bookId);

            error_log(

                '[google-vacation] calendar updated after reschedule book_id=' . $bookId

                . ' result=' . json_encode($updateResult, JSON_UNESCAPED_UNICODE)

            );

        } catch (Throwable $e) {

            error_log(

                '[google-vacation] calendar sync after reschedule failed book_id=' . $bookId

                . ' error=' . $e->getMessage()

            );

        }

    }



    /**

     * @param array{medical_center_id: string, user_center_id: ?string, name: string} $vacationCenter

     * @return array<int, array{

     *   book_id: string,

     *   event: array<string, mixed>,

     *   from: int,

     *   to: int,

     *   medical_center_id: string

     * }>

     */

    private static function findOverlappingAppointments(

        string $userId,

        string $googleAccessToken,

        string $hamdastAccessToken,

        int $from,

        int $to,

        array $vacationCenter

    ): array {

        $medicalCenterId = $vacationCenter['medical_center_id'];

        $timeMin = gmdate('Y-m-d\TH:i:s\Z', $from - 3600);

        $timeMax = gmdate('Y-m-d\TH:i:s\Z', $to + 3600);

        $events = GoogleCalendarWatch::listEventsInRange($googleAccessToken, $timeMin, $timeMax);

        $targets = [];

        $seenBookIds = [];



        foreach ($events as $event) {

            if (!is_array($event)) {

                continue;

            }



            $target = self::buildOverlappingAppointmentTarget(

                $event,

                $from,

                $to,

                $medicalCenterId,

                $hamdastAccessToken

            );

            if ($target === null || isset($seenBookIds[$target['book_id']])) {

                continue;

            }



            $seenBookIds[$target['book_id']] = true;

            $targets[] = $target;

        }



        foreach (GoogleCalendarBookingRepository::listBookIdsForUser($userId) as $bookId) {

            if (isset($seenBookIds[$bookId])) {

                continue;

            }



            $bookEvents = GoogleCalendar::findEventsByBookId($googleAccessToken, $bookId);

            if ($bookEvents === []) {

                $storedEventId = GoogleCalendarBookingRepository::getGoogleEventId($userId, $bookId);

                if ($storedEventId !== null) {

                    $bookEvents = [['id' => $storedEventId]];

                }

            }



            foreach ($bookEvents as $event) {

                if (!is_array($event)) {

                    continue;

                }



                $target = self::buildOverlappingAppointmentTarget(

                    $event,

                    $from,

                    $to,

                    $medicalCenterId,

                    $hamdastAccessToken,

                    $bookId

                );

                if ($target === null || isset($seenBookIds[$target['book_id']])) {

                    continue;

                }



                $seenBookIds[$target['book_id']] = true;

                $targets[] = $target;

            }

        }



        return $targets;

    }



    /**

     * @param array<string, mixed> $event

     * @return array{

     *   book_id: string,

     *   event: array<string, mixed>,

     *   from: int,

     *   to: int,

     *   medical_center_id: string

     * }|null

     */

    private static function buildOverlappingAppointmentTarget(

        array $event,

        int $from,

        int $to,

        string $medicalCenterId,

        string $hamdastAccessToken,

        ?string $bookIdHint = null

    ): ?array {

        $bookId = $bookIdHint ?? GoogleEventParser::extractBookId($event);

        if ($bookId === null || $bookId === '') {

            return null;

        }



        if ($bookIdHint === null && !GoogleEventParser::isHamgamAppointmentEvent($event)) {

            return null;

        }



        $parsed = GoogleEventParser::parseEvent($event);

        if ($parsed === null) {

            $moveRange = Paziresh24AppointmentApi::resolveMoveRange($hamdastAccessToken, $bookId, 0, 0);

            if ($moveRange['from'] <= 0 || $moveRange['to'] <= $moveRange['from']) {

                return null;

            }



            if (!self::rangesOverlap($moveRange['from'], $moveRange['to'], $from, $to)) {

                return null;

            }



            $eventFrom = $moveRange['from'];

            $eventTo = $moveRange['to'];

        } else {

            if (!self::rangesOverlap($parsed['start_ts'], $parsed['end_ts'], $from, $to)) {

                return null;

            }



            $eventFrom = $parsed['start_ts'];

            $eventTo = $parsed['end_ts'];

        }



        $appointmentCenterId = BookingAppointmentResolver::resolveAppointmentMedicalCenterId(

            $bookId,

            $hamdastAccessToken,

            $bookIdHint === null ? $event : null

        );

        if ($appointmentCenterId === null || $appointmentCenterId !== $medicalCenterId) {

            return null;

        }



        return [

            'book_id' => $bookId,

            'event' => $event,

            'from' => $eventFrom,

            'to' => $eventTo,

            'medical_center_id' => $appointmentCenterId,

        ];

    }



    /**

     * @param array<string, mixed> $tokenRow

     */

    private static function refreshGoogleAccessToken(array $tokenRow): ?string

    {

        $refreshToken = (string) ($tokenRow['google_refresh_token'] ?? '');

        if ($refreshToken === '') {

            return null;

        }



        $googleTokenData = GoogleCalendar::refreshAccessToken($refreshToken);

        $googleAccessToken = is_array($googleTokenData) ? ($googleTokenData['access_token'] ?? '') : '';

        if (!is_string($googleAccessToken) || $googleAccessToken === '') {

            return null;

        }



        return $googleAccessToken;

    }






    /**

     * @param array<string, mixed> $tokenRow

     * @param array{medical_center_id: string, user_center_id: ?string, name: string} $vacationCenter

     */

    private static function resolveOverlappingAppointmentsFromConflictBody(

        string $userId,

        array $tokenRow,

        int $from,

        int $to,

        array $vacationCenter,

        string $hamdastAccessToken,

        ?array $conflictBody

    ): int {

        $bookIds = Paziresh24VacationApi::extractBookIdsFromConflictBody($conflictBody);

        if ($bookIds === []) {

            return 0;

        }



        $resolved = 0;

        foreach ($bookIds as $bookId) {

            if (

                self::resolveSingleBookConflict(

                    $userId,

                    $tokenRow,

                    $bookId,

                    $vacationCenter,

                    $hamdastAccessToken,

                    $from,

                    $to

                )

            ) {

                $resolved++;

            }

        }



        return $resolved;

    }



    /**

     * @param array<string, mixed> $tokenRow

     * @param array{medical_center_id: string, user_center_id: ?string, name: string} $vacationCenter

     */

    private static function resolveSingleBookConflict(

        string $userId,

        array $tokenRow,

        string $bookId,

        array $vacationCenter,

        string $hamdastAccessToken,

        int $vacationFrom,

        int $vacationTo

    ): bool {

        $bookId = trim($bookId);

        if ($bookId === '') {

            return false;

        }



        $appointmentCenterId = BookingAppointmentResolver::resolveAppointmentMedicalCenterId(

            $bookId,

            $hamdastAccessToken

        );

        if (

            $appointmentCenterId === null

            || $appointmentCenterId !== $vacationCenter['medical_center_id']

        ) {

            return false;

        }



        if (GoogleTokensRepository::isCancelConflictingAppointmentsEnabled($tokenRow)) {

            return self::cancelSingleOverlappingAppointment(

                $userId,

                $tokenRow,

                $bookId,

                $hamdastAccessToken

            );

        }



        $moveRange = Paziresh24AppointmentApi::resolveMoveRange($hamdastAccessToken, $bookId, 0, 0);

        if ($moveRange['from'] <= 0 || $moveRange['to'] <= $moveRange['from']) {

            return false;

        }



        return self::rescheduleSingleOverlappingAppointment(

            $userId,

            $bookId,

            $moveRange['from'],

            $moveRange['to'],

            $vacationCenter['medical_center_id'],

            $vacationCenter,

            $hamdastAccessToken,

            $vacationFrom,

            $vacationTo

        );

    }



    /**

     * @param array<string, mixed> $tokenRow

     */

    private static function cancelSingleOverlappingAppointment(

        string $userId,

        array $tokenRow,

        string $bookId,

        string $hamdastAccessToken

    ): bool {

        $response = Paziresh24AppointmentApi::deleteAppointmentResult($hamdastAccessToken, $bookId);

        if (!$response['success']) {

            if ($response['permission_denied']) {

                error_log(

                    '[google-vacation] cannot cancel overlapping appointment: missing provider.appointment.write book_id='

                    . $bookId

                );

            }



            return false;

        }



        self::syncAppointmentCalendarAfterCancel($userId, $bookId);



        error_log('[google-vacation] cancelled overlapping appointment book_id=' . $bookId);



        return true;

    }



    private static function syncAppointmentCalendarAfterCancel(string $userId, string $bookId): void

    {

        try {

            $result = AppointmentWebhookService::handleCancel(

                ['book_id' => $bookId],

                $userId,

                $bookId

            );



            error_log(

                '[google-vacation] calendar cleaned after cancel book_id=' . $bookId

                . ' result=' . json_encode($result, JSON_UNESCAPED_UNICODE)

            );

        } catch (Throwable $e) {

            error_log(

                '[google-vacation] calendar sync after cancel failed book_id=' . $bookId

                . ' error=' . $e->getMessage()

            );

        }

    }



    /**

     * @param array<string, mixed> $tokenRow

     * @param array{medical_center_id: string, user_center_id: ?string, name: string} $vacationCenter

     */

    private static function cancelOverlappingAppointments(

        string $userId,

        array $tokenRow,

        int $from,

        int $to,

        array $vacationCenter,

        string $hamdastAccessToken

    ): int {

        $googleAccessToken = self::refreshGoogleAccessToken($tokenRow);

        if ($googleAccessToken === null) {

            return 0;

        }



        $targets = self::findOverlappingAppointments(

            $userId,

            $googleAccessToken,

            $hamdastAccessToken,

            $from,

            $to,

            $vacationCenter

        );



        $cancelled = 0;

        foreach ($targets as $target) {

            if (

                self::cancelSingleOverlappingAppointment(

                    $userId,

                    $tokenRow,

                    $target['book_id'],

                    $hamdastAccessToken

                )

            ) {

                $cancelled++;

            }

        }



        error_log(

            '[google-vacation] cancelled overlapping appointments count=' . $cancelled

            . ' from=' . $from

            . ' to=' . $to

            . ' center=' . $vacationCenter['medical_center_id']

        );



        return $cancelled;

    }



    private static function rangesOverlap(int $startA, int $endA, int $startB, int $endB): bool

    {

        return $startA < $endB && $startB < $endA;

    }

}


