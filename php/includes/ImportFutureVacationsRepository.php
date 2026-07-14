<?php

declare(strict_types=1);

/**
 * Permanent per-doctor lock for the 30-day import feature and undoable backfill slots.
 */
final class ImportFutureVacationsRepository
{
    public static function isDoctorLocked(string $doctorId): bool
    {
        $doctorId = GoogleTokensRepository::normalizeUserId($doctorId);
        if ($doctorId === '') {
            return false;
        }

        $stmt = Database::connection()->prepare(
            'SELECT 1 FROM import_future_vacations_doctor_lock
             WHERE paziresh24_user_id = :doctor_id
             LIMIT 1'
        );
        $stmt->execute(['doctor_id' => $doctorId]);

        return $stmt->fetchColumn() !== false;
    }

    public static function lockDoctor(string $doctorId): void
    {
        $doctorId = GoogleTokensRepository::normalizeUserId($doctorId);
        if ($doctorId === '') {
            return;
        }

        if (Database::isMysql()) {
            $stmt = Database::connection()->prepare(
                'INSERT IGNORE INTO import_future_vacations_doctor_lock (paziresh24_user_id, used_at)
                 VALUES (:doctor_id, CURRENT_TIMESTAMP)'
            );
        } elseif (Database::isPgsql()) {
            $stmt = Database::connection()->prepare(
                'INSERT INTO import_future_vacations_doctor_lock (paziresh24_user_id, used_at)
                 VALUES (:doctor_id, CURRENT_TIMESTAMP)
                 ON CONFLICT (paziresh24_user_id) DO NOTHING'
            );
        } else {
            $stmt = Database::connection()->prepare(
                'INSERT OR IGNORE INTO import_future_vacations_doctor_lock (paziresh24_user_id, used_at)
                 VALUES (:doctor_id, CURRENT_TIMESTAMP)'
            );
        }

        $stmt->execute(['doctor_id' => $doctorId]);
    }

    public static function unlockDoctor(string $doctorId): void
    {
        $doctorId = GoogleTokensRepository::normalizeUserId($doctorId);
        if ($doctorId === '') {
            return;
        }

        $stmt = Database::connection()->prepare(
            'DELETE FROM import_future_vacations_doctor_lock
             WHERE paziresh24_user_id = :doctor_id'
        );
        $stmt->execute(['doctor_id' => $doctorId]);
    }

    public static function hasActiveBackfillSlots(string $doctorId): bool
    {
        return self::countActiveBackfillSlots($doctorId) > 0;
    }

    public static function countActiveBackfillSlots(string $doctorId): int
    {
        return GoogleVacationRepository::countTrackedVacations($doctorId);
    }

    /**
     * Merge undoable backfill slots with tracked google_event_vacations rows.
     *
     * @return array<int, array{
     *   id: int,
     *   google_event_id: string,
     *   medical_center_id: string,
     *   vacation_from: int,
     *   vacation_to: int
     * }>
     */
    public static function listDeletableImportVacationTargets(string $doctorId): array
    {
        $doctorId = GoogleTokensRepository::normalizeUserId($doctorId);
        if ($doctorId === '') {
            return [];
        }

        $targets = [];
        $seen = [];

        foreach (self::listActiveBackfillSlots($doctorId) as $slot) {
            $dedupeKey = $slot['medical_center_id'] . '|' . $slot['vacation_from'] . '|' . $slot['vacation_to'];
            if (isset($seen[$dedupeKey])) {
                continue;
            }

            $seen[$dedupeKey] = true;
            $targets[] = $slot;
        }

        foreach (GoogleVacationRepository::listTrackedVacationDeletionTargets($doctorId) as $tracked) {
            $dedupeKey = $tracked['medical_center_id'] . '|' . $tracked['vacation_from'] . '|' . $tracked['vacation_to'];
            if (isset($seen[$dedupeKey])) {
                continue;
            }

            $seen[$dedupeKey] = true;
            $targets[] = [
                'id' => 0,
                'google_event_id' => $tracked['google_event_id'],
                'medical_center_id' => $tracked['medical_center_id'],
                'vacation_from' => $tracked['vacation_from'],
                'vacation_to' => $tracked['vacation_to'],
            ];
        }

        return $targets;
    }

