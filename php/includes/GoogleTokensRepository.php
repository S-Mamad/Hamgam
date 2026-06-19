<?php

declare(strict_types=1);

final class GoogleTokensRepository
{
    private const ALLOWED_COLOR_IDS = ['1', '5', '9', '10', '11'];

    public static function normalizeUserId(string $userId): string
    {
        $userId = trim($userId);
        if ($userId !== '' && ctype_digit($userId)) {
            return (string) (int) $userId;
        }

        return $userId;
    }

    /**
     * @return array<int, string>
     */
    private static function userIdLookupVariants(string $userId): array
    {
        $normalized = self::normalizeUserId($userId);
        $variants = [$normalized];
        if ($normalized !== $userId && !in_array($userId, $variants, true)) {
            $variants[] = $userId;
        }

        return $variants;
    }

    public static function findByUserId(string $userId): ?array
    {
        foreach (self::userIdLookupVariants($userId) as $variant) {
            $stmt = Database::connection()->prepare(
                'SELECT * FROM google_tokens WHERE paziresh24_user_id = :user_id LIMIT 1'
            );
            $stmt->execute(['user_id' => $variant]);
            $row = $stmt->fetch();
            if ($row !== false) {
                return $row;
            }
        }

        return self::findByNumericUserId($userId);
    }

    public static function deleteByUserId(string $userId): void
    {
        self::purgeUserRecords($userId);
    }

    /**
     * Removes all Hamgam/Zamanak rows for a doctor (tokens, bookings, vacation map).
     */
    public static function purgeUserRecords(string $userId): void
    {
        foreach (self::resolveStoredUserIds($userId) as $storedUserId) {
            self::deleteRowsForStoredUserId($storedUserId);
        }
    }

    /**
     * @return array<int, string>
     */
    private static function resolveStoredUserIds(string $userId): array
    {
        $normalized = self::normalizeUserId($userId);
        $ids = [];

        foreach (self::userIdLookupVariants($userId) as $variant) {
            $stmt = Database::connection()->prepare(
                'SELECT paziresh24_user_id FROM google_tokens WHERE paziresh24_user_id = :user_id'
            );
            $stmt->execute(['user_id' => $variant]);
            $row = $stmt->fetch();
            if ($row !== false && isset($row['paziresh24_user_id'])) {
                $ids[] = (string) $row['paziresh24_user_id'];
            }
        }

        if ($normalized !== '' && ctype_digit($normalized)) {
            foreach (self::findAllRowsByNumericUserId((int) $normalized) as $row) {
                if (isset($row['paziresh24_user_id'])) {
                    $ids[] = (string) $row['paziresh24_user_id'];
                }
            }
        }

        if ($ids === []) {
            return [$normalized];
        }

        return array_values(array_unique($ids));
    }

    private static function deleteRowsForStoredUserId(string $storedUserId): void
    {
        $pdo = Database::connection();

        $stmt = $pdo->prepare('DELETE FROM google_event_vacations WHERE paziresh24_user_id = :user_id');
        $stmt->execute(['user_id' => $storedUserId]);

        $stmt = $pdo->prepare('DELETE FROM google_calendar_bookings WHERE paziresh24_user_id = :user_id');
        $stmt->execute(['user_id' => $storedUserId]);

        $stmt = $pdo->prepare('DELETE FROM google_tokens WHERE paziresh24_user_id = :user_id');
        $stmt->execute(['user_id' => $storedUserId]);
    }

