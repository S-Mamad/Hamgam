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



        // When no sync token exists yet, bound the fallback listing to recent/future
        // events so a large calendar history can't stall the webhook and prevent the
        // token (and the new vacation event) from being processed. Ignored once a
        // sync token is present.
        $fallbackTimeMin = gmdate('Y-m-d\TH:i:s\Z', time() - (2 * 86400));

        $listResult = GoogleCalendarWatch::listChangedEvents($googleAccessToken, $syncToken, $fallbackTimeMin);

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



            self::syncSingleEvent(
                $userId,
                $tokenRow,
                $event,
                $autoVacation,
                $vacationCenters,
                $hamdastAccessToken,
                false,
                $googleAccessToken
            );

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

                true,

                $googleAccessToken

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
     * Daily roll-forward: sync vacation events on the Iran calendar day 30 days ahead.
     *
     * @return array{users: int, imported: int, skipped: int, failed: int}
     */
    public static function runDailyRollingVacationSync(?int $now = null): array
    {
        $now = $now ?? time();
        $summary = ['users' => 0, 'imported' => 0, 'skipped' => 0, 'failed' => 0];
        $dayBounds = GoogleEventParser::resolveRollingSyncTargetDayBounds($now);
        $timeMin = gmdate('Y-m-d\TH:i:s\Z', $dayBounds['from_ts']);
        $timeMax = gmdate('Y-m-d\TH:i:s\Z', $dayBounds['to_ts']);

        foreach (GoogleVacationRepository::findUsersWithAutoVacationEnabled() as $tokenRow) {
            if (!is_array($tokenRow)) {
                continue;
            }

            $userId = (string) ($tokenRow['paziresh24_user_id'] ?? '');
            $refreshToken = (string) ($tokenRow['google_refresh_token'] ?? '');
            $hamdastAccessToken = (string) ($tokenRow['hamdast_access_token'] ?? '');

            if ($userId === '' || $refreshToken === '') {
                continue;
            }

            $summary['users']++;

            $googleTokenData = GoogleCalendar::refreshAccessToken($refreshToken);
            $googleAccessToken = is_array($googleTokenData) ? ($googleTokenData['access_token'] ?? '') : '';
            if (!is_string($googleAccessToken) || $googleAccessToken === '') {
                error_log('[google-vacation] rolling sync token refresh failed user=' . $userId);
                $summary['failed']++;
                continue;
            }

            $events = GoogleCalendarWatch::listEventsInRange($googleAccessToken, $timeMin, $timeMax);
            if ($events === []) {
                continue;
            }

            $autoVacation = GoogleVacationRepository::isAutoVacationEnabled($tokenRow);
            $vacationCenters = self::resolveVacationCentersForUser($userId, $tokenRow, $hamdastAccessToken);
            if ($vacationCenters === []) {
                error_log('[google-vacation] rolling sync skipped: no vacation centers user=' . $userId);
                continue;
            }

            foreach (self::groupEventsForVacationSync($events) as $group) {
                if (($group['type'] ?? '') === 'single') {
                    $event = $group['event'] ?? null;
                    if (!is_array($event)) {
                        continue;
                    }

                    $result = self::syncSingleEvent(
                        $userId,
                        $tokenRow,
                        $event,
                        $autoVacation,
                        $vacationCenters,
                        $hamdastAccessToken,
                        false,
                        $googleAccessToken
                    );
                } else {
                    $seriesEvents = $group['events'] ?? [];
                    if (!is_array($seriesEvents) || $seriesEvents === []) {
                        continue;
                    }

                    $seriesKey = is_string($group['seriesKey'] ?? null) ? $group['seriesKey'] : '';
                    if ($seriesKey === '') {
                        continue;
                    }

                    $representative = $seriesEvents[0];
                    if (!is_array($representative)) {
                        continue;
                    }

                    $result = self::syncRecurringSeriesEvent(
                        $userId,
                        $tokenRow,
                        $representative,
                        $seriesKey,
                        $autoVacation,
                        $vacationCenters,
                        $hamdastAccessToken,
                        false,
                        $seriesEvents,
                        $googleAccessToken
                    );
                }

                if ($result === 'created' || $result === 'updated') {
                    $summary['imported']++;
                } elseif ($result === 'failed') {
                    $summary['failed']++;
                } else {
                    $summary['skipped']++;
                }
            }

            GoogleTokensRepository::saveImportBackfillWindowEnd(
                $userId,
                GoogleEventParser::resolveVacationSyncHorizonBounds($now)['to_ts']
            );
        }

        error_log(
            '[google-vacation] rolling sync completed users=' . $summary['users']
            . ' imported=' . $summary['imported']
            . ' skipped=' . $summary['skipped']
            . ' failed=' . $summary['failed']
            . ' target_from=' . $dayBounds['from_ts']
        );

        return $summary;
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

        bool $trackAsBackfill = false,

        string $googleAccessToken = ''

    ): string {

        $eventId = GoogleEventParser::extractEventId($googleEvent);

        $isDeletedPreview = self::isDeletedGoogleEvent($googleEvent, null);



        if (!$isDeletedPreview && $eventId !== null) {

            $googleEvent = self::hydrateGoogleEventForVacationSync(

                $googleEvent,

                $tokenRow,

                $googleAccessToken

            );

        }



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

            self::revertHamgamAppointmentCalendarToApi(

                $userId,

                $googleEvent,

                $parsed,

                $hamdastAccessToken

            );

            return 'skipped';

        }



        $seriesKey = GoogleEventParser::extractRecurringSeriesKey($googleEvent);

        if ($seriesKey !== null) {

            if (GoogleVacationRepository::hasProcessedEvent($userId, $seriesKey)) {

                $legacyRows = GoogleVacationRepository::findProcessedEventsForGoogleEvent($userId, $seriesKey);

                if (self::isLegacyCollapsedSeriesTracking($legacyRows)) {

                    self::dissolveLegacyCollapsedSeriesVacation(

                        $userId,

                        $seriesKey,

                        $tokenRow,

                        $hamdastAccessToken

                    );

                }

            }



            return self::syncRecurringSeriesEvent(

                $userId,

                $tokenRow,

                $googleEvent,

                $seriesKey,

                $autoVacation,

                $vacationCenters,

                $hamdastAccessToken,

                $trackAsBackfill,

                null,

                $googleAccessToken

            );

        }



        if (
            !GoogleVacationRepository::hasProcessedEvent($userId, $parsed['event_id'])
            && !GoogleEventParser::eventOverlapsVacationSyncHorizon($parsed)
        ) {

            error_log('[google-vacation] skipped event outside 30-day horizon ' . $parsed['event_id']);

            return 'skipped';

        }



        return self::syncIndividualVacationEvent(

            $userId,

            $tokenRow,

            $parsed,

            $autoVacation,

            $vacationCenters,

            $hamdastAccessToken,

            $googleEvent,

            null,

            $trackAsBackfill

        );

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

     * @return 'created'|'updated'|'skipped'|'failed'

     */

    private static function syncIndividualVacationEvent(

        string $userId,

        array $tokenRow,

        array $parsed,

        bool $autoVacation,

        array $vacationCenters,

        string $hamdastAccessToken,

        array $googleEvent,

        ?string $seriesKey,

        bool $trackAsBackfill = false

    ): string {

        if (!GoogleVacationRepository::hasProcessedEvent($userId, $parsed['event_id'])) {

            if ($seriesKey !== null) {

                $reconciled = self::tryReconcileMovedRecurringInstance(

                    $userId,

                    $parsed,

                    $seriesKey,

                    $autoVacation,

                    $vacationCenters,

                    $tokenRow,

                    $hamdastAccessToken,

                    $trackAsBackfill

                );

                if ($reconciled !== null) {

                    return $reconciled;

                }

            }

        }



        if (GoogleVacationRepository::hasProcessedEvent($userId, $parsed['event_id'])) {

            $updated = self::processUpdatedEvent(

                $userId,

                $parsed,

                $autoVacation,

                $vacationCenters,

                $tokenRow,

                $hamdastAccessToken,

                $trackAsBackfill,

                $seriesKey

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

                $hamdastAccessToken,

                $parsed['event_id']

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

                self::attachGoogleSyncMetadata($response, $parsed, $seriesKey),

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

     * @param array<string, mixed> $tokenRow

     * @param array<string, mixed> $googleEvent

     * @param array<int, array{medical_center_id: string, user_center_id: ?string, name: string}> $vacationCenters

     * @param array<int, array<string, mixed>>|null $prefetchedInstances

     * @return 'created'|'updated'|'skipped'|'failed'

     */

    private static function syncRecurringSeriesEvent(

        string $userId,

        array $tokenRow,

        array $googleEvent,

        string $seriesKey,

        bool $autoVacation,

        array $vacationCenters,

        string $hamdastAccessToken,

        bool $trackAsBackfill = false,

        ?array $prefetchedInstances = null,

        string $googleAccessToken = ''

    ): string {

        $eventId = GoogleEventParser::extractEventId($googleEvent);

        if ($eventId !== null && !self::isDeletedGoogleEvent($googleEvent, null)) {

            $googleEvent = self::hydrateGoogleEventForVacationSync($googleEvent, $tokenRow, $googleAccessToken);

        }



        $parsed = GoogleEventParser::parseEvent($googleEvent);

        $isDeleted = self::isDeletedGoogleEvent($googleEvent, $parsed);



        if ($isDeleted) {

            $isWholeSeriesDelete = $eventId === null

                || $eventId === ''

                || $eventId === $seriesKey

                || GoogleEventParser::extractRecurringSeriesKey($googleEvent) === $eventId;



            if (!$isWholeSeriesDelete) {

                $remainingInstances = self::resolveRecurringInstances($tokenRow, $seriesKey, $prefetchedInstances);

                $aggregatedRemaining = GoogleEventParser::aggregateRecurringInstances($remainingInstances, $seriesKey);

                if ($aggregatedRemaining !== null) {

                    if (GoogleVacationRepository::hasProcessedEvent($userId, $seriesKey)) {

                        $updated = self::processUpdatedRecurringSeries(

                            $userId,

                            $aggregatedRemaining,

                            $autoVacation,

                            $vacationCenters,

                            $tokenRow,

                            $hamdastAccessToken,

                            $trackAsBackfill

                        );



                        return $updated ? 'updated' : 'skipped';

                    }



                    return 'skipped';

                }

            }



            self::processDeletedEvent($userId, $seriesKey, $parsed, $tokenRow, $hamdastAccessToken);



            if (GoogleEventParser::isHamgamAppointmentEvent($googleEvent)) {

                self::processDeletedAppointmentEvent($userId, $tokenRow, $googleEvent, $hamdastAccessToken);

            }



            return 'skipped';

        }



        if (GoogleEventParser::isHamgamAppointmentEvent($googleEvent)) {

            return 'skipped';

        }



        $masterEvent = isset($googleEvent['recurrence']) && is_array($googleEvent['recurrence'])

            ? $googleEvent

            : null;

        $instances = self::resolveRecurringInstances($tokenRow, $seriesKey, $prefetchedInstances);

        if (!GoogleEventParser::shouldAggregateRecurringVacationSeries($masterEvent, $instances)) {

            if ($parsed === null) {

                return 'skipped';

            }



            if (
                !GoogleVacationRepository::hasProcessedEvent($userId, $parsed['event_id'])
                && !GoogleEventParser::eventOverlapsVacationSyncHorizon($parsed)
            ) {

                error_log(

                    '[google-vacation] skipped recurring instance outside 30-day horizon '

                    . $parsed['event_id']

                );

                return 'skipped';

            }



            return self::syncIndividualVacationEvent(

                $userId,

                $tokenRow,

                $parsed,

                $autoVacation,

                $vacationCenters,

                $hamdastAccessToken,

                $googleEvent,

                $seriesKey,

                $trackAsBackfill

            );

        }



        $aggregated = GoogleEventParser::aggregateRecurringInstances($instances, $seriesKey);

        if ($aggregated === null) {

            error_log('[google-vacation] recurring series has no active instances series=' . $seriesKey);

            return 'skipped';

        }



        error_log(

            '[google-vacation] recurring series aggregated id=' . $seriesKey

            . ' instances=' . count($instances)

            . ' from=' . $aggregated['start_ts']

            . ' to=' . $aggregated['end_ts']

        );



        if (GoogleVacationRepository::hasProcessedEvent($userId, $seriesKey)) {

            $updated = self::processUpdatedRecurringSeries(

                $userId,

                $aggregated,

                $autoVacation,

                $vacationCenters,

                $tokenRow,

                $hamdastAccessToken,

                $trackAsBackfill

            );



            return $updated ? 'updated' : 'skipped';

        }



        if (!$autoVacation) {

            error_log('[google-vacation] auto_vacation off, no Paziresh24 action for recurring ' . $seriesKey);

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

                $aggregated['start_ts'],

                $aggregated['end_ts'],

                $vacationCenter,

                $hamdastAccessToken,

                $seriesKey

            );



            if ($response === null) {

                error_log(

                    '[google-vacation] vacation create failed for recurring series ' . $seriesKey

                    . ' center=' . $vacationCenter['medical_center_id']

                );

                $failedCount++;

                continue;

            }



            self::migrateRecurringTrackingToSeriesKey(

                $userId,

                $seriesKey,

                $vacationCenter['medical_center_id'],

                $tokenRow,

                $hamdastAccessToken

            );



            GoogleVacationRepository::recordProcessedEvent(

                $userId,

                $seriesKey,

                $aggregated['summary'],

                $aggregated['start_ts'],

                $aggregated['end_ts'],

                self::attachCollapsedSeriesMetadata($response),

                $vacationCenter['medical_center_id']

            );

            if ($trackAsBackfill) {
                ImportFutureVacationsRepository::upsertBackfillSlotForEvent(
                    $userId,
                    $seriesKey,
                    $vacationCenter['medical_center_id'],
                    $aggregated['start_ts'],
                    $aggregated['end_ts']
                );
            }



            error_log(

                '[google-vacation] vacation created for recurring series ' . $seriesKey

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

     * @param array<int, array<string, mixed>> $events

     * @return array<int, array{

     *   type: 'single',

     *   event: array<string, mixed>

     * }|array{

     *   type: 'recurring',

     *   seriesKey: string,

     *   events: array<int, array<string, mixed>>

     * }>

     */

    private static function groupEventsForVacationSync(array $events): array

    {

        $recurringGroups = [];

        $result = [];

        $seenSingleIds = [];



        foreach ($events as $event) {

            if (!is_array($event)) {

                continue;

            }



            $seriesKey = GoogleEventParser::extractRecurringSeriesKey($event);

            if ($seriesKey !== null) {

                if (!isset($recurringGroups[$seriesKey])) {

                    $recurringGroups[$seriesKey] = [];

                }

                $recurringGroups[$seriesKey][] = $event;

                continue;

            }



            $eventId = GoogleEventParser::extractEventId($event);

            if ($eventId === null || isset($seenSingleIds[$eventId])) {

                continue;

            }



            $seenSingleIds[$eventId] = true;

            $result[] = [

                'type' => 'single',

                'event' => $event,

            ];

        }



        foreach ($recurringGroups as $seriesKey => $groupEvents) {

            $result[] = [

                'type' => 'recurring',

                'seriesKey' => $seriesKey,

                'events' => $groupEvents,

            ];

        }



        return $result;

    }



    /**

     * @param array<int, array<string, mixed>>|null $prefetchedInstances

     * @return array<int, array<string, mixed>>

     */

    private static function resolveRecurringInstances(

        array $tokenRow,

        string $seriesKey,

        ?array $prefetchedInstances = null

    ): array {

        $googleAccessToken = self::refreshGoogleAccessToken($tokenRow);

        if ($googleAccessToken === null) {

            return self::fallbackPrefetchedRecurringInstances($prefetchedInstances, $seriesKey);

        }



        $masterEvent = GoogleCalendarWatch::getEvent($googleAccessToken, $seriesKey);

        $window = GoogleEventParser::resolveRecurringInstancesVacationSyncWindow(

            $masterEvent,

            $prefetchedInstances

        );



        $instances = GoogleCalendarWatch::listRecurringEventInstances(

            $googleAccessToken,

            $seriesKey,

            $window['time_min'],

            $window['time_max']

        );



        if ($instances !== []) {

            $instances = GoogleEventParser::filterRecurringInstancesToHorizon($instances);



            error_log(

                '[google-vacation] recurring instances fetched count=' . count($instances)

                . ' series=' . $seriesKey

                . ' from=' . $window['time_min']

                . ' to=' . $window['time_max']

            );



            return $instances;

        }



        if ($masterEvent !== null) {

            $parsedMaster = GoogleEventParser::parseEvent($masterEvent);

            if (
                $parsedMaster !== null
                && GoogleEventParser::eventOverlapsVacationSyncHorizon($parsedMaster)
                && GoogleEventParser::aggregateRecurringInstances([$masterEvent], $seriesKey) !== null
            ) {

                return [$masterEvent];

            }

        }



        return self::fallbackPrefetchedRecurringInstances($prefetchedInstances, $seriesKey);

    }



    /**

     * @param array<int, array<string, mixed>>|null $prefetchedInstances

     * @return array<int, array<string, mixed>>

     */

    private static function fallbackPrefetchedRecurringInstances(?array $prefetchedInstances, string $seriesKey): array

    {

        if ($prefetchedInstances === null || $prefetchedInstances === []) {

            return [];

        }



        error_log(

            '[google-vacation] recurring instances API unavailable, using prefetched slice series='

            . $seriesKey

            . ' count=' . count($prefetchedInstances)

        );



        return GoogleEventParser::filterRecurringInstancesToHorizon($prefetchedInstances);

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

    private static function processUpdatedRecurringSeries(

        string $userId,

        array $parsed,

        bool $autoVacation,

        array $vacationCenters,

        array $tokenRow,

        string $hamdastAccessToken,

        bool $trackAsBackfill = false

    ): bool {

        $seriesKey = $parsed['event_id'];

        $trackedRows = GoogleVacationRepository::findProcessedEventsRelatedToGoogleEvent($userId, $seriesKey);

        if ($trackedRows === []) {

            error_log('[google-vacation] recurring update skipped: tracked rows missing for ' . $seriesKey);

            return false;

        }



        $fallbackCenterId = isset($tokenRow['center_id']) && is_string($tokenRow['center_id'])

            ? trim($tokenRow['center_id'])

            : null;



        $rowsByCenter = self::groupTrackedRowsByMedicalCenter($trackedRows, $fallbackCenterId);

        $newFrom = $parsed['start_ts'];

        $newTo = $parsed['end_ts'];

        $anyUpdated = false;



        foreach ($rowsByCenter as $medicalCenterId => $centerRows) {

            $primary = $centerRows[0];

            $oldFrom = isset($primary['vacation_from']) ? (int) $primary['vacation_from'] : 0;

            $oldTo = isset($primary['vacation_to']) ? (int) $primary['vacation_to'] : 0;



            if (count($centerRows) > 1) {

                self::removeDuplicateRecurringTrackedRows(

                    $userId,

                    $seriesKey,

                    $medicalCenterId,

                    $centerRows,

                    $hamdastAccessToken,

                    $primary

                );

            }



            $trackedEventId = is_string($primary['google_event_id'] ?? null) && $primary['google_event_id'] !== ''

                ? $primary['google_event_id']

                : $seriesKey;



            if ($oldFrom <= 0 || $oldTo <= 0 || $newFrom <= 0 || $newTo <= $newFrom) {

                error_log(

                    '[google-vacation] recurring update skipped: invalid timestamps for ' . $seriesKey

                    . ' center=' . $medicalCenterId

                );

                continue;

            }



            if ($oldFrom === $newFrom && $oldTo === $newTo) {

                $oldSummary = is_string($primary['event_summary'] ?? null) ? $primary['event_summary'] : '';

                if ($oldSummary !== $parsed['summary'] || $trackedEventId !== $seriesKey) {

                    GoogleVacationRepository::recordProcessedEvent(

                        $userId,

                        $seriesKey,

                        $parsed['summary'],

                        $newFrom,

                        $newTo,

                        self::decodeStoredResponse($primary),

                        $medicalCenterId

                    );

                    $anyUpdated = true;

                }



                if ($trackAsBackfill) {

                    ImportFutureVacationsRepository::upsertBackfillSlotForEvent(

                        $userId,

                        $seriesKey,

                        $medicalCenterId,

                        $newFrom,

                        $newTo

                    );

                    $anyUpdated = true;

                } elseif (

                    ImportFutureVacationsRepository::hasActiveBackfillSlotForEvent(

                        $userId,

                        $trackedEventId,

                        $medicalCenterId

                    )

                    || ImportFutureVacationsRepository::hasActiveBackfillSlotForEvent(

                        $userId,

                        $seriesKey,

                        $medicalCenterId

                    )

                ) {

                    ImportFutureVacationsRepository::syncBackfillSlotForEvent(

                        $userId,

                        $seriesKey,

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

                error_log('[google-vacation] auto_vacation off, no recurring update for ' . $seriesKey);

                continue;

            }



            if ($hamdastAccessToken === '') {

                error_log('[google-vacation] missing hamdast token for recurring update user ' . $userId);

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



            $response = self::replaceIndividualVacationByDeleteAndCreate(

                $userId,

                $tokenRow,

                $seriesKey,

                $oldFrom,

                $oldTo,

                $newFrom,

                $newTo,

                $vacationCenter,

                $hamdastAccessToken

            );



            if ($response === null) {

                error_log(

                    '[google-vacation] recurring vacation update failed for ' . $seriesKey

                    . ' center=' . $medicalCenterId

                );

                continue;

            }



            GoogleVacationRepository::recordProcessedEvent(

                $userId,

                $seriesKey,

                $parsed['summary'],

                $newFrom,

                $newTo,

                $response,

                $medicalCenterId

            );

            if ($trackAsBackfill) {

                ImportFutureVacationsRepository::syncBackfillSlotForEvent(

                    $userId,

                    $seriesKey,

                    $medicalCenterId,

                    $newFrom,

                    $newTo,

                    $oldFrom,

                    $oldTo

                );

            }



            error_log(

                '[google-vacation] recurring vacation updated for ' . $seriesKey

                . ' center=' . $medicalCenterId

                . ' old_from=' . $oldFrom

                . ' old_to=' . $oldTo

                . ' from=' . $newFrom

                . ' to=' . $newTo

            );

            $anyUpdated = true;

        }



        return $anyUpdated;

    }



    /**

     * @param array<int, array<string, mixed>> $trackedRows

     * @return array<string, array<int, array<string, mixed>>>

     */

    private static function groupTrackedRowsByMedicalCenter(array $trackedRows, ?string $fallbackCenterId): array

    {

        $grouped = [];



        foreach ($trackedRows as $tracked) {

            $medicalCenterId = GoogleVacationRepository::resolveTrackedMedicalCenterId($tracked, $fallbackCenterId);

            if ($medicalCenterId === '') {

                continue;

            }



            if (!isset($grouped[$medicalCenterId])) {

                $grouped[$medicalCenterId] = [];

            }

            $grouped[$medicalCenterId][] = $tracked;

        }



        foreach ($grouped as $medicalCenterId => $rows) {

            usort(

                $rows,

                static function (array $left, array $right): int {

                    $leftId = is_string($left['google_event_id'] ?? null) ? $left['google_event_id'] : '';

                    $rightId = is_string($right['google_event_id'] ?? null) ? $right['google_event_id'] : '';

                    if ($leftId === $rightId) {

                        return ((int) ($left['id'] ?? 0)) <=> ((int) ($right['id'] ?? 0));

                    }



                    $leftIsMaster = !str_contains($leftId, '_');

                    $rightIsMaster = !str_contains($rightId, '_');

                    if ($leftIsMaster !== $rightIsMaster) {

                        return $leftIsMaster ? -1 : 1;

                    }



                    return strcmp($leftId, $rightId);

                }

            );

            $grouped[$medicalCenterId] = $rows;

        }



        return $grouped;

    }



    /**

     * @param array<int, array<string, mixed>> $centerRows

     * @param array<string, mixed> $keepRow

     */

    private static function removeDuplicateRecurringTrackedRows(

        string $userId,

        string $seriesKey,

        string $medicalCenterId,

        array $centerRows,

        string $hamdastAccessToken,

        array $keepRow

    ): void {

        $keepEventId = is_string($keepRow['google_event_id'] ?? null) ? $keepRow['google_event_id'] : $seriesKey;



        foreach ($centerRows as $index => $tracked) {

            if ($index === 0) {

                continue;

            }



            $from = isset($tracked['vacation_from']) ? (int) $tracked['vacation_from'] : 0;

            $to = isset($tracked['vacation_to']) ? (int) $tracked['vacation_to'] : 0;

            $trackedEventId = is_string($tracked['google_event_id'] ?? null) ? $tracked['google_event_id'] : '';



            if ($from > 0 && $to > $from && $trackedEventId !== '' && $trackedEventId !== $keepEventId) {

                Paziresh24VacationApi::deleteVacation(

                    $hamdastAccessToken,

                    $medicalCenterId,

                    $from,

                    $to

                );

            }



            if ($trackedEventId !== '') {

                GoogleVacationRepository::removeProcessedEvent($userId, $trackedEventId, $medicalCenterId);

                ImportFutureVacationsRepository::markBackfillSlotsDeletedByEvent(

                    $userId,

                    $trackedEventId,

                    $medicalCenterId

                );

            }

        }

    }



    private static function migrateRecurringTrackingToSeriesKey(

        string $userId,

        string $seriesKey,

        string $medicalCenterId,

        array $tokenRow,

        string $hamdastAccessToken

    ): void {

        $trackedRows = GoogleVacationRepository::findProcessedEventsRelatedToGoogleEvent($userId, $seriesKey);

        if ($trackedRows === []) {

            return;

        }



        $fallbackCenterId = isset($tokenRow['center_id']) && is_string($tokenRow['center_id'])

            ? trim($tokenRow['center_id'])

            : null;



        $rowsByCenter = self::groupTrackedRowsByMedicalCenter($trackedRows, $fallbackCenterId);

        $centerRows = $rowsByCenter[$medicalCenterId] ?? [];

        if ($centerRows === []) {

            return;

        }



        $instanceRows = [];

        foreach ($centerRows as $tracked) {

            $trackedEventId = is_string($tracked['google_event_id'] ?? null) ? trim($tracked['google_event_id']) : '';

            if ($trackedEventId !== '' && $trackedEventId !== $seriesKey) {

                $instanceRows[] = $tracked;

            }

        }



        if ($instanceRows === []) {

            return;

        }



        foreach ($instanceRows as $tracked) {

            $from = isset($tracked['vacation_from']) ? (int) $tracked['vacation_from'] : 0;

            $to = isset($tracked['vacation_to']) ? (int) $tracked['vacation_to'] : 0;

            $trackedEventId = is_string($tracked['google_event_id'] ?? null) ? $tracked['google_event_id'] : '';



            if ($from > 0 && $to > $from && $hamdastAccessToken !== '') {

                Paziresh24VacationApi::deleteVacation($hamdastAccessToken, $medicalCenterId, $from, $to);

            }



            if ($trackedEventId !== '') {

                GoogleVacationRepository::removeProcessedEvent($userId, $trackedEventId, $medicalCenterId);

                ImportFutureVacationsRepository::markBackfillSlotsDeletedByEvent(

                    $userId,

                    $trackedEventId,

                    $medicalCenterId

                );

            }

        }



        error_log(

            '[google-vacation] collapsed per-instance vacations into series=' . $seriesKey

            . ' center=' . $medicalCenterId

            . ' removed=' . count($instanceRows)

        );

    }



    /**

     * Removes a legacy collapsed recurring vacation (stored under series master id).

     *

     * @param array<string, mixed> $tokenRow

     */

    private static function dissolveLegacyCollapsedSeriesVacation(

        string $userId,

        string $seriesKey,

        array $tokenRow,

        string $hamdastAccessToken

    ): void {

        $trackedRows = GoogleVacationRepository::findProcessedEventsForGoogleEvent($userId, $seriesKey);

        if ($trackedRows === []) {

            return;

        }



        $fallbackCenterId = isset($tokenRow['center_id']) && is_string($tokenRow['center_id'])

            ? trim($tokenRow['center_id'])

            : null;



        foreach ($trackedRows as $tracked) {

            $from = isset($tracked['vacation_from']) ? (int) $tracked['vacation_from'] : 0;

            $to = isset($tracked['vacation_to']) ? (int) $tracked['vacation_to'] : 0;

            $medicalCenterId = GoogleVacationRepository::resolveTrackedMedicalCenterId($tracked, $fallbackCenterId);



            if ($medicalCenterId === '') {

                continue;

            }



            if ($hamdastAccessToken !== '' && $from > 0 && $to > $from) {

                Paziresh24VacationApi::deleteVacation(

                    $hamdastAccessToken,

                    $medicalCenterId,

                    $from,

                    $to

                );

            }



            GoogleVacationRepository::removeProcessedEvent($userId, $seriesKey, $medicalCenterId);

            ImportFutureVacationsRepository::markBackfillSlotsDeletedByEvent(

                $userId,

                $seriesKey,

                $medicalCenterId

            );

        }



        error_log('[google-vacation] dissolved legacy collapsed recurring vacation series=' . $seriesKey);

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

        bool $trackAsBackfill = false,

        ?string $seriesKey = null

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



            if (self::shouldSkipStaleGoogleEventUpdate($parsed, $tracked)) {

                error_log(

                    '[google-vacation] update skipped: stale google revision for event ' . $eventId

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

                        self::attachGoogleSyncMetadata(self::decodeStoredResponse($tracked), $parsed, $seriesKey),

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



            $response = self::replaceIndividualVacationByDeleteAndCreate(

                $userId,

                $tokenRow,

                $eventId,

                $oldFrom,

                $oldTo,

                $newFrom,

                $newTo,

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

                self::attachGoogleSyncMetadata($response, $parsed, $seriesKey),

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

     * @param array<string, mixed> $googleEvent

     * @param array<string, mixed> $tokenRow

     * @return array<string, mixed>

     */

    private static function hydrateGoogleEventForVacationSync(

        array $googleEvent,

        array $tokenRow,

        string $googleAccessToken

    ): array {

        $eventId = GoogleEventParser::extractEventId($googleEvent);

        if ($eventId === null) {

            return $googleEvent;

        }



        $accessToken = $googleAccessToken;

        if ($accessToken === '') {

            $refreshToken = (string) ($tokenRow['google_refresh_token'] ?? '');

            if ($refreshToken === '') {

                return $googleEvent;

            }



            $tokenData = GoogleCalendar::refreshAccessToken($refreshToken);

            $accessToken = is_array($tokenData) ? (string) ($tokenData['access_token'] ?? '') : '';

        }



        if ($accessToken === '') {

            return $googleEvent;

        }



        $fullEvent = GoogleCalendarWatch::getEvent($accessToken, $eventId);

        if ($fullEvent === null) {

            return $googleEvent;

        }



        $partialParsed = GoogleEventParser::parseEvent($googleEvent);

        $fullParsed = GoogleEventParser::parseEvent($fullEvent);

        if (

            $partialParsed !== null

            && $fullParsed !== null

            && $partialParsed['start_ts'] !== $fullParsed['start_ts']

        ) {

            error_log(

                '[google-vacation] hydrated event times id=' . $eventId

                . ' partial_from=' . $partialParsed['start_ts']

                . ' full_from=' . $fullParsed['start_ts']

            );

        }



        return $fullEvent;

    }



    /**

     * @param array{medical_center_id: string, user_center_id: ?string, name: string} $vacationCenter

     * @return array<string, mixed>|null

     */

    private static function replaceIndividualVacationByDeleteAndCreate(

        string $userId,

        array $tokenRow,

        string $googleEventId,

        int $oldFrom,

        int $oldTo,

        int $newFrom,

        int $newTo,

        array $vacationCenter,

        string $hamdastAccessToken

    ): ?array {

        if ($newFrom <= 0 || $newTo <= $newFrom) {

            return null;

        }



        $medicalCenterId = $vacationCenter['medical_center_id'];



        if (

            !self::clearConflictingAppointmentsBeforeVacation(

                $userId,

                $tokenRow,

                $newFrom,

                $newTo,

                $vacationCenter,

                $hamdastAccessToken

            )

        ) {

            error_log(

                '[google-vacation] vacation replace aborted: overlapping appointments not moved event='

                . $googleEventId

                . ' center='

                . $medicalCenterId

            );



            return null;

        }



        if ($oldFrom > 0 && $oldTo > $oldFrom && $hamdastAccessToken !== '') {

            Paziresh24VacationApi::deleteVacation($hamdastAccessToken, $medicalCenterId, $oldFrom, $oldTo);

        }



        return self::createVacationWithConflictResolution(

            $userId,

            $tokenRow,

            $newFrom,

            $newTo,

            $vacationCenter,

            $hamdastAccessToken,

            $googleEventId

        );

    }



    /**

     * @return array<string, mixed>|null

     */

    private static function attachCollapsedSeriesMetadata(?array $response): ?array

    {

        $payload = is_array($response) ? $response : [];

        $payload['_hamgam_collapsed_series'] = true;



        return $payload;

    }



    private static function isVacationRangeContained(

        int $innerFrom,

        int $innerTo,

        int $outerFrom,

        int $outerTo

    ): bool {

        if ($innerFrom <= 0 || $innerTo <= $innerFrom || $outerFrom <= 0 || $outerTo <= $outerFrom) {

            return false;

        }



        return $outerFrom <= $innerFrom && $outerTo >= $innerTo;

    }



    /**

     * @return array{

     *   google_event_id: string,

     *   medical_center_id: string,

     *   vacation_from: int,

     *   vacation_to: int

     * }|null

     */

    private static function findCoveringTrackedVacation(

        string $userId,

        string $medicalCenterId,

        int $from,

        int $to,

        string $excludeEventId

    ): ?array {

        if ($from <= 0 || $to <= $from || $medicalCenterId === '') {

            return null;

        }



        $tracked = GoogleVacationRepository::listAllTrackedVacationsForDeletion($userId, $medicalCenterId);

        $bestMatch = null;

        $bestSpan = 0;



        foreach ($tracked as $row) {

            if (!is_array($row)) {

                continue;

            }



            $eventId = is_string($row['google_event_id'] ?? null) ? trim($row['google_event_id']) : '';

            $centerId = is_string($row['medical_center_id'] ?? null) ? trim($row['medical_center_id']) : '';

            $outerFrom = (int) ($row['vacation_from'] ?? 0);

            $outerTo = (int) ($row['vacation_to'] ?? 0);



            if ($eventId === '' || $eventId === $excludeEventId || $centerId !== $medicalCenterId) {

                continue;

            }



            if (!self::isVacationRangeContained($from, $to, $outerFrom, $outerTo)) {

                continue;

            }



            $span = $outerTo - $outerFrom;

            if ($bestMatch === null || $span > $bestSpan) {

                $bestMatch = [

                    'google_event_id' => $eventId,

                    'medical_center_id' => $centerId,

                    'vacation_from' => $outerFrom,

                    'vacation_to' => $outerTo,

                ];

                $bestSpan = $span;

            }

        }



        return $bestMatch;

    }



    private static function isTrackedVacationCovered(array $tracked): bool

    {

        $stored = self::decodeStoredResponse($tracked);



        return is_array($stored)

            && is_string($stored['_hamgam_covered_by_event_id'] ?? null)

            && trim($stored['_hamgam_covered_by_event_id']) !== '';

    }



    private static function deleteStandalonePaziresh24Vacation(

        string $hamdastAccessToken,

        string $medicalCenterId,

        int $from,

        int $to

    ): void {

        if ($hamdastAccessToken === '' || $from <= 0 || $to <= $from) {

            return;

        }



        Paziresh24VacationApi::deleteVacation($hamdastAccessToken, $medicalCenterId, $from, $to);

    }



    /**

     * @param array{

     *   google_event_id: string,

     *   medical_center_id: string,

     *   vacation_from: int,

     *   vacation_to: int

     * } $coveringVacation

     */

    private static function recordCoveredVacationTracking(

        string $userId,

        array $parsed,

        ?string $seriesKey,

        array $coveringVacation,

        string $medicalCenterId,

        ?array $previousResponse = null

    ): void {

        GoogleVacationRepository::recordProcessedEvent(

            $userId,

            $parsed['event_id'],

            $parsed['summary'],

            $parsed['start_ts'],

            $parsed['end_ts'],

            self::attachGoogleSyncMetadata($previousResponse, $parsed, $seriesKey, $coveringVacation),

            $medicalCenterId

        );

    }



    private static function absorbContainedTrackedVacations(

        string $userId,

        string $parentEventId,

        int $parentFrom,

        int $parentTo,

        string $medicalCenterId,

        string $hamdastAccessToken,

        ?string $seriesKey = null

    ): void {

        if ($parentFrom <= 0 || $parentTo <= $parentFrom || $medicalCenterId === '') {

            return;

        }



        $tracked = GoogleVacationRepository::listAllTrackedVacationsForDeletion($userId, $medicalCenterId);

        $parentCovering = [

            'google_event_id' => $parentEventId,

            'medical_center_id' => $medicalCenterId,

            'vacation_from' => $parentFrom,

            'vacation_to' => $parentTo,

        ];



        foreach ($tracked as $row) {

            if (!is_array($row)) {

                continue;

            }



            $childEventId = is_string($row['google_event_id'] ?? null) ? trim($row['google_event_id']) : '';

            if ($childEventId === '' || $childEventId === $parentEventId) {

                continue;

            }



            $childFrom = (int) ($row['vacation_from'] ?? 0);

            $childTo = (int) ($row['vacation_to'] ?? 0);

            if (!self::isVacationRangeContained($childFrom, $childTo, $parentFrom, $parentTo)) {

                continue;

            }



            $childRow = GoogleVacationRepository::findProcessedEvent($userId, $childEventId, $medicalCenterId);

            if ($childRow === null) {

                continue;

            }



            if (self::isTrackedVacationCovered($childRow)) {

                continue;

            }



            self::deleteStandalonePaziresh24Vacation($hamdastAccessToken, $medicalCenterId, $childFrom, $childTo);



            $childParsed = [

                'event_id' => $childEventId,

                'summary' => is_string($childRow['event_summary'] ?? null) ? $childRow['event_summary'] : '',

                'start_ts' => $childFrom,

                'end_ts' => $childTo,

                'updated' => null,

            ];



            self::recordCoveredVacationTracking(

                $userId,

                $childParsed,

                null,

                $parentCovering,

                $medicalCenterId,

                self::decodeStoredResponse($childRow)

            );



            error_log(

                '[google-vacation] absorbed nested vacation child=' . $childEventId

                . ' into parent=' . $parentEventId

                . ' center=' . $medicalCenterId

            );

        }

    }



    /**

     * @param array<string, mixed> $tokenRow

     * @param array{medical_center_id: string, user_center_id: ?string, name: string} $vacationCenter

     * @return array<string, mixed>|null

     */

    private static function createVacationUnlessCovered(

        string $userId,

        string $googleEventId,

        array $tokenRow,

        int $from,

        int $to,

        array $vacationCenter,

        string $hamdastAccessToken,

        ?string $seriesKey = null

    ): ?array {

        return self::createVacationWithConflictResolution(

            $userId,

            $tokenRow,

            $from,

            $to,

            $vacationCenter,

            $hamdastAccessToken,

            $googleEventId

        );

    }



    /**

     * @param array<string, mixed> $tracked

     * @param array{

     *   google_event_id: string,

     *   medical_center_id: string,

     *   vacation_from: int,

     *   vacation_to: int

     * }|null $coveringNew

     * @param array{

     *   google_event_id: string,

     *   medical_center_id: string,

     *   vacation_from: int,

     *   vacation_to: int

     * }|null $coveringOld

     * @return 'updated'|'failed'|null null = continue with normal Paziresh24 update

     */

    private static function applyVacationMoveWithCoverageRules(

        string $userId,

        array $parsed,

        ?string $seriesKey,

        array $tracked,

        int $oldFrom,

        int $oldTo,

        int $newFrom,

        int $newTo,

        string $medicalCenterId,

        array $tokenRow,

        array $vacationCenters,

        string $hamdastAccessToken,

        bool $trackAsBackfill,

        ?array $coveringNew,

        ?array $coveringOld

    ): ?string {

        $eventId = $parsed['event_id'];

        $wasCovered = self::isTrackedVacationCovered($tracked);



        if ($coveringNew !== null) {

            if (!$wasCovered && $coveringOld === null) {

                self::deleteStandalonePaziresh24Vacation($hamdastAccessToken, $medicalCenterId, $oldFrom, $oldTo);

            }



            self::recordCoveredVacationTracking(

                $userId,

                $parsed,

                $seriesKey,

                $coveringNew,

                $medicalCenterId,

                self::decodeStoredResponse($tracked)

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

                '[google-vacation] vacation covered by larger event child=' . $eventId

                . ' parent=' . $coveringNew['google_event_id']

                . ' center=' . $medicalCenterId

            );



            return 'updated';

        }



        if ($wasCovered || $coveringOld !== null) {

            $vacationCenter = self::findVacationCenterByMedicalId($vacationCenters, $medicalCenterId);

            if ($vacationCenter === null) {

                $vacationCenter = [

                    'medical_center_id' => $medicalCenterId,

                    'user_center_id' => null,

                    'name' => '',

                ];

            }



            $response = self::createVacationUnlessCovered(

                $userId,

                $eventId,

                $tokenRow,

                $newFrom,

                $newTo,

                $vacationCenter,

                $hamdastAccessToken,

                $seriesKey

            );



            if ($response === null) {

                return 'failed';

            }



            GoogleVacationRepository::recordProcessedEvent(

                $userId,

                $eventId,

                $parsed['summary'],

                $newFrom,

                $newTo,

                self::attachGoogleSyncMetadata($response, $parsed, $seriesKey),

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

                '[google-vacation] vacation moved out of covering parent event=' . $eventId

                . ' center=' . $medicalCenterId

            );



            return 'updated';

        }



        return null;

    }



    /**

     * @param array<int, array<string, mixed>> $trackedRows

     */

    private static function isLegacyCollapsedSeriesTracking(array $trackedRows): bool

    {

        foreach ($trackedRows as $tracked) {

            if (!is_array($tracked)) {

                continue;

            }



            $eventId = is_string($tracked['google_event_id'] ?? null) ? $tracked['google_event_id'] : '';

            if ($eventId === '' || str_contains($eventId, '_')) {

                continue;

            }



            $from = isset($tracked['vacation_from']) ? (int) $tracked['vacation_from'] : 0;

            $to = isset($tracked['vacation_to']) ? (int) $tracked['vacation_to'] : 0;

            if ($from <= 0 || $to <= $from) {

                continue;

            }



            $stored = self::decodeStoredResponse($tracked);

            if (is_array($stored) && ($stored['_hamgam_collapsed_series'] ?? false) === true) {

                return false;

            }



            if (($to - $from) > (36 * 3600)) {

                return true;

            }

        }



        return false;

    }



    /**

     * When a recurring instance is dragged to a new day Google may issue a new instance id

     * before the delete webhook arrives. Reconcile by updating the existing vacation slot.

     *

     * @param array{

     *   event_id: string,

     *   summary: string,

     *   start_ts: int,

     *   end_ts: int,

     *   updated: ?string

     * } $parsed

     * @param array<int, array{medical_center_id: string, user_center_id: ?string, name: string}> $vacationCenters

     * @param array<string, mixed> $tokenRow

     * @return 'created'|'updated'|'skipped'|'failed'|null

     */

    private static function tryReconcileMovedRecurringInstance(

        string $userId,

        array $parsed,

        string $seriesKey,

        bool $autoVacation,

        array $vacationCenters,

        array $tokenRow,

        string $hamdastAccessToken,

        bool $trackAsBackfill

    ): ?string {

        $related = GoogleVacationRepository::findProcessedEventsRelatedToGoogleEvent($userId, $seriesKey);

        if ($related === []) {

            return null;

        }



        $duration = $parsed['end_ts'] - $parsed['start_ts'];

        if ($duration <= 0) {

            return null;

        }



        $candidates = [];

        foreach ($related as $row) {

            if (!is_array($row)) {

                continue;

            }



            $rowEventId = is_string($row['google_event_id'] ?? null) ? trim($row['google_event_id']) : '';

            if ($rowEventId === '' || $rowEventId === $parsed['event_id'] || $rowEventId === $seriesKey) {

                continue;

            }



            $rowFrom = isset($row['vacation_from']) ? (int) $row['vacation_from'] : 0;

            $rowTo = isset($row['vacation_to']) ? (int) $row['vacation_to'] : 0;

            if ($rowFrom <= 0 || $rowTo <= $rowFrom) {

                continue;

            }



            if (abs(($rowTo - $rowFrom) - $duration) > 120) {

                continue;

            }



            $candidates[] = $row;

        }



        if (count($candidates) !== 1) {

            return null;

        }



        if (!$autoVacation || $hamdastAccessToken === '') {

            return 'skipped';

        }



        $candidate = $candidates[0];

        $oldEventId = is_string($candidate['google_event_id'] ?? null) ? $candidate['google_event_id'] : '';

        $oldFrom = (int) ($candidate['vacation_from'] ?? 0);

        $oldTo = (int) ($candidate['vacation_to'] ?? 0);

        $fallbackCenterId = isset($tokenRow['center_id']) && is_string($tokenRow['center_id'])

            ? trim($tokenRow['center_id'])

            : null;

        $medicalCenterId = GoogleVacationRepository::resolveTrackedMedicalCenterId($candidate, $fallbackCenterId);



        if ($medicalCenterId === '') {

            return null;

        }



        if ($oldFrom === $parsed['start_ts'] && $oldTo === $parsed['end_ts']) {

            GoogleVacationRepository::removeProcessedEvent($userId, $oldEventId, $medicalCenterId);

            GoogleVacationRepository::recordProcessedEvent(

                $userId,

                $parsed['event_id'],

                $parsed['summary'],

                $parsed['start_ts'],

                $parsed['end_ts'],

                self::attachGoogleSyncMetadata(self::decodeStoredResponse($candidate), $parsed, $seriesKey),

                $medicalCenterId

            );



            error_log(

                '[google-vacation] recurring instance id migrated series=' . $seriesKey

                . ' old_id=' . $oldEventId

                . ' new_id=' . $parsed['event_id']

            );



            return 'updated';

        }



        $vacationCenter = self::findVacationCenterByMedicalId($vacationCenters, $medicalCenterId);

        if ($vacationCenter === null) {

            $vacationCenter = [

                'medical_center_id' => $medicalCenterId,

                'user_center_id' => null,

                'name' => '',

            ];

        }



        $response = self::replaceIndividualVacationByDeleteAndCreate(

            $userId,

            $tokenRow,

            $parsed['event_id'],

            $oldFrom,

            $oldTo,

            $parsed['start_ts'],

            $parsed['end_ts'],

            $vacationCenter,

            $hamdastAccessToken

        );



        if ($response === null) {

            return 'failed';

        }



        GoogleVacationRepository::removeProcessedEvent($userId, $oldEventId, $medicalCenterId);

        GoogleVacationRepository::recordProcessedEvent(

            $userId,

            $parsed['event_id'],

            $parsed['summary'],

            $parsed['start_ts'],

            $parsed['end_ts'],

            self::attachGoogleSyncMetadata($response, $parsed, $seriesKey),

            $medicalCenterId

        );



        if ($trackAsBackfill) {

            ImportFutureVacationsRepository::syncBackfillSlotForEvent(

                $userId,

                $parsed['event_id'],

                $medicalCenterId,

                $parsed['start_ts'],

                $parsed['end_ts'],

                $oldFrom,

                $oldTo

            );

        }



        error_log(

            '[google-vacation] recurring instance move reconciled series=' . $seriesKey

            . ' old_id=' . $oldEventId

            . ' new_id=' . $parsed['event_id']

            . ' old_from=' . $oldFrom

            . ' from=' . $parsed['start_ts']

        );



        return 'updated';

    }



    /**

     * @param array{updated: ?string} $parsed

     */

    private static function resolveGoogleUpdatedTs(array $parsed): ?int

    {

        $updated = $parsed['updated'] ?? null;

        if (!is_string($updated) || $updated === '') {

            return null;

        }



        try {

            return (new DateTimeImmutable($updated))->getTimestamp();

        } catch (Throwable) {

            return null;

        }

    }



    /**

     * @param array<string, mixed>|null $response

     * @param array{

     *   updated: ?string,

     *   start_ts: int,

     *   end_ts: int

     * } $parsed

     * @return array<string, mixed>|null

     */

    private static function attachGoogleSyncMetadata(
        ?array $response,
        array $parsed,
        ?string $seriesKey = null,
        ?array $coveringVacation = null
    ): ?array {

        $payload = is_array($response) ? $response : [];



        $updatedTs = self::resolveGoogleUpdatedTs($parsed);

        if ($updatedTs !== null) {

            $payload['_hamgam_google_updated'] = $updatedTs;

        }



        $payload['_hamgam_event_start_ts'] = $parsed['start_ts'];

        $payload['_hamgam_event_end_ts'] = $parsed['end_ts'];



        if (is_string($seriesKey) && $seriesKey !== '') {

            $payload['_hamgam_recurring_series_id'] = $seriesKey;

        }



        if (is_array($coveringVacation)) {

            $coveringEventId = is_string($coveringVacation['google_event_id'] ?? null)

                ? trim($coveringVacation['google_event_id'])

                : '';

            if ($coveringEventId !== '') {

                $payload['_hamgam_covered_by_event_id'] = $coveringEventId;

                $payload['_hamgam_covered_by_from'] = (int) ($coveringVacation['vacation_from'] ?? 0);

                $payload['_hamgam_covered_by_to'] = (int) ($coveringVacation['vacation_to'] ?? 0);

            }

        } else {

            unset(

                $payload['_hamgam_covered_by_event_id'],

                $payload['_hamgam_covered_by_from'],

                $payload['_hamgam_covered_by_to']

            );

        }



        return $payload;

    }



    /**

     * @param array{updated: ?string} $parsed

     * @param array<string, mixed> $tracked

     */

    private static function shouldSkipStaleGoogleEventUpdate(array $parsed, array $tracked): bool

    {

        $stored = self::decodeStoredResponse($tracked);

        if ($stored === null) {

            return false;

        }



        $storedUpdated = $stored['_hamgam_google_updated'] ?? null;

        if (!is_numeric($storedUpdated)) {

            return false;

        }



        $incomingUpdatedTs = self::resolveGoogleUpdatedTs($parsed);

        if ($incomingUpdatedTs === null) {

            return false;

        }



        return $incomingUpdatedTs < (int) $storedUpdated;

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

     * When the doctor drags a Hamgam appointment in Google Calendar, move the real Paziresh24 slot.

     * @param array<string, mixed> $tokenRow

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

     */

    private static function processUpdatedAppointmentEvent(

        string $userId,

        array $tokenRow,

        array $googleEvent,

        array $parsed,

        string $hamdastAccessToken

    ): bool {

        // DISABLED: جابه‌جایی دستی نوبت در Google Calendar نباید زمان نوبت را در پذیرش۲۴ تغییر دهد.
        return false;

        /*

        $eventId = $parsed['event_id'];

        $bookId = GoogleEventParser::extractBookId($googleEvent);



        if ($bookId === null || $bookId === '') {

            error_log('[google-vacation] appointment move skipped: missing book_id event=' . $eventId);

            return false;

        }



        if ($hamdastAccessToken === '') {

            error_log('[google-vacation] appointment move skipped: missing hamdast token user=' . $userId);

            return false;

        }



        if (!Paziresh24AppointmentApi::hasAppointmentWriteScope($hamdastAccessToken)) {

            error_log(

                '[google-vacation] appointment move blocked: missing provider.appointment.write book_id='

                . $bookId

            );

            return false;

        }



        $moveRange = Paziresh24AppointmentApi::resolveMoveRange($hamdastAccessToken, $bookId, 0, 0);

        if ($moveRange['from'] <= 0 || $moveRange['to'] <= $moveRange['from']) {

            error_log('[google-vacation] appointment move skipped: invalid API range book_id=' . $bookId);

            return false;

        }



        $targetFrom = $parsed['start_ts'];

        if (self::appointmentTimestampsMatch($moveRange['from'], $targetFrom)) {

            return false;

        }



        $appointment = GoogleCalendar::getAppointment($bookId, $hamdastAccessToken);

        $medicalCenterId = BookingAppointmentResolver::resolveAppointmentMedicalCenterId(

            $bookId,

            $hamdastAccessToken,

            $googleEvent

        );

        if ($medicalCenterId === null || $medicalCenterId === '') {

            error_log('[google-vacation] appointment move skipped: missing center book_id=' . $bookId);

            return false;

        }



        $hintUserCenterId = is_array($appointment)

            ? BookingAppointmentResolver::extractUserCenterId($appointment)

            : null;

        $userCenterId = BookingAppointmentResolver::resolveUserCenterIdForReschedule(

            is_array($appointment) ? $appointment : null,

            $hamdastAccessToken,

            $medicalCenterId,

            $hintUserCenterId

        );



        error_log(

            '[google-vacation] appointment calendar move detected book_id=' . $bookId

            . ' event=' . $eventId

            . ' api_from=' . $moveRange['from']

            . ' api_to=' . $moveRange['to']

            . ' calendar_from=' . $targetFrom

            . ' center=' . $medicalCenterId

            . ' user_center_id=' . ($userCenterId ?? '')

        );



        $moveResult = Paziresh24AppointmentApi::moveAppointmentWithCenterFallback(

            $hamdastAccessToken,

            $medicalCenterId,

            $userCenterId,

            $moveRange['from'],

            $moveRange['to'],

            $targetFrom

        );



        if (!$moveResult['success']) {

            if ($moveResult['permission_denied'] ?? false) {

                error_log(

                    '[google-vacation] appointment move blocked: re-auth required book_id=' . $bookId

                );

            } else {

                error_log(

                    '[google-vacation] appointment move failed book_id=' . $bookId

                    . ' http=' . ($moveResult['http_status'] ?? 0)

                    . ' book_from=' . $moveRange['from']

                    . ' book_to=' . $moveRange['to']

                    . ' target_from=' . $targetFrom

                );

            }



            self::revertAppointmentCalendarToApi($userId, $bookId, $hamdastAccessToken);

            return false;

        }



        error_log(

            '[google-vacation] appointment moved from calendar drag book_id=' . $bookId

            . ' book_from=' . $moveRange['from']

            . ' book_to=' . $moveRange['to']

            . ' target_from=' . $targetFrom

            . ' center_path=' . ($moveResult['center_id_used'] ?? '')

        );



        self::syncAppointmentCalendarAfterReschedule($userId, $bookId, $hamdastAccessToken);

        return true;

        */

    }



    private static function appointmentTimestampsMatch(int $apiTimestamp, int $calendarTimestamp): bool

    {

        return abs($apiTimestamp - $calendarTimestamp) < 60;

    }



    private static function revertAppointmentCalendarToApi(

        string $userId,

        string $bookId,

        string $hamdastAccessToken

    ): void {

        // DISABLED: فقط برای بازگرداندن تقویم پس از شکست جابه‌جایی دستی نوبت استفاده می‌شد.
        // self::syncAppointmentCalendarAfterReschedule($userId, $bookId, $hamdastAccessToken);

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

        string $hamdastAccessToken,

        string $googleEventId = ''

    ): ?array {

        if (

            !self::clearConflictingAppointmentsBeforeVacation(

                $userId,

                $tokenRow,

                $from,

                $to,

                $vacationCenter,

                $hamdastAccessToken

            )

        ) {

            error_log(

                '[google-vacation] vacation create aborted: overlapping appointments not moved book_id=center='

                . $vacationCenter['medical_center_id']

                . ' from=' . $from

                . ' to=' . $to

            );

            return null;

        }



        $maxAttempts = 3;

        $result = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {

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



            if (!$result['book_conflict'] || $attempt >= $maxAttempts) {

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

        }



        return null;

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

        string $hamdastAccessToken,

        string $googleEventId = '',

        ?string $seriesKey = null

    ): ?array {

        $medicalCenterId = $vacationCenter['medical_center_id'];



        if (

            !self::clearConflictingAppointmentsBeforeVacation(

                $userId,

                $tokenRow,

                $from,

                $to,

                $vacationCenter,

                $hamdastAccessToken

            )

        ) {

            return null;

        }



        $maxAttempts = 3;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {

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

                return self::createVacationUnlessCovered(

                    $userId,

                    $googleEventId !== '' ? $googleEventId : 'unknown-event',

                    $tokenRow,

                    $from,

                    $to,

                    $vacationCenter,

                    $hamdastAccessToken,

                    $seriesKey

                );

            }



            if (!$result['book_conflict'] || $attempt >= $maxAttempts) {

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

     * Move or cancel every appointment overlapping the vacation window before Paziresh24 accepts the vacation.

     *

     * @param array<string, mixed> $tokenRow

     * @param array{medical_center_id: string, user_center_id: ?string, name: string} $vacationCenter

     */

    private static function clearConflictingAppointmentsBeforeVacation(

        string $userId,

        array $tokenRow,

        int $from,

        int $to,

        array $vacationCenter,

        string $hamdastAccessToken

    ): bool {

        Paziresh24AppointmentApi::resetAppointmentCache();

        $googleAccessToken = self::refreshGoogleAccessToken($tokenRow);

        if ($googleAccessToken === null) {

            return false;

        }



        $targets = self::findOverlappingAppointments(

            $userId,

            $googleAccessToken,

            $hamdastAccessToken,

            $from,

            $to,

            $vacationCenter

        );



        if ($targets === []) {

            return true;

        }



        $expected = count($targets);



        if (GoogleTokensRepository::isCancelConflictingAppointmentsEnabled($tokenRow)) {

            $cancelled = self::cancelOverlappingAppointments(

                $userId,

                $tokenRow,

                $from,

                $to,

                $vacationCenter,

                $hamdastAccessToken,

                $targets

            );



            if ($cancelled < $expected) {

                error_log(

                    '[google-vacation] cancel before vacation incomplete cancelled='

                    . $cancelled

                    . ' expected='

                    . $expected

                    . ' center='

                    . $vacationCenter['medical_center_id']

                );



                Paziresh24AppointmentApi::resetAppointmentCache();

                return false;

            }



            Paziresh24AppointmentApi::resetAppointmentCache();

            return true;

        }



        if (!Paziresh24AppointmentApi::hasAppointmentWriteScope($hamdastAccessToken)) {

            error_log(

                '[google-vacation] reschedule before vacation blocked: missing provider.appointment.write center='

                . $vacationCenter['medical_center_id']

            );



            return false;

        }



        $rescheduled = self::rescheduleOverlappingAppointments(

            $userId,

            $tokenRow,

            $from,

            $to,

            $vacationCenter,

            $hamdastAccessToken,

            $targets

        );



        if ($rescheduled < $expected) {

            error_log(

                '[google-vacation] reschedule before vacation incomplete moved='

                . $rescheduled

                . ' expected='

                . $expected

                . ' center='

                . $vacationCenter['medical_center_id']

            );



            Paziresh24AppointmentApi::resetAppointmentCache();

            return false;

        }



        Paziresh24AppointmentApi::resetAppointmentCache();

        return true;

    }



    /**

     * @param array<string, mixed> $tokenRow

     * @param array{medical_center_id: string, user_center_id: ?string, name: string} $vacationCenter

     * @param array<int, array{

     *   book_id: string,

     *   event: array<string, mixed>,

     *   from: int,

     *   to: int,

     *   medical_center_id: string

     * }>|null $prefetchedTargets

     */

    private static function rescheduleOverlappingAppointments(

        string $userId,

        array $tokenRow,

        int $from,

        int $to,

        array $vacationCenter,

        string $hamdastAccessToken,

        ?array $prefetchedTargets = null

    ): int {

        $googleAccessToken = self::refreshGoogleAccessToken($tokenRow);

        if ($googleAccessToken === null) {

            return 0;

        }



        $targets = $prefetchedTargets ?? self::findOverlappingAppointments(

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

        Paziresh24AppointmentApi::resetAppointmentCache();

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



        if (!Paziresh24AppointmentApi::hasAppointmentWriteScope($hamdastAccessToken)) {

            error_log(

                '[google-vacation] book conflict reschedule blocked: missing provider.appointment.write center='

                . $vacationCenter['medical_center_id']

            );



            return 0;

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

        Paziresh24AppointmentApi::invalidateAppointmentCache($bookId);

        $freshRange = Paziresh24AppointmentApi::resolveMoveRange($hamdastAccessToken, $bookId, $bookFrom, $bookTo);

        if ($freshRange['from'] > 0 && $freshRange['to'] > $freshRange['from']) {

            $bookFrom = $freshRange['from'];

            $bookTo = $freshRange['to'];

        }



        $apiOverlapsVacation = $bookFrom > 0

            && $bookTo > $bookFrom

            && self::rangesOverlap($bookFrom, $bookTo, $vacationFrom, $vacationTo);



        if (!$apiOverlapsVacation) {

            self::syncAppointmentCalendarAfterReschedule($userId, $bookId, $hamdastAccessToken);

            error_log(

                '[google-vacation] stale calendar appointment synced (api no longer overlaps) book_id=' . $bookId

            );

            return true;

        }



        $appointment = Paziresh24AppointmentApi::fetchAppointmentForMove($bookId, $hamdastAccessToken);

        $hintUserCenterId = is_array($appointment)

            ? BookingAppointmentResolver::extractUserCenterId($appointment)

            : null;

        if (($hintUserCenterId === null || $hintUserCenterId === '') && isset($vacationCenter['user_center_id'])) {

            $userCenterFromVacation = $vacationCenter['user_center_id'];

            if (is_string($userCenterFromVacation) && trim($userCenterFromVacation) !== '') {

                $hintUserCenterId = trim($userCenterFromVacation);

            }

        }



        $result = Paziresh24AppointmentApi::rescheduleToFirstAvailableSlot(

            $hamdastAccessToken,

            $bookId,

            $medicalCenterId,

            $hintUserCenterId,

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



    private static function revertHamgamAppointmentCalendarToApi(

        string $userId,

        array $googleEvent,

        array $parsed,

        string $hamdastAccessToken

    ): void {

        $bookId = GoogleEventParser::extractBookId($googleEvent);

        if ($bookId === null || $bookId === '' || $hamdastAccessToken === '') {

            return;

        }



        $appointment = GoogleCalendar::getAppointment($bookId, $hamdastAccessToken);

        if (!is_array($appointment)) {

            return;

        }



        $apiRange = BookingAppointmentResolver::extractRangeFromPayload($appointment);

        if ($apiRange === null) {

            return;

        }



        $calendarDiff = abs($apiRange['from'] - $parsed['start_ts']);

        if ($calendarDiff < 60) {

            return;

        }



        error_log(

            '[google-vacation] reverting calendar appointment to API time book_id='

            . $bookId

            . ' calendar_from=' . $parsed['start_ts']

            . ' api_from=' . $apiRange['from']

        );



        try {

            AppointmentWebhookService::syncCalendarFromApiMove($userId, $bookId);

        } catch (Throwable $e) {

            error_log(

                '[google-vacation] calendar revert failed book_id=' . $bookId

                . ' error=' . $e->getMessage()

            );

        }

    }



    private static function syncAppointmentCalendarAfterReschedule(

        string $userId,

        string $bookId,

        string $hamdastAccessToken

    ): void {

        Paziresh24AppointmentApi::invalidateAppointmentCache($bookId);

        $appointment = GoogleCalendar::getAppointment($bookId, $hamdastAccessToken);

        if (!is_array($appointment)) {

            error_log('[google-vacation] calendar sync after reschedule skipped: appointment fetch book_id=' . $bookId);

            return;

        }



        try {

            $updateResult = AppointmentWebhookService::syncCalendarFromApiMove($userId, $bookId);

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

        $targets = [];

        $seenBookIds = [];



        $addTarget = static function (?array $target) use (&$targets, &$seenBookIds): void {

            if ($target === null) {

                return;

            }



            $bookId = $target['book_id'] ?? '';

            if (!is_string($bookId) || $bookId === '' || isset($seenBookIds[$bookId])) {

                return;

            }



            $seenBookIds[$bookId] = true;

            $targets[] = $target;

        };



        $timeMin = gmdate('Y-m-d\TH:i:s\Z', $from - 3600);

        $timeMax = gmdate('Y-m-d\TH:i:s\Z', $to + 3600);

        $events = GoogleCalendarWatch::listEventsInRange($googleAccessToken, $timeMin, $timeMax);

        $eventsByBookId = [];



        foreach ($events as $event) {

            if (!is_array($event)) {

                continue;

            }



            $bookIdHint = GoogleEventParser::extractBookId($event);

            if ($bookIdHint === null || $bookIdHint === '') {

                $eventId = GoogleEventParser::extractEventId($event);

                if ($eventId !== null) {

                    $bookIdHint = GoogleCalendarBookingRepository::getBookIdByGoogleEventId($userId, $eventId);

                }

            }



            if (is_string($bookIdHint) && $bookIdHint !== '') {

                $eventsByBookId[$bookIdHint] = $event;

            }



            $addTarget(self::buildOverlappingAppointmentTarget(

                $event,

                $from,

                $to,

                $medicalCenterId,

                $hamdastAccessToken,

                is_string($bookIdHint) && $bookIdHint !== '' ? $bookIdHint : null

            ));

        }



        foreach (GoogleCalendarBookingRepository::listBookIdsForUser($userId) as $bookId) {

            if (isset($seenBookIds[$bookId])) {

                continue;

            }



            $addTarget(self::buildOverlappingAppointmentTargetFromApi(

                $bookId,

                $from,

                $to,

                $medicalCenterId,

                $hamdastAccessToken,

                $eventsByBookId[$bookId] ?? null

            ));

        }



        error_log(

            '[google-vacation] overlapping appointments found=' . count($targets)

            . ' center=' . $medicalCenterId

            . ' vacation_from=' . $from

            . ' vacation_to=' . $to

        );



        return $targets;

    }



    /**

     * @return array{

     *   book_id: string,

     *   event: array<string, mixed>,

     *   from: int,

     *   to: int,

     *   medical_center_id: string

     * }|null

     */

    private static function buildOverlappingAppointmentTargetFromApi(

        string $bookId,

        int $from,

        int $to,

        string $medicalCenterId,

        string $hamdastAccessToken,

        ?array $googleEvent = null

    ): ?array {

        $bookId = trim($bookId);

        if ($bookId === '') {

            return null;

        }



        $overlapRange = self::resolveOverlappingAppointmentRange(

            $bookId,

            $hamdastAccessToken,

            $from,

            $to,

            $googleEvent

        );

        if ($overlapRange === null) {

            return null;

        }



        $cachedAppointment = Paziresh24AppointmentApi::fetchAppointmentForMove($bookId, $hamdastAccessToken);

        $appointmentCenterId = is_array($cachedAppointment)

            ? BookingAppointmentResolver::extractCenterId($cachedAppointment)

            : null;

        if ($appointmentCenterId === null || $appointmentCenterId === '') {

            $appointmentCenterId = BookingAppointmentResolver::resolveAppointmentMedicalCenterId(

                $bookId,

                $hamdastAccessToken

            );

        }

        if ($appointmentCenterId === null || $appointmentCenterId !== $medicalCenterId) {

            return null;

        }



        return [

            'book_id' => $bookId,

            'event' => [],

            'from' => $overlapRange['from'],

            'to' => $overlapRange['to'],

            'medical_center_id' => $appointmentCenterId,

        ];

    }



    /**

     * @param ?array<string, mixed> $googleEvent

     * @return array{from: int, to: int}|null

     */

    private static function resolveOverlappingAppointmentRange(

        string $bookId,

        string $hamdastAccessToken,

        int $vacationFrom,

        int $vacationTo,

        ?array $googleEvent = null

    ): ?array {

        $bookId = trim($bookId);

        if ($bookId === '') {

            return null;

        }



        $moveRange = Paziresh24AppointmentApi::resolveMoveRange($hamdastAccessToken, $bookId, 0, 0);

        $hasApiRange = $moveRange['from'] > 0 && $moveRange['to'] > $moveRange['from'];



        $calendarFrom = null;

        $calendarTo = null;

        if ($googleEvent !== null) {

            $parsed = GoogleEventParser::parseEvent($googleEvent);

            if ($parsed !== null) {

                $calendarFrom = $parsed['start_ts'];

                $calendarTo = $parsed['end_ts'];

            }

        }



        $overlapsVacation = false;

        if (

            $hasApiRange

            && self::rangesOverlap($moveRange['from'], $moveRange['to'], $vacationFrom, $vacationTo)

        ) {

            $overlapsVacation = true;

        }

        if (

            $calendarFrom !== null

            && $calendarTo !== null

            && $calendarTo > $calendarFrom

            && self::rangesOverlap($calendarFrom, $calendarTo, $vacationFrom, $vacationTo)

        ) {

            $overlapsVacation = true;

        }



        if (!$overlapsVacation) {

            return null;

        }



        if (

            $hasApiRange

            && self::rangesOverlap($moveRange['from'], $moveRange['to'], $vacationFrom, $vacationTo)

        ) {

            return [

                'from' => $moveRange['from'],

                'to' => $moveRange['to'],

            ];

        }



        if (

            $calendarFrom !== null

            && $calendarTo !== null

            && $calendarTo > $calendarFrom

            && self::rangesOverlap($calendarFrom, $calendarTo, $vacationFrom, $vacationTo)

        ) {

            return [

                'from' => $calendarFrom,

                'to' => $calendarTo,

            ];

        }



        return null;

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



        $overlapRange = self::resolveOverlappingAppointmentRange(

            $bookId,

            $hamdastAccessToken,

            $from,

            $to,

            $event

        );

        if ($overlapRange === null) {

            return null;

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

            'from' => $overlapRange['from'],

            'to' => $overlapRange['to'],

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

        string $hamdastAccessToken,

        ?array $prefetchedTargets = null

    ): int {

        $googleAccessToken = self::refreshGoogleAccessToken($tokenRow);

        if ($googleAccessToken === null) {

            return 0;

        }



        $targets = $prefetchedTargets ?? self::findOverlappingAppointments(

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