    public static function reconcileBackfillSlotsFromTrackedEvents(string $doctorId): int
    {
        $created = 0;

        foreach (GoogleVacationRepository::listTrackedVacationDeletionTargets($doctorId) as $tracked) {
            $eventId = trim($tracked['google_event_id']);
            if ($eventId === '') {
                continue;
            }

            if (
                self::hasActiveBackfillSlotForEvent(
                    $doctorId,
                    $eventId,
                    $tracked['medical_center_id']
                )
            ) {
                continue;
            }

            self::upsertBackfillSlotForEvent(
                $doctorId,
                $eventId,
                $tracked['medical_center_id'],
                $tracked['vacation_from'],
                $tracked['vacation_to']
            );
            $created++;
        }

        return $created;
    }

    public static function purgeBackfillSlotsForDoctor(string $doctorId): void
    {
        $doctorId = GoogleTokensRepository::normalizeUserId($doctorId);
        if ($doctorId === '') {
            return;
        }

        $stmt = Database::connection()->prepare(
            'DELETE FROM import_future_vacations_backfill_slots
             WHERE paziresh24_user_id = :doctor_id'
        );
        $stmt->execute(['doctor_id' => $doctorId]);
    }

    /**
     * @return array<int, array{
     *   id: int,
     *   google_event_id: string,
     *   medical_center_id: string,
     *   vacation_from: int,
     *   vacation_to: int
     * }>
     */
    public static function listActiveBackfillSlots(string $doctorId): array
    {
        $doctorId = GoogleTokensRepository::normalizeUserId($doctorId);
        if ($doctorId === '') {
            return [];
        }

        $stmt = Database::connection()->prepare(
            'SELECT id, google_event_id, medical_center_id, vacation_from, vacation_to
             FROM import_future_vacations_backfill_slots
             WHERE paziresh24_user_id = :doctor_id
               AND deleted_at IS NULL
             ORDER BY vacation_from ASC, id ASC'
        );
        $stmt->execute(['doctor_id' => $doctorId]);
        $rows = $stmt->fetchAll();

        if (!is_array($rows)) {
            return [];
        }

        $slots = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $slots[] = [
                'id' => (int) ($row['id'] ?? 0),
                'google_event_id' => (string) ($row['google_event_id'] ?? ''),
                'medical_center_id' => (string) ($row['medical_center_id'] ?? ''),
                'vacation_from' => (int) ($row['vacation_from'] ?? 0),
                'vacation_to' => (int) ($row['vacation_to'] ?? 0),
            ];
        }