    private static function findByNumericUserId(string $userId): ?array
    {
        $normalized = self::normalizeUserId($userId);
        if ($normalized === '' || !ctype_digit($normalized)) {
            return null;
        }

        $rows = self::findAllRowsByNumericUserId((int) $normalized);
        if ($rows === []) {
            return null;
        }

        foreach ($rows as $row) {
            if (self::hasRefreshToken($row)) {
                return $row;
            }
        }

        return $rows[0];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function findAllRowsByNumericUserId(int $numericUserId): array
    {
        $driver = Config::get('DB_DRIVER', 'sqlite');

        if ($driver === 'mysql') {
            $stmt = Database::connection()->prepare(
                'SELECT * FROM google_tokens
                 WHERE paziresh24_user_id REGEXP \'^[0-9]+$\'
                   AND CAST(paziresh24_user_id AS UNSIGNED) = :uid'
            );
        } else {
            $stmt = Database::connection()->prepare(
                'SELECT * FROM google_tokens
                 WHERE paziresh24_user_id GLOB \'[0-9]*\'
                   AND CAST(paziresh24_user_id AS INTEGER) = :uid'
            );
        }

        $stmt->execute(['uid' => $numericUserId]);
        $rows = $stmt->fetchAll();

        return is_array($rows) ? $rows : [];
    }

    public static function updateHamdastAccessToken(string $userId, string $hamdastAccessToken): void
    {
        $userId = self::normalizeUserId($userId);
        $stmt = Database::connection()->prepare(
            'UPDATE google_tokens SET
                hamdast_access_token = :hamdast_access_token,
                updated_at = CURRENT_TIMESTAMP
             WHERE paziresh24_user_id = :user_id'
        );
        $stmt->execute([
            'user_id' => $userId,
            'hamdast_access_token' => $hamdastAccessToken,
        ]);
    }

    public static function saveGoogleAccountEmail(string $userId, string $email): void
    {
        $userId = self::normalizeUserId($userId);
        $email = trim($email);
        if ($email === '') {
            return;
        }

        $stmt = Database::connection()->prepare(
            'UPDATE google_tokens SET
                google_account_email = :google_account_email,
                updated_at = CURRENT_TIMESTAMP
             WHERE paziresh24_user_id = :user_id'
        );
        $stmt->execute([
            'user_id' => $userId,
            'google_account_email' => $email,
        ]);
    }

    public static function clearGoogleAccountEmail(string $userId): void
    {
        $userId = self::normalizeUserId($userId);
        if ($userId === '') {
            return;
        }

        $stmt = Database::connection()->prepare(
            'UPDATE google_tokens SET
                google_account_email = NULL,
                updated_at = CURRENT_TIMESTAMP
             WHERE paziresh24_user_id = :user_id'
        );
        $stmt->execute(['user_id' => $userId]);
    }

    /**
     * @param array<string, mixed>|null $tokenRow
     * @return array{refresh_token: string, channel_id: string, resource_id: string}|null
     */
    public static function watchCleanupCredentials(?array $tokenRow): ?array
    {
        if ($tokenRow === null) {
            return null;
        }

        $channelId = (string) ($tokenRow['google_channel_id'] ?? '');
        $resourceId = (string) ($tokenRow['google_resource_id'] ?? '');
        $refreshToken = (string) ($tokenRow['google_refresh_token'] ?? '');

        if ($channelId === '' || $resourceId === '' || $refreshToken === '') {
            return null;
        }

        return [
            'refresh_token' => $refreshToken,
            'channel_id' => $channelId,
            'resource_id' => $resourceId,
        ];
    }

    /**
     * Disconnect Google OAuth for a doctor while preserving Hamgam settings/preferences.
     */
    public static function disconnectGoogleConnection(string $userId): bool
    {
        $tokenRow = self::findByUserId($userId);
        if ($tokenRow === null || !self::hasRefreshToken($tokenRow)) {
            return false;
        }

        $dbUserId = (string) ($tokenRow['paziresh24_user_id'] ?? self::normalizeUserId($userId));
        $stmt = Database::connection()->prepare(
            'UPDATE google_tokens SET
                google_refresh_token = NULL,
                google_access_token = NULL,
                google_account_email = NULL,
                google_channel_id = NULL,
                google_resource_id = NULL,
                google_watch_expiration = NULL,
                google_sync_token = NULL,
                last_sync_status = NULL,
                updated_at = CURRENT_TIMESTAMP
             WHERE paziresh24_user_id = :user_id'
        );
        $stmt->execute(['user_id' => $dbUserId]);

        require_once __DIR__ . '/GoogleVacationRepository.php';
        require_once __DIR__ . '/GoogleCalendarBookingRepository.php';
        GoogleVacationRepository::clearProcessedEvents($dbUserId);
        GoogleCalendarBookingRepository::clearAllForUser($dbUserId);

        return true;
    }

    /** Insert minimal row or update hamdast token — used on every panel login. */
    public static function upsertHamdastAccessToken(string $userId, string $hamdastAccessToken): void
    {
        $userId = self::normalizeUserId($userId);
        $existing = self::findByUserId($userId);
        if ($existing !== null) {
            self::updateHamdastAccessToken(
                (string) ($existing['paziresh24_user_id'] ?? $userId),
                $hamdastAccessToken
            );
            return;
        }

        $driver = Config::get('DB_DRIVER', 'sqlite');
        if ($driver === 'mysql') {
            $stmt = Database::connection()->prepare(
                'INSERT INTO google_tokens (paziresh24_user_id, hamdast_access_token, color_id)
                 VALUES (:user_id, :hamdast_access_token, :color_id)
                 ON DUPLICATE KEY UPDATE
                    hamdast_access_token = VALUES(hamdast_access_token),
                    updated_at = CURRENT_TIMESTAMP'
            );
        } else {
            $stmt = Database::connection()->prepare(
                'INSERT INTO google_tokens (paziresh24_user_id, hamdast_access_token, color_id, updated_at)
                 VALUES (:user_id, :hamdast_access_token, :color_id, CURRENT_TIMESTAMP)
                 ON CONFLICT(paziresh24_user_id) DO UPDATE SET
                    hamdast_access_token = excluded.hamdast_access_token,
                    updated_at = CURRENT_TIMESTAMP'
            );
        }

        $stmt->execute([
            'user_id' => $userId,
            'hamdast_access_token' => $hamdastAccessToken,
            'color_id' => '9',
        ]);
    }

    public static function needsWatchRegistration(?array $row): bool
    {
        if (!self::hasRefreshToken($row)) {
            return false;
        }

        $channelId = $row['google_channel_id'] ?? '';
        if (!is_string($channelId) || trim($channelId) === '') {
            return true;
        }

        $expiration = $row['google_watch_expiration'] ?? null;
        if (!is_numeric($expiration)) {
            return true;
        }

        $nowMs = (int) (microtime(true) * 1000);

        return (int) $expiration <= ($nowMs + 3600000);
    }

    public static function hasRefreshToken(?array $row): bool
    {
        if ($row === null) {
            return false;
        }

        $token = $row['google_refresh_token'] ?? '';
        return is_string($token) && trim($token) !== '';
    }

    public static function upsertOAuthConnection(
        string $userId,
        string $hamdastAccessToken,
        string $googleRefreshToken,
        ?string $googleAccessToken = null,
        string $colorId = '9',
        bool $patientName = true,
        bool $patientDateTime = false,
        bool $patientNational = false,
        bool $patientPhone = false,
        ?array $existingRow = null
    ): void {
        $userId = self::normalizeUserId($userId);
        $existing = $existingRow ?? self::findByUserId($userId);
        $prefs = self::getSettings($existing);

        $driver = Config::get('DB_DRIVER', 'sqlite');

        if ($driver === 'mysql') {
            $stmt = Database::connection()->prepare(
                'INSERT INTO google_tokens (
                    paziresh24_user_id,
                    hamdast_access_token,
                    google_refresh_token,
                    google_access_token,
                    color_id,
                    Patient_name,
                    Patient_date_time,
                    Patient_national,
                    Patient_phone,
                    Patient_center
                ) VALUES (
                    :user_id,
                    :hamdast_access_token,
                    :google_refresh_token,
                    :google_access_token,
                    :color_id,
                    :patient_name,
                    :patient_date_time,
                    :patient_national,
                    :patient_phone,
                    :patient_center
                )
                ON DUPLICATE KEY UPDATE
                    hamdast_access_token = VALUES(hamdast_access_token),
                    google_refresh_token = VALUES(google_refresh_token),
                    google_access_token = VALUES(google_access_token),
                    updated_at = CURRENT_TIMESTAMP'
            );
        } else {
            $stmt = Database::connection()->prepare(
                'INSERT INTO google_tokens (
                    paziresh24_user_id,
                    hamdast_access_token,
                    google_refresh_token,
                    google_access_token,
                    color_id,
                    Patient_name,
                    Patient_date_time,
                    Patient_national,
                    Patient_phone,
                    Patient_center,
                    updated_at
                ) VALUES (
                    :user_id,
                    :hamdast_access_token,
                    :google_refresh_token,
                    :google_access_token,
                    :color_id,
                    :patient_name,
                    :patient_date_time,
                    :patient_national,
                    :patient_phone,
                    :patient_center,
                    CURRENT_TIMESTAMP
                )
                ON CONFLICT(paziresh24_user_id) DO UPDATE SET
                    hamdast_access_token = excluded.hamdast_access_token,
                    google_refresh_token = excluded.google_refresh_token,
                    google_access_token = excluded.google_access_token,
                    updated_at = CURRENT_TIMESTAMP'
            );
        }

