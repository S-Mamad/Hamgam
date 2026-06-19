<?php

declare(strict_types=1);

final class Database
{
    private static ?PDO $pdo = null;

    public static function connection(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $driver = Config::get('DB_DRIVER', 'sqlite');

        if ($driver === 'mysql') {
            $host = Config::require('DB_HOST');
            $port = Config::get('DB_PORT', '3306');
            $name = Config::require('DB_NAME');
            $user = Config::require('DB_USER');
            $pass = Config::get('DB_PASS', '');

            $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
            self::$pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } else {
            $path = Config::get('DB_PATH', __DIR__ . '/../storage/database.sqlite');
            if (!str_starts_with($path, '/') && !preg_match('/^[A-Za-z]:\\\\/', $path)) {
                $path = realpath(__DIR__ . '/..') . DIRECTORY_SEPARATOR . ltrim($path, '/\\');
            }

            $dir = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0750, true);
            }

            self::$pdo = new PDO('sqlite:' . $path, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        }

        self::migrate();

        return self::$pdo;
    }

    private static function migrate(): void
    {
        $pdo = self::$pdo;
        if (!$pdo instanceof PDO) {
            return;
        }

        $driver = Config::get('DB_DRIVER', 'sqlite');

        if ($driver === 'mysql') {
            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS google_tokens (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    paziresh24_user_id VARCHAR(64) NOT NULL UNIQUE,
                    google_refresh_token TEXT NULL,
                    google_access_token TEXT NULL,
                    hamdast_access_token TEXT NULL,
                    color_id VARCHAR(8) NOT NULL DEFAULT "9",
                    Patient_name TINYINT(1) NOT NULL DEFAULT 1,
                    Patient_date_time TINYINT(1) NOT NULL DEFAULT 0,
                    Patient_national TINYINT(1) NOT NULL DEFAULT 0,
                    Patient_phone TINYINT(1) NOT NULL DEFAULT 0,
                    Patient_center TINYINT(1) NOT NULL DEFAULT 1,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_google_tokens_user_id (paziresh24_user_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );
            self::ensureColumn($pdo, 'google_tokens', 'hamdast_access_token', 'TEXT NULL');
            self::ensureColumn($pdo, 'google_tokens', 'color_id', 'VARCHAR(8) NOT NULL DEFAULT "9"');
            self::ensureColumn($pdo, 'google_tokens', 'Patient_name', 'TINYINT(1) NOT NULL DEFAULT 1');
            self::ensureColumn($pdo, 'google_tokens', 'Patient_date_time', 'TINYINT(1) NOT NULL DEFAULT 0');
            self::ensureColumn($pdo, 'google_tokens', 'Patient_national', 'TINYINT(1) NOT NULL DEFAULT 0');
            self::ensureColumn($pdo, 'google_tokens', 'Patient_phone', 'TINYINT(1) NOT NULL DEFAULT 0');
            self::ensureColumn($pdo, 'google_tokens', 'Patient_center', 'TINYINT(1) NOT NULL DEFAULT 1');
            self::migrateVacationSchema($pdo, 'mysql');
            self::migrateBookingSchema($pdo, 'mysql');
            self::migrateImportFutureVacationsSchema($pdo, 'mysql');
            self::migrateLegacyDefaultColorId($pdo);
            return;
        }

        $schemaFile = __DIR__ . '/../sql/google_tokens.sql';
        if (is_file($schemaFile)) {
            $sql = file_get_contents($schemaFile);
            if ($sql !== false) {
                $pdo->exec($sql);
            }
        }

        self::ensureColumn($pdo, 'google_tokens', 'hamdast_access_token', 'TEXT');
        self::ensureColumn($pdo, 'google_tokens', 'color_id', 'VARCHAR(8) NOT NULL DEFAULT "9"');
        self::ensureColumn($pdo, 'google_tokens', 'Patient_name', 'INTEGER NOT NULL DEFAULT 1');
        self::ensureColumn($pdo, 'google_tokens', 'Patient_date_time', 'INTEGER NOT NULL DEFAULT 0');
        self::ensureColumn($pdo, 'google_tokens', 'Patient_national', 'INTEGER NOT NULL DEFAULT 0');
        self::ensureColumn($pdo, 'google_tokens', 'Patient_phone', 'INTEGER NOT NULL DEFAULT 0');
        self::ensureColumn($pdo, 'google_tokens', 'Patient_center', 'INTEGER NOT NULL DEFAULT 1');
        self::migrateVacationSchema($pdo, 'sqlite');
        self::migrateBookingSchema($pdo, 'sqlite');
        self::migrateImportFutureVacationsSchema($pdo, 'sqlite');
        self::migrateLegacyDefaultColorId($pdo);
    }

    private static function migrateLegacyDefaultColorId(PDO $pdo): void
    {
        try {
            $pdo->exec(
                "UPDATE google_tokens
                 SET color_id = '9'
                 WHERE color_id = '1'
                   AND (google_refresh_token IS NULL OR TRIM(google_refresh_token) = '')"
            );
        } catch (Throwable $e) {
            error_log('[Database] legacy color_id migration failed: ' . $e->getMessage());
        }
    }

    private static function migrateImportFutureVacationsSchema(PDO $pdo, string $driver): void
    {
        try {
            if ($driver === 'mysql') {
                $pdo->exec(
                    'CREATE TABLE IF NOT EXISTS import_future_vacations_doctor_lock (
                        paziresh24_user_id VARCHAR(64) NOT NULL PRIMARY KEY,
                        used_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        INDEX idx_import_future_doctor_lock_user (paziresh24_user_id)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
                );
                $pdo->exec(
                    'CREATE TABLE IF NOT EXISTS import_future_vacations_backfill_slots (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        paziresh24_user_id VARCHAR(64) NOT NULL,
                        google_event_id VARCHAR(256) NULL,
                        medical_center_id VARCHAR(64) NOT NULL,
                        vacation_from BIGINT NOT NULL,
                        vacation_to BIGINT NOT NULL,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        deleted_at TIMESTAMP NULL,
                        UNIQUE KEY uq_backfill_slot (paziresh24_user_id, medical_center_id, vacation_from, vacation_to),
                        INDEX idx_backfill_slots_doctor (paziresh24_user_id),
                        INDEX idx_backfill_slots_active (paziresh24_user_id, deleted_at)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
                );
            } else {
                $pdo->exec(
                    'CREATE TABLE IF NOT EXISTS import_future_vacations_doctor_lock (
                        paziresh24_user_id TEXT NOT NULL PRIMARY KEY,
                        used_at DATETIME DEFAULT CURRENT_TIMESTAMP
                    )'
                );
                $pdo->exec(
                    'CREATE TABLE IF NOT EXISTS import_future_vacations_backfill_slots (
                        id INTEGER PRIMARY KEY AUTOINCREMENT,
                        paziresh24_user_id TEXT NOT NULL,
                        google_event_id TEXT,
                        medical_center_id TEXT NOT NULL,
                        vacation_from INTEGER NOT NULL,
                        vacation_to INTEGER NOT NULL,
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                        deleted_at DATETIME,
                        UNIQUE (paziresh24_user_id, medical_center_id, vacation_from, vacation_to)
                    )'
                );
            }

            self::ensureColumn($pdo, 'import_future_vacations_backfill_slots', 'google_event_id', $driver === 'mysql' ? 'VARCHAR(256) NULL' : 'TEXT');

            require_once __DIR__ . '/ImportFutureVacationsRepository.php';
            ImportFutureVacationsRepository::migrateExistingDoctorLocks($pdo);
        } catch (Throwable $e) {
            error_log('[Database] import_future_vacations migration failed: ' . $e->getMessage());
        }
    }

    private static function migrateBookingSchema(PDO $pdo, string $driver): void
    {
        try {
            if ($driver === 'mysql') {
                $pdo->exec(
                    'CREATE TABLE IF NOT EXISTS google_calendar_bookings (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        paziresh24_user_id VARCHAR(64) NOT NULL,
                        book_id VARCHAR(128) NOT NULL,
                        google_event_id VARCHAR(256) NULL,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        UNIQUE KEY uq_user_book (paziresh24_user_id, book_id),
                        INDEX idx_calendar_bookings_user (paziresh24_user_id)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
                );
                self::ensureColumn($pdo, 'google_calendar_bookings', 'google_event_id', 'VARCHAR(256) NULL');
                return;
            }

            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS google_calendar_bookings (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    paziresh24_user_id TEXT NOT NULL,
                    book_id TEXT NOT NULL,
                    google_event_id TEXT,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE (paziresh24_user_id, book_id)
                )'
            );
            self::ensureColumn($pdo, 'google_calendar_bookings', 'google_event_id', 'TEXT');
        } catch (Throwable $e) {
            error_log('[Database] booking migration failed: ' . $e->getMessage());
        }
    }

    private static function migrateVacationSchema(PDO $pdo, string $driver): void
    {
        try {
            if ($driver === 'mysql') {
                self::ensureColumn($pdo, 'google_tokens', 'auto_vacation', 'TINYINT(1) NOT NULL DEFAULT 0');
                self::ensureColumn($pdo, 'google_tokens', 'center_id', 'VARCHAR(64) NULL');
                self::ensureColumn($pdo, 'google_tokens', 'google_channel_id', 'VARCHAR(128) NULL');
                self::ensureColumn($pdo, 'google_tokens', 'google_resource_id', 'VARCHAR(256) NULL');
                self::ensureColumn($pdo, 'google_tokens', 'google_watch_expiration', 'BIGINT NULL');
                self::ensureColumn($pdo, 'google_tokens', 'google_sync_token', 'TEXT NULL');
                self::ensureColumn($pdo, 'google_tokens', 'google_account_email', 'VARCHAR(255) NULL');
                self::ensureColumn($pdo, 'google_tokens', 'import_future_vacations', 'TINYINT(1) NOT NULL DEFAULT 0');
                self::ensureColumn($pdo, 'google_tokens', 'import_future_vacations_done_at', 'TIMESTAMP NULL');
                self::ensureColumn($pdo, 'google_tokens', 'import_future_vacations_window_end', 'BIGINT NULL');
                self::ensureColumn($pdo, 'google_tokens', 'import_future_vacations_last_cleared_at', 'TIMESTAMP NULL');
                self::ensureColumn($pdo, 'google_tokens', 'cancel_appointment_on_event_delete', 'TINYINT(1) NOT NULL DEFAULT 1');
                self::ensureColumn($pdo, 'google_tokens', 'cancel_conflicting_appointments', 'TINYINT(1) NOT NULL DEFAULT 1');
                self::ensureColumn($pdo, 'google_tokens', 'vacation_sync_centers', 'TEXT NULL');
                self::ensureColumn($pdo, 'google_tokens', 'last_sync_status', 'TEXT NULL');

                $pdo->exec(
                    'CREATE TABLE IF NOT EXISTS google_event_vacations (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        paziresh24_user_id VARCHAR(64) NOT NULL,
                        google_event_id VARCHAR(256) NOT NULL,
                        medical_center_id VARCHAR(64) NOT NULL DEFAULT \'\',
                        event_summary VARCHAR(512) NULL,
                        vacation_from BIGINT NOT NULL,
                        vacation_to BIGINT NOT NULL,
                        paziresh24_response TEXT NULL,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        UNIQUE KEY uq_user_event_center (paziresh24_user_id, google_event_id, medical_center_id),
                        INDEX idx_event_vacations_user (paziresh24_user_id)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
                );
                self::ensureColumn($pdo, 'google_event_vacations', 'medical_center_id', 'VARCHAR(64) NOT NULL DEFAULT \'\'');
                self::migrateEventVacationsUniqueKey($pdo, 'mysql');
                return;
            }

            self::ensureColumn($pdo, 'google_tokens', 'auto_vacation', 'INTEGER NOT NULL DEFAULT 0');
            self::ensureColumn($pdo, 'google_tokens', 'center_id', 'TEXT');
            self::ensureColumn($pdo, 'google_tokens', 'google_channel_id', 'TEXT');
            self::ensureColumn($pdo, 'google_tokens', 'google_resource_id', 'TEXT');
            self::ensureColumn($pdo, 'google_tokens', 'google_watch_expiration', 'INTEGER');
            self::ensureColumn($pdo, 'google_tokens', 'google_sync_token', 'TEXT');
            self::ensureColumn($pdo, 'google_tokens', 'google_account_email', 'TEXT');
            self::ensureColumn($pdo, 'google_tokens', 'import_future_vacations', 'INTEGER NOT NULL DEFAULT 0');
            self::ensureColumn($pdo, 'google_tokens', 'import_future_vacations_done_at', 'DATETIME');
            self::ensureColumn($pdo, 'google_tokens', 'import_future_vacations_window_end', 'INTEGER');
            self::ensureColumn($pdo, 'google_tokens', 'import_future_vacations_last_cleared_at', 'DATETIME');
            self::ensureColumn($pdo, 'google_tokens', 'cancel_appointment_on_event_delete', 'INTEGER NOT NULL DEFAULT 1');
            self::ensureColumn($pdo, 'google_tokens', 'cancel_conflicting_appointments', 'INTEGER NOT NULL DEFAULT 1');
            self::ensureColumn($pdo, 'google_tokens', 'vacation_sync_centers', 'TEXT');
            self::ensureColumn($pdo, 'google_tokens', 'last_sync_status', 'TEXT');

            $pdo->exec(
                'CREATE TABLE IF NOT EXISTS google_event_vacations (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    paziresh24_user_id TEXT NOT NULL,
                    google_event_id TEXT NOT NULL,
                    medical_center_id TEXT NOT NULL DEFAULT \'\',
                    event_summary TEXT,
                    vacation_from INTEGER NOT NULL,
                    vacation_to INTEGER NOT NULL,
                    paziresh24_response TEXT,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE (paziresh24_user_id, google_event_id, medical_center_id)
                )'
            );
            self::ensureColumn($pdo, 'google_event_vacations', 'medical_center_id', 'TEXT NOT NULL DEFAULT \'\'');
            self::migrateEventVacationsUniqueKey($pdo, 'sqlite');
        } catch (Throwable $e) {
            error_log('[Database] vacation migration failed: ' . $e->getMessage());
        }
    }

    private static function migrateEventVacationsUniqueKey(PDO $pdo, string $driver): void
    {
        try {
            if ($driver === 'mysql') {
                $stmt = $pdo->query(
                    "SELECT COUNT(*) FROM information_schema.STATISTICS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME = 'google_event_vacations'
                       AND INDEX_NAME = 'uq_user_event'"
                );
                if ($stmt !== false && (int) $stmt->fetchColumn() > 0) {
                    $pdo->exec('ALTER TABLE google_event_vacations DROP INDEX uq_user_event');
                }

                $stmt = $pdo->query(
                    "SELECT COUNT(*) FROM information_schema.STATISTICS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME = 'google_event_vacations'
                       AND INDEX_NAME = 'uq_user_event_center'"
                );
                if ($stmt !== false && (int) $stmt->fetchColumn() === 0) {
                    $pdo->exec(
                        'ALTER TABLE google_event_vacations
                         ADD UNIQUE KEY uq_user_event_center (paziresh24_user_id, google_event_id, medical_center_id)'
                    );
                }

                return;
            }

            $stmt = $pdo->query("PRAGMA index_list(google_event_vacations)");
            if ($stmt === false) {
                return;
            }

            $hasMultiCenterUnique = false;
            $hasLegacyUnique = false;
            while ($row = $stmt->fetch()) {
                $indexName = (string) ($row['name'] ?? '');
                if ($indexName === 'sqlite_autoindex_google_event_vacations_1') {
                    $info = $pdo->query("PRAGMA index_info({$indexName})");
                    if ($info !== false) {
                        $cols = [];
                        while ($col = $info->fetch()) {
                            $cols[] = (string) ($col['name'] ?? '');
                        }
                        if (count($cols) === 3) {
                            $hasMultiCenterUnique = true;
                        } elseif (count($cols) === 2) {
                            $hasLegacyUnique = true;
                        }
                    }
                }
            }

            if ($hasMultiCenterUnique || !$hasLegacyUnique) {
                return;
            }

            $pdo->exec('BEGIN');
            $pdo->exec(
                'CREATE TABLE google_event_vacations_migrated (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    paziresh24_user_id TEXT NOT NULL,
                    google_event_id TEXT NOT NULL,
                    medical_center_id TEXT NOT NULL DEFAULT \'\',
                    event_summary TEXT,
                    vacation_from INTEGER NOT NULL,
                    vacation_to INTEGER NOT NULL,
                    paziresh24_response TEXT,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE (paziresh24_user_id, google_event_id, medical_center_id)
                )'
            );
            $pdo->exec(
                'INSERT INTO google_event_vacations_migrated (
                    id, paziresh24_user_id, google_event_id, medical_center_id,
                    event_summary, vacation_from, vacation_to, paziresh24_response, created_at
                )
                SELECT
                    id, paziresh24_user_id, google_event_id,
                    COALESCE(NULLIF(medical_center_id, \'\'), \'\'),
                    event_summary, vacation_from, vacation_to, paziresh24_response, created_at
                FROM google_event_vacations'
            );
            $pdo->exec('DROP TABLE google_event_vacations');
            $pdo->exec('ALTER TABLE google_event_vacations_migrated RENAME TO google_event_vacations');
            $pdo->exec('COMMIT');
        } catch (Throwable $e) {
            if ($driver === 'sqlite') {
                try {
                    $pdo->exec('ROLLBACK');
                } catch (Throwable) {
                }
            }
            error_log('[Database] migrateEventVacationsUniqueKey failed: ' . $e->getMessage());
        }
    }

    private static function ensureColumn(PDO $pdo, string $table, string $column, string $definition): void
    {
        try {
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

            if ($driver === 'mysql') {
                $stmt = $pdo->prepare(
                    'SELECT COUNT(*) FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column'
                );
                $stmt->execute(['table' => $table, 'column' => $column]);
                if ((int) $stmt->fetchColumn() > 0) {
                    return;
                }

                $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
                return;
            }

            $stmt = $pdo->query("PRAGMA table_info({$table})");
            if ($stmt === false) {
                return;
            }

            while ($row = $stmt->fetch()) {
                if (($row['name'] ?? '') === $column) {
                    return;
                }
            }

            $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
        } catch (Throwable $e) {
            error_log("[Database] ensureColumn {$table}.{$column} failed: " . $e->getMessage());
        }
    }
}