        return $slots;
    }

    public static function recordBackfillSlot(
        string $doctorId,
        string $medicalCenterId,
        int $vacationFrom,
        int $vacationTo
    ): void {
        $doctorId = GoogleTokensRepository::normalizeUserId($doctorId);
        $medicalCenterId = trim($medicalCenterId);

        if ($doctorId === '' || $medicalCenterId === '' || $vacationFrom <= 0 || $vacationTo <= $vacationFrom) {
            return;
        }

        if (Database::isMysql()) {
            $stmt = Database::connection()->prepare(
                'INSERT IGNORE INTO import_future_vacations_backfill_slots (
                    paziresh24_user_id,
                    medical_center_id,
                    vacation_from,
                    vacation_to
                ) VALUES (
                    :doctor_id,
                    :medical_center_id,
                    :vacation_from,
                    :vacation_to
                )'
            );
        } elseif (Database::isPgsql()) {
            $stmt = Database::connection()->prepare(
                'INSERT INTO import_future_vacations_backfill_slots (
                    paziresh24_user_id,
                    medical_center_id,
                    vacation_from,
                    vacation_to
                ) VALUES (
                    :doctor_id,
                    :medical_center_id,
                    :vacation_from,
                    :vacation_to
                )
                ON CONFLICT (paziresh24_user_id, medical_center_id, vacation_from, vacation_to) DO NOTHING'
            );
        } else {
            $stmt = Database::connection()->prepare(
                'INSERT OR IGNORE INTO import_future_vacations_backfill_slots (
                    paziresh24_user_id,
                    medical_center_id,
                    vacation_from,
                    vacation_to
                ) VALUES (
                    :doctor_id,
                    :medical_center_id,
                    :vacation_from,
                    :vacation_to
                )'
            );
        }

        $stmt->execute([
            'doctor_id' => $doctorId,
            'medical_center_id' => $medicalCenterId,
            'vacation_from' => $vacationFrom,
            'vacation_to' => $vacationTo,
        ]);
    }

    public static function upsertBackfillSlotForEvent(
        string $doctorId,
        string $eventId,
        string $medicalCenterId,
        int $vacationFrom,
        int $vacationTo
    ): void {
        $doctorId = GoogleTokensRepository::normalizeUserId($doctorId);
        $eventId = trim($eventId);
        $medicalCenterId = trim($medicalCenterId);

        if (
            $doctorId === ''
            || $eventId === ''
            || $medicalCenterId === ''
            || $vacationFrom <= 0
            || $vacationTo <= $vacationFrom
        ) {
            return;
        }

        $existingStmt = Database::connection()->prepare(
            'SELECT id
             FROM import_future_vacations_backfill_slots
             WHERE paziresh24_user_id = :doctor_id
               AND google_event_id = :event_id
               AND medical_center_id = :medical_center_id
               AND deleted_at IS NULL
             LIMIT 1'
        );
        $existingStmt->execute([
            'doctor_id' => $doctorId,
            'event_id' => $eventId,
            'medical_center_id' => $medicalCenterId,
        ]);
        $existingId = $existingStmt->fetchColumn();

        if ($existingId !== false) {
            $updateStmt = Database::connection()->prepare(
                'UPDATE import_future_vacations_backfill_slots
                 SET vacation_from = :vacation_from,
                     vacation_to = :vacation_to,
                     deleted_at = NULL
                 WHERE id = :id'
            );
            $updateStmt->execute([
                'id' => (int) $existingId,
                'vacation_from' => $vacationFrom,
                'vacation_to' => $vacationTo,
            ]);
            return;
        }

        $insertStmt = Database::connection()->prepare(
            'INSERT INTO import_future_vacations_backfill_slots (
                paziresh24_user_id,
                google_event_id,
                medical_center_id,
                vacation_from,
                vacation_to
            ) VALUES (
                :doctor_id,
                :event_id,
                :medical_center_id,
                :vacation_from,
                :vacation_to
            )'
        );
        $insertStmt->execute([
            'doctor_id' => $doctorId,
            'event_id' => $eventId,
            'medical_center_id' => $medicalCenterId,
            'vacation_from' => $vacationFrom,
            'vacation_to' => $vacationTo,
        ]);
    }

    public static function syncBackfillSlotForEvent(
        string $doctorId,
        string $eventId,
        string $medicalCenterId,
        int $newFrom,
        int $newTo,
        ?int $oldFrom = null,
        ?int $oldTo = null
    ): void {
        $doctorId = GoogleTokensRepository::normalizeUserId($doctorId);
        $eventId = trim($eventId);
        $medicalCenterId = trim($medicalCenterId);

        if (
            $doctorId === ''
            || $eventId === ''
            || $medicalCenterId === ''
            || $newFrom <= 0
            || $newTo <= $newFrom
        ) {
            return;
        }

        // 1) Preferred path: event-id based row already exists.
        $existingStmt = Database::connection()->prepare(
            'SELECT id
             FROM import_future_vacations_backfill_slots
             WHERE paziresh24_user_id = :doctor_id
               AND google_event_id = :event_id
               AND medical_center_id = :medical_center_id
               AND deleted_at IS NULL
             LIMIT 1'
        );
        $existingStmt->execute([
            'doctor_id' => $doctorId,
            'event_id' => $eventId,
            'medical_center_id' => $medicalCenterId,
        ]);
        $existingId = $existingStmt->fetchColumn();

        if ($existingId !== false) {
            $updateStmt = Database::connection()->prepare(
                'UPDATE import_future_vacations_backfill_slots
                 SET vacation_from = :vacation_from,
                     vacation_to = :vacation_to,
                     deleted_at = NULL
                 WHERE id = :id'
            );
            $updateStmt->execute([
                'id' => (int) $existingId,
                'vacation_from' => $newFrom,
                'vacation_to' => $newTo,
            ]);
            return;
        }

        // 2) Legacy path: row created before google_event_id existed.
        if ($oldFrom !== null && $oldTo !== null && $oldFrom > 0 && $oldTo > $oldFrom) {
            $legacyStmt = Database::connection()->prepare(
                'SELECT id
                 FROM import_future_vacations_backfill_slots
                 WHERE paziresh24_user_id = :doctor_id
                   AND medical_center_id = :medical_center_id
                   AND vacation_from = :old_from
                   AND vacation_to = :old_to
                   AND deleted_at IS NULL
                   AND (google_event_id IS NULL OR google_event_id = \'\')
                 LIMIT 1'
            );
            $legacyStmt->execute([
                'doctor_id' => $doctorId,
                'medical_center_id' => $medicalCenterId,
                'old_from' => $oldFrom,
                'old_to' => $oldTo,
            ]);
            $legacyId = $legacyStmt->fetchColumn();

            if ($legacyId !== false) {
                $promoteStmt = Database::connection()->prepare(
                    'UPDATE import_future_vacations_backfill_slots
                     SET google_event_id = :event_id,
                         vacation_from = :vacation_from,
                         vacation_to = :vacation_to,
                         deleted_at = NULL
                     WHERE id = :id'
                );
                $promoteStmt->execute([
                    'id' => (int) $legacyId,
                    'event_id' => $eventId,
                    'vacation_from' => $newFrom,
                    'vacation_to' => $newTo,
                ]);
                return;
            }
        }

        // 3) No existing row found: create a new canonical row.
        self::upsertBackfillSlotForEvent($doctorId, $eventId, $medicalCenterId, $newFrom, $newTo);
    }

    public static function hasActiveBackfillSlotForEvent(
        string $doctorId,
        string $eventId,
        ?string $medicalCenterId = null
    ): bool {
        $doctorId = GoogleTokensRepository::normalizeUserId($doctorId);
        $eventId = trim($eventId);
        if ($doctorId === '' || $eventId === '') {
            return false;
        }

        if ($medicalCenterId !== null && trim($medicalCenterId) !== '') {
            $stmt = Database::connection()->prepare(
                'SELECT 1
                 FROM import_future_vacations_backfill_slots
                 WHERE paziresh24_user_id = :doctor_id
                   AND google_event_id = :event_id
                   AND medical_center_id = :medical_center_id
                   AND deleted_at IS NULL
                 LIMIT 1'
            );
            $stmt->execute([
                'doctor_id' => $doctorId,
                'event_id' => $eventId,
                'medical_center_id' => trim($medicalCenterId),
            ]);
            return $stmt->fetchColumn() !== false;
        }

        $stmt = Database::connection()->prepare(
            'SELECT 1
             FROM import_future_vacations_backfill_slots
             WHERE paziresh24_user_id = :doctor_id
               AND google_event_id = :event_id
               AND deleted_at IS NULL
             LIMIT 1'
        );
        $stmt->execute([
            'doctor_id' => $doctorId,
            'event_id' => $eventId,
        ]);

        return $stmt->fetchColumn() !== false;
    }

    public static function markBackfillSlotDeleted(int $slotId): void
    {
        if ($slotId <= 0) {
            return;
        }

        $stmt = Database::connection()->prepare(
            'UPDATE import_future_vacations_backfill_slots
             SET deleted_at = CURRENT_TIMESTAMP
             WHERE id = :id
               AND deleted_at IS NULL'
        );
        $stmt->execute(['id' => $slotId]);
    }

    public static function markBackfillSlotsDeletedByEvent(
        string $doctorId,
        string $eventId,
        ?string $medicalCenterId = null
    ): void {
        $doctorId = GoogleTokensRepository::normalizeUserId($doctorId);
        $eventId = trim($eventId);
        if ($doctorId === '' || $eventId === '') {
            return;
        }

        if ($medicalCenterId !== null && trim($medicalCenterId) !== '') {
            $stmt = Database::connection()->prepare(
                'UPDATE import_future_vacations_backfill_slots
                 SET deleted_at = CURRENT_TIMESTAMP
                 WHERE paziresh24_user_id = :doctor_id
                   AND google_event_id = :event_id
                   AND medical_center_id = :medical_center_id
                   AND deleted_at IS NULL'
            );
            $stmt->execute([
                'doctor_id' => $doctorId,
                'event_id' => $eventId,
                'medical_center_id' => trim($medicalCenterId),
            ]);
            return;
        }

        $stmt = Database::connection()->prepare(
            'UPDATE import_future_vacations_backfill_slots
             SET deleted_at = CURRENT_TIMESTAMP
             WHERE paziresh24_user_id = :doctor_id
               AND google_event_id = :event_id
               AND deleted_at IS NULL'
        );
        $stmt->execute([
            'doctor_id' => $doctorId,
            'event_id' => $eventId,
        ]);
    }

    /**
     * @return array{removed: int, already_gone: int, failed: int, deleted: int, not_found: int}
     */
    public static function deleteActiveBackfillVacations(
        string $doctorId,
        string $accessToken,
        ?string $fallbackCenterId = null
    ): array {
        require_once __DIR__ . '/vacation-bootstrap.php';
        hamgam_load_vacation_modules();

        self::reconcileBackfillSlotsFromTrackedEvents($doctorId);

        $targets = [];

        foreach (GoogleVacationRepository::listAllTrackedVacationsForDeletion($doctorId, $fallbackCenterId) as $tracked) {
            $targets[] = [
                'id' => 0,
                'google_event_id' => $tracked['google_event_id'],
                'medical_center_id' => $tracked['medical_center_id'],
                'vacation_from' => $tracked['vacation_from'],
                'vacation_to' => $tracked['vacation_to'],
            ];
        }

        foreach (self::listActiveBackfillSlots($doctorId) as $slot) {
            $duplicate = false;
            foreach ($targets as $existing) {
                if (
                    $existing['google_event_id'] !== ''
                    && $existing['google_event_id'] === $slot['google_event_id']
                    && $existing['medical_center_id'] === $slot['medical_center_id']
                ) {
                    $duplicate = true;
                    break;
                }
            }

            if (!$duplicate) {
                $targets[] = $slot;
            }
        }

        $slots = $targets;
        $removed = 0;
        $alreadyGone = 0;
        $failed = 0;

        foreach ($slots as $slot) {
            $medicalCenterId = trim($slot['medical_center_id']);
            if ($medicalCenterId === '' && is_string($fallbackCenterId) && trim($fallbackCenterId) !== '') {
                $medicalCenterId = trim($fallbackCenterId);
            }

            $vacationFrom = (int) ($slot['vacation_from'] ?? 0);
            $vacationTo = (int) ($slot['vacation_to'] ?? 0);
            $eventId = is_string($slot['google_event_id'] ?? null) ? trim($slot['google_event_id']) : '';

            if ($medicalCenterId === '' || $vacationFrom <= 0 || $vacationTo <= 0) {
                if ($slot['id'] > 0) {
                    self::markBackfillSlotDeleted($slot['id']);
                }
                if ($eventId !== '') {
                    GoogleVacationRepository::removeProcessedEvent($doctorId, $eventId, $medicalCenterId !== '' ? $medicalCenterId : null);
                }
                continue;
            }

            $response = Paziresh24VacationApi::deleteVacation(
                $accessToken,
                $medicalCenterId,
                $vacationFrom,
                $vacationTo
            );

            if ($slot['id'] > 0) {
                self::markBackfillSlotDeleted($slot['id']);
            }

            if ($eventId !== '') {
                GoogleVacationRepository::removeProcessedEvent($doctorId, $eventId, $medicalCenterId);
            } else {
                GoogleVacationRepository::removeProcessedEventsByTimeSlot(
                    $doctorId,
                    $medicalCenterId,
                    $vacationFrom,
                    $vacationTo
                );
            }

            if ($response === null) {
                $failed++;
                continue;
            }

            if ($response === []) {
                $alreadyGone++;
            } else {
                $removed++;
            }
        }

        return [
            'removed' => $removed,
            'already_gone' => $alreadyGone,
            'failed' => $failed,
            'deleted' => $removed + $alreadyGone,
            'not_found' => $alreadyGone,
        ];
    }

    public static function migrateExistingDoctorLocks(PDO $pdo): void
    {
        try {
            if (Database::isMysql()) {
                $pdo->exec(
                    'INSERT IGNORE INTO import_future_vacations_doctor_lock (paziresh24_user_id, used_at)
                     SELECT paziresh24_user_id, import_future_vacations_done_at
                     FROM google_tokens
                     WHERE import_future_vacations_done_at IS NOT NULL'
                );

                return;
            }

            if (Database::isPgsql()) {
                $pdo->exec(
                    'INSERT INTO import_future_vacations_doctor_lock (paziresh24_user_id, used_at)
                     SELECT paziresh24_user_id, import_future_vacations_done_at
                     FROM google_tokens
                     WHERE import_future_vacations_done_at IS NOT NULL
                     ON CONFLICT (paziresh24_user_id) DO NOTHING'
                );

                return;
            }

            $pdo->exec(
                'INSERT OR IGNORE INTO import_future_vacations_doctor_lock (paziresh24_user_id, used_at)
                 SELECT paziresh24_user_id, import_future_vacations_done_at
                 FROM google_tokens
                 WHERE import_future_vacations_done_at IS NOT NULL'
            );
        } catch (Throwable $e) {
            error_log('[ImportFutureVacationsRepository] lock migration failed: ' . $e->getMessage());
        }
    }
}