        $stmt->execute([
            'user_id' => $userId,
            'hamdast_access_token' => $hamdastAccessToken,
            'google_refresh_token' => $googleRefreshToken,
            'google_access_token' => $googleAccessToken,
            'color_id' => $prefs['color_id'],
            'patient_name' => $prefs['Patient_name'] ? 1 : 0,
            'patient_date_time' => $prefs['Patient_date_time'] ? 1 : 0,
            'patient_national' => $prefs['Patient_national'] ? 1 : 0,
            'patient_phone' => $prefs['Patient_phone'] ? 1 : 0,
            'patient_center' => $prefs['Patient_center'] ? 1 : 0,
        ]);
    }

    /**
     * @return array{
     *   color_id: string,
     *   Patient_name: bool,
     *   Patient_date_time: bool,
     *   Patient_national: bool,
     *   Patient_phone: bool,
     *   Patient_center: bool,
     *   auto_vacation: bool,
     *   import_future_vacations: bool,
     *   import_future_vacations_used: bool,
     *   import_future_backfill_undo_available: bool,
     *   cancel_appointment_on_event_delete: bool,
     *   cancel_conflicting_appointments: bool,
     *   google_account_email: ?string,
     *   vacation_sync_centers: array{mode: string, center_ids: array<int, string>}
     * }
     */
    public static function getSettings(?array $row): array
    {
        if ($row === null) {
            return [
                'color_id' => '9',
                'Patient_name' => true,
                'Patient_date_time' => false,
                'Patient_national' => false,
                'Patient_phone' => false,
                'Patient_center' => true,
                'auto_vacation' => false,
                'import_future_vacations' => false,
                'import_future_vacations_used' => false,
                'import_future_backfill_undo_available' => false,
                'import_future_vacations_reset_available' => false,
                'import_future_backfill_slot_count' => 0,
                'synced_vacation_count' => 0,
                'cancel_appointment_on_event_delete' => true,
                'cancel_conflicting_appointments' => true,
                'google_account_email' => null,
                'vacation_sync_centers' => self::defaultVacationSyncCenters(),
            ];
        }

        $email = $row['google_account_email'] ?? null;
        $doctorId = (string) ($row['paziresh24_user_id'] ?? '');
        $importUsed = self::hasCompletedImportFutureVacations($row);
        if ($doctorId !== '') {
            ImportFutureVacationsRepository::reconcileBackfillSlotsFromTrackedEvents($doctorId);
        }
        $trackedVacationCount = $doctorId !== ''
            ? GoogleVacationRepository::countTrackedVacations($doctorId)
            : 0;

        return [
            'color_id' => self::normalizeColorId($row['color_id'] ?? null),
            'Patient_name' => self::toBool($row['Patient_name'] ?? false),
            'Patient_date_time' => self::toBool($row['Patient_date_time'] ?? false),
            'Patient_national' => self::toBool($row['Patient_national'] ?? false),
            'Patient_phone' => self::toBool($row['Patient_phone'] ?? false),
            'Patient_center' => self::toBool($row['Patient_center'] ?? false),
            'auto_vacation' => self::toBool($row['auto_vacation'] ?? false),
            'import_future_vacations' => self::toBool($row['import_future_vacations'] ?? false),
            'import_future_vacations_used' => $importUsed,
            'import_future_backfill_undo_available' => $trackedVacationCount > 0 || $importUsed,
            'import_future_vacations_reset_available' => $importUsed,
            'import_future_backfill_slot_count' => $trackedVacationCount,
            'synced_vacation_count' => $trackedVacationCount,
            'cancel_appointment_on_event_delete' => self::toBool($row['cancel_appointment_on_event_delete'] ?? true),
            'cancel_conflicting_appointments' => self::toBool($row['cancel_conflicting_appointments'] ?? true),
            'google_account_email' => is_string($email) && trim($email) !== '' ? trim($email) : null,
            'vacation_sync_centers' => self::parseVacationSyncCentersFromRow($row, false),
        ];
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function updateSettings(string $userId, array $body): bool
    {
        $settings = self::parseSettingsBody($body);
        if ($settings === null) {
            return false;
        }

        $userId = self::normalizeUserId($userId);
        $existing = self::findByUserId($userId);
        if ($existing !== null && !array_key_exists('vacationSyncCenters', $body)) {
            $settings['vacation_sync_centers'] = self::parseVacationSyncCentersFromRow($existing);
        }
        if ($existing === null) {
            error_log('[GoogleTokensRepository] updateSettings: creating row for user ' . $userId);
            self::insertSettingsRow($userId, $settings);
            return true;
        }

        $dbUserId = (string) ($existing['paziresh24_user_id'] ?? $userId);
        $settings = self::applyImportFutureVacationsLock($existing, $settings);

        $stmt = Database::connection()->prepare(
            'UPDATE google_tokens SET
                color_id = :color_id,
                Patient_name = :patient_name,
                Patient_date_time = :patient_date_time,
                Patient_national = :patient_national,
                Patient_phone = :patient_phone,
                Patient_center = :patient_center,
                auto_vacation = :auto_vacation,
                import_future_vacations = :import_future_vacations,
                cancel_appointment_on_event_delete = :cancel_appointment_on_event_delete,
                cancel_conflicting_appointments = :cancel_conflicting_appointments,
                vacation_sync_centers = :vacation_sync_centers,
                updated_at = CURRENT_TIMESTAMP
             WHERE paziresh24_user_id = :user_id'
        );

        $stmt->execute([
            'user_id' => $dbUserId,
            'color_id' => $settings['color_id'],
            'patient_name' => $settings['Patient_name'] ? 1 : 0,
            'patient_date_time' => $settings['Patient_date_time'] ? 1 : 0,
            'patient_national' => $settings['Patient_national'] ? 1 : 0,
            'patient_phone' => $settings['Patient_phone'] ? 1 : 0,
            'patient_center' => $settings['Patient_center'] ? 1 : 0,
            'auto_vacation' => $settings['auto_vacation'] ? 1 : 0,
            'import_future_vacations' => $settings['import_future_vacations'] ? 1 : 0,
            'cancel_appointment_on_event_delete' => $settings['cancel_appointment_on_event_delete'] ? 1 : 0,
            'cancel_conflicting_appointments' => $settings['cancel_conflicting_appointments'] ? 1 : 0,
            'vacation_sync_centers' => self::encodeVacationSyncCentersForDb($settings['vacation_sync_centers']),
        ]);

        return true;
    }

    /**
     * @param array{
     *   color_id: string,
     *   Patient_name: bool,
     *   Patient_date_time: bool,
     *   Patient_national: bool,
     *   Patient_phone: bool,
     *   Patient_center: bool,
     *   auto_vacation: bool,
     *   import_future_vacations: bool,
     *   cancel_appointment_on_event_delete: bool,
     *   cancel_appointment_on_event_delete: bool,
     *   cancel_conflicting_appointments: bool,
     *   vacation_sync_centers: array{mode: string, center_ids: array<int, string>}
     * } $settings
     */
    private static function insertSettingsRow(string $userId, array $settings): void
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO google_tokens (
                paziresh24_user_id,
                color_id,
                Patient_name,
                Patient_date_time,
                Patient_national,
                Patient_phone,
                Patient_center,
                auto_vacation,
                import_future_vacations,
                cancel_appointment_on_event_delete,
                cancel_conflicting_appointments,
                vacation_sync_centers
            ) VALUES (
                :user_id,
                :color_id,
                :patient_name,
                :patient_date_time,
                :patient_national,
                :patient_phone,
                :patient_center,
                :auto_vacation,
                :import_future_vacations,
                :cancel_appointment_on_event_delete,
                :cancel_conflicting_appointments,
                :vacation_sync_centers
            )'
        );

        $stmt->execute([
            'user_id' => $userId,
            'color_id' => $settings['color_id'],
            'patient_name' => $settings['Patient_name'] ? 1 : 0,
            'patient_date_time' => $settings['Patient_date_time'] ? 1 : 0,
            'patient_national' => $settings['Patient_national'] ? 1 : 0,
            'patient_phone' => $settings['Patient_phone'] ? 1 : 0,
            'patient_center' => $settings['Patient_center'] ? 1 : 0,
            'auto_vacation' => $settings['auto_vacation'] ? 1 : 0,
            'import_future_vacations' => $settings['import_future_vacations'] ? 1 : 0,
            'cancel_appointment_on_event_delete' => $settings['cancel_appointment_on_event_delete'] ? 1 : 0,
            'cancel_conflicting_appointments' => $settings['cancel_conflicting_appointments'] ? 1 : 0,
            'vacation_sync_centers' => self::encodeVacationSyncCentersForDb($settings['vacation_sync_centers']),
        ]);
    }

    /**
     * @param array<string, mixed> $body
     * @return array{
     *   color_id: string,
     *   Patient_name: bool,
     *   Patient_date_time: bool,
     *   Patient_national: bool,
     *   Patient_phone: bool,
     *   Patient_center: bool,
     *   auto_vacation: bool,
     *   import_future_vacations: bool,
     *   cancel_appointment_on_event_delete: bool,
     *   cancel_conflicting_appointments: bool,
     *   vacation_sync_centers: array{mode: string, center_ids: array<int, string>}
     * }|null
     */
    private static function parseSettingsBody(array $body): ?array
    {
        if (!array_key_exists('colorId', $body)) {
            error_log('[GoogleTokensRepository] parseSettingsBody: missing colorId');
            return null;
        }

        $colorId = self::normalizeColorId($body['colorId']);
        if (!in_array($colorId, self::ALLOWED_COLOR_IDS, true)) {
            error_log('[GoogleTokensRepository] parseSettingsBody: invalid colorId=' . $colorId);
            return null;
        }

        $boolFields = ['fullName', 'centerName', 'datetime', 'nationalId', 'phone'];
        $parsedBools = [];
        foreach ($boolFields as $field) {
            if (!array_key_exists($field, $body)) {
                error_log('[GoogleTokensRepository] parseSettingsBody: missing ' . $field);
                return null;
            }
            $parsedBools[$field] = self::readSettingsBool($body[$field]);
            if ($parsedBools[$field] === null) {
                error_log('[GoogleTokensRepository] parseSettingsBody: invalid bool ' . $field);
                return null;
            }
        }

        $autoVacation = false;
        if (array_key_exists('autoVacation', $body)) {
            $parsedAutoVacation = self::readSettingsBool($body['autoVacation']);
            if ($parsedAutoVacation === null) {
                error_log('[GoogleTokensRepository] parseSettingsBody: invalid autoVacation');
                return null;
            }
            $autoVacation = $parsedAutoVacation;
        }

        $importFutureVacations = false;
        if ($autoVacation && array_key_exists('importFutureVacations', $body)) {
            $parsedImport = self::readSettingsBool($body['importFutureVacations']);
            if ($parsedImport === null) {
                error_log('[GoogleTokensRepository] parseSettingsBody: invalid importFutureVacations');
                return null;
            }
            $importFutureVacations = $parsedImport;
        }

        $cancelAppointmentOnEventDelete = false;
        if ($autoVacation && array_key_exists('cancelAppointmentOnEventDelete', $body)) {
            $parsedCancelOnDelete = self::readSettingsBool($body['cancelAppointmentOnEventDelete']);
            if ($parsedCancelOnDelete === null) {
                error_log('[GoogleTokensRepository] parseSettingsBody: invalid cancelAppointmentOnEventDelete');
                return null;
            }
            $cancelAppointmentOnEventDelete = $parsedCancelOnDelete;
        }

        $cancelConflictingAppointments = false;
        if ($autoVacation && array_key_exists('cancelConflictingAppointments', $body)) {
            $parsedCancelConflict = self::readSettingsBool($body['cancelConflictingAppointments']);
            if ($parsedCancelConflict === null) {
                error_log('[GoogleTokensRepository] parseSettingsBody: invalid cancelConflictingAppointments');
                return null;
            }
            $cancelConflictingAppointments = $parsedCancelConflict;
        }

        $vacationSyncCenters = self::defaultVacationSyncCenters();
        if (array_key_exists('vacationSyncCenters', $body)) {
            $parsedCenters = self::parseVacationSyncCentersFromBody($body['vacationSyncCenters']);
            if ($parsedCenters === null) {
                error_log('[GoogleTokensRepository] parseSettingsBody: invalid vacationSyncCenters');
                return null;
            }
            $vacationSyncCenters = $parsedCenters;
        }

        if ($autoVacation && $vacationSyncCenters['mode'] === 'selected' && $vacationSyncCenters['center_ids'] === []) {
            error_log('[GoogleTokensRepository] parseSettingsBody: empty vacation center selection');
            return null;
        }

        return [
            'color_id' => $colorId,
            'Patient_name' => $parsedBools['fullName'],
            'Patient_center' => $parsedBools['centerName'],
            'Patient_date_time' => $parsedBools['datetime'],
            'Patient_national' => $parsedBools['nationalId'],
            'Patient_phone' => $parsedBools['phone'],
            'auto_vacation' => $autoVacation,
            'import_future_vacations' => $importFutureVacations,
            'cancel_appointment_on_event_delete' => $cancelAppointmentOnEventDelete,
            'cancel_conflicting_appointments' => $cancelConflictingAppointments,
            'vacation_sync_centers' => $vacationSyncCenters,
        ];
    }

    private static function readSettingsBool(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (int) $value === 1;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }
            if (in_array($normalized, ['0', 'false', 'no', 'off', ''], true)) {
                return false;
            }

            return null;
        }

        return null;
    }

    public static function isCancelAppointmentOnEventDeleteEnabled(?array $row): bool
    {
        if ($row === null) {
            return true;
        }

        return self::toBool($row['cancel_appointment_on_event_delete'] ?? true);
    }

    public static function isCancelConflictingAppointmentsEnabled(?array $row): bool
    {
        if ($row === null) {
            return true;
        }

        return self::toBool($row['cancel_conflicting_appointments'] ?? true);
    }

    public static function hasCompletedImportFutureVacations(?array $row): bool
    {
        if ($row !== null) {
            $doctorId = (string) ($row['paziresh24_user_id'] ?? '');
            if ($doctorId !== '' && ImportFutureVacationsRepository::isDoctorLocked($doctorId)) {
                return true;
            }
        }

        if ($row === null) {
            return false;
        }

        $doneAt = $row['import_future_vacations_done_at'] ?? null;

        return $doneAt !== null && $doneAt !== '';
    }

    /**
     * @param array<string, mixed> $existing
     * @param array{
     *   color_id: string,
     *   Patient_name: bool,
     *   Patient_date_time: bool,
     *   Patient_national: bool,
     *   Patient_phone: bool,
     *   Patient_center: bool,
     *   auto_vacation: bool,
     *   import_future_vacations: bool,
     *   cancel_appointment_on_event_delete: bool,
     *   cancel_conflicting_appointments: bool,
     *   vacation_sync_centers: array{mode: string, center_ids: array<int, string>}
     * } $settings
     * @return array{
     *   color_id: string,
     *   Patient_name: bool,
     *   Patient_date_time: bool,
     *   Patient_national: bool,
     *   Patient_phone: bool,
     *   Patient_center: bool,
     *   auto_vacation: bool,
     *   import_future_vacations: bool,
     *   cancel_appointment_on_event_delete: bool,
     *   cancel_conflicting_appointments: bool,
     *   vacation_sync_centers: array{mode: string, center_ids: array<int, string>}
     * }
     */
    private static function applyImportFutureVacationsLock(array $existing, array $settings): array
    {
        if (!self::hasCompletedImportFutureVacations($existing)) {
            return $settings;
        }

        $currentlyOn = self::toBool($existing['import_future_vacations'] ?? false);
        $requestedOn = $settings['import_future_vacations'];

        if (!$currentlyOn && $requestedOn) {
            $settings['import_future_vacations'] = false;
        }

        return $settings;
    }

    public static function resetImportBackfillState(string $userId): void
    {
        $userId = self::resolveStoredUserId($userId);
        $existing = self::findByUserId($userId);
        if ($existing !== null && self::hasCompletedImportFutureVacations($existing)) {
            return;
        }

        $stmt = Database::connection()->prepare(
            'UPDATE google_tokens SET
                import_future_vacations_done_at = NULL,
                import_future_vacations_window_end = NULL,
                updated_at = CURRENT_TIMESTAMP
             WHERE paziresh24_user_id = :user_id'
        );
        $stmt->execute(['user_id' => $userId]);
    }

    public static function parseImportFutureVacationsFlag(mixed $value): ?bool
    {
        return self::readSettingsBool($value);
    }

    public static function shouldRunFutureVacationsBackfill(?array $row): bool
    {
        if ($row === null) {
            return false;
        }

        $doctorId = (string) ($row['paziresh24_user_id'] ?? '');
        if ($doctorId !== '' && ImportFutureVacationsRepository::isDoctorLocked($doctorId)) {
            return false;
        }

        if (!self::toBool($row['auto_vacation'] ?? false)) {
            return false;
        }

        if (!self::toBool($row['import_future_vacations'] ?? false)) {
            return false;
        }

        $doneAt = $row['import_future_vacations_done_at'] ?? null;
        if ($doneAt !== null && $doneAt !== '') {
            return false;
        }

        return true;
    }

    public static function resolveStoredUserId(string $userId): string
    {
        $existing = self::findByUserId($userId);
        if ($existing !== null) {
            return (string) ($existing['paziresh24_user_id'] ?? self::normalizeUserId($userId));
        }

        return self::normalizeUserId($userId);
    }

    public static function saveImportBackfillWindowEnd(string $userId, int $windowEndTs): void
    {
        $userId = self::resolveStoredUserId($userId);
        $stmt = Database::connection()->prepare(
            'UPDATE google_tokens SET
                import_future_vacations_window_end = :window_end,
                updated_at = CURRENT_TIMESTAMP
             WHERE paziresh24_user_id = :user_id'
        );
        $stmt->execute([
            'user_id' => $userId,
            'window_end' => $windowEndTs,
        ]);
    }

    public static function getImportBackfillCutoffTs(?array $row): ?int
    {
        if ($row === null) {
            return null;
        }

        $raw = $row['import_future_vacations_last_cleared_at'] ?? null;
        if ($raw === null || $raw === '') {
            return null;
        }

        if (is_numeric($raw)) {
            $ts = (int) $raw;
            return $ts > 0 ? $ts : null;
        }

        $ts = strtotime((string) $raw);

        return $ts !== false && $ts > 0 ? $ts : null;
    }

    public static function markImportBackfillDone(string $userId): void
    {
        $userId = self::resolveStoredUserId($userId);
        ImportFutureVacationsRepository::lockDoctor($userId);

        $stmt = Database::connection()->prepare(
            'UPDATE google_tokens SET
                import_future_vacations_done_at = CURRENT_TIMESTAMP,
                updated_at = CURRENT_TIMESTAMP
             WHERE paziresh24_user_id = :user_id'
        );
        $stmt->execute(['user_id' => $userId]);
    }

    public static function clearImportFutureVacationsCompletion(string $userId): void
    {
        $userId = self::resolveStoredUserId($userId);
        ImportFutureVacationsRepository::unlockDoctor($userId);
        ImportFutureVacationsRepository::purgeBackfillSlotsForDoctor($userId);

        $stmt = Database::connection()->prepare(
            'UPDATE google_tokens SET
                import_future_vacations = 0,
                import_future_vacations_done_at = NULL,
                import_future_vacations_window_end = NULL,
                import_future_vacations_last_cleared_at = NULL,
                updated_at = CURRENT_TIMESTAMP
             WHERE paziresh24_user_id = :user_id'
        );
        $stmt->execute(['user_id' => $userId]);
    }

    private static function normalizeColorId(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '9';
        }

        return (string) $value;
    }

    public static function toBoolPublic(mixed $value): bool
    {
        return self::toBool($value);
    }

    /**
     * @return array{mode: string, center_ids: array<int, string>}
     */
    public static function defaultVacationSyncCenters(): array
    {
        return [
            'mode' => 'selected',
            'center_ids' => [],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{mode: string, center_ids: array<int, string>}
     */
    public static function parseVacationSyncCentersFromRow(array $row, bool $includeLegacyCenterId = true): array
    {
        $raw = $row['vacation_sync_centers'] ?? null;
        if (is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $parsed = self::sanitizeVacationSyncCenters($decoded);
                if ($parsed !== null) {
                    return $parsed;
                }
            }
        }

        if ($includeLegacyCenterId) {
            $legacyCenterId = isset($row['center_id']) && is_string($row['center_id'])
                ? trim($row['center_id'])
                : '';

            if ($legacyCenterId !== '' && self::isMedicalCenterId($legacyCenterId)) {
                return [
                    'mode' => 'selected',
                    'center_ids' => [$legacyCenterId],
                ];
            }
        }

        return self::defaultVacationSyncCenters();
    }

    /**
     * @return array{mode: string, center_ids: array<int, string>}|null
     */
    public static function parseVacationSyncCentersFromBody(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        return self::sanitizeVacationSyncCenters($value);
    }

    /**
     * @param array<string, mixed> $value
     * @return array{mode: string, center_ids: array<int, string>}|null
     */
    private static function sanitizeVacationSyncCenters(array $value): ?array
    {
        $mode = isset($value['mode']) && is_string($value['mode'])
            ? strtolower(trim($value['mode']))
            : '';

        if (!in_array($mode, ['all', 'selected'], true)) {
            return null;
        }

        $centerIds = [];
        $rawIds = $value['center_ids'] ?? $value['centerIds'] ?? [];
        if (is_array($rawIds)) {
            foreach ($rawIds as $id) {
                if (!is_scalar($id)) {
                    continue;
                }

                $normalized = trim((string) $id);
                if ($normalized !== '' && self::isMedicalCenterId($normalized)) {
                    $centerIds[] = $normalized;
                }
            }
        }

        $centerIds = array_values(array_unique($centerIds));

        if ($mode === 'selected' && $centerIds === []) {
            return [
                'mode' => 'selected',
                'center_ids' => [],
            ];
        }

        return [
            'mode' => $mode,
            'center_ids' => $mode === 'all' ? [] : $centerIds,
        ];
    }

    /**
     * @param array{mode: string, center_ids: array<int, string>} $selection
     */
    public static function encodeVacationSyncCentersForDb(array $selection): ?string
    {
        $payload = [
            'mode' => $selection['mode'] === 'selected' ? 'selected' : 'all',
            'center_ids' => $selection['mode'] === 'selected' ? array_values($selection['center_ids']) : [],
        ];

        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param array<int, array{
     *   medical_center_id: string,
     *   user_center_id: ?string,
     *   name: string,
     *   is_active_booking: bool
     * }> $availableCenters
     * @return array<int, array{medical_center_id: string, user_center_id: ?string, name: string}>
     */
    public static function filterVacationCentersForSync(array $tokenRow, array $availableCenters): array
    {
        if ($availableCenters === []) {
            return [];
        }

        $selection = self::parseVacationSyncCentersFromRow($tokenRow);

        if ($selection['mode'] === 'all') {
            return array_map(static function (array $center): array {
                return [
                    'medical_center_id' => $center['medical_center_id'],
                    'user_center_id' => $center['user_center_id'] ?? null,
                    'name' => $center['name'],
                ];
            }, $availableCenters);
        }

        $selectedIds = array_flip($selection['center_ids']);
        $filtered = [];

        foreach ($availableCenters as $center) {
            if (!isset($selectedIds[$center['medical_center_id']])) {
                continue;
            }

            $filtered[] = [
                'medical_center_id' => $center['medical_center_id'],
                'user_center_id' => $center['user_center_id'] ?? null,
                'name' => $center['name'],
            ];
        }

        return $filtered;
    }

    private static function isUuid(string $value): bool
    {
        return preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $value
        ) === 1;
    }

    private static function isMedicalCenterId(string $value): bool
    {
        return self::isUuid($value) || preg_match('/^\d+$/', $value) === 1;
    }

    private static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (int) $value === 1;
        }

        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }

    public static function markSyncPending(string $userId, string $operation = 'sync'): void
    {
        self::saveSyncStatus($userId, [
            'pending' => true,
            'operation' => $operation,
            'ok' => null,
            'warnings' => [],
            'backfill' => null,
        ]);
    }

    /**
     * @param array<string, mixed> $status
     */
    public static function saveSyncStatus(string $userId, array $status): void
    {
        $userId = self::resolveStoredUserId($userId);
        $status['updated_at'] = time();

        $json = json_encode($status, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return;
        }

        $stmt = Database::connection()->prepare(
            'UPDATE google_tokens SET last_sync_status = :status, updated_at = CURRENT_TIMESTAMP
             WHERE paziresh24_user_id = :user_id'
        );
        $stmt->execute([
            'user_id' => $userId,
            'status' => $json,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function getSyncStatus(string $userId): ?array
    {
        $row = self::findByUserId($userId);
        if ($row === null) {
            return null;
        }

        $raw = $row['last_sync_status'] ?? null;
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            return null;
        }

        if (($decoded['pending'] ?? false) === true) {
            $updatedAt = $decoded['updated_at'] ?? 0;
            if (is_numeric($updatedAt) && time() - (int) $updatedAt > 120) {
                $decoded['pending'] = false;
            }
        }

        return $decoded;
    }
}
