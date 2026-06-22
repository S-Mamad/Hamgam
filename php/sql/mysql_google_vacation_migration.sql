-- Migration: Google Calendar → Paziresh24 Vacation Sync
-- اجرا بعد از mysql_google_tokens.sql

USE hamgam;

-- ۱) فیلدهای جدید روی google_tokens (سازگار با MySQL قدیمی‌تر بدون IF NOT EXISTS روی ADD COLUMN)

SET @db = DATABASE();

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'google_tokens' AND COLUMN_NAME = 'auto_vacation') = 0,
    'ALTER TABLE google_tokens ADD COLUMN auto_vacation TINYINT(1) NOT NULL DEFAULT 0 AFTER Patient_phone',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'google_tokens' AND COLUMN_NAME = 'center_id') = 0,
    'ALTER TABLE google_tokens ADD COLUMN center_id VARCHAR(64) NULL AFTER auto_vacation',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'google_tokens' AND COLUMN_NAME = 'google_channel_id') = 0,
    'ALTER TABLE google_tokens ADD COLUMN google_channel_id VARCHAR(128) NULL AFTER center_id',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'google_tokens' AND COLUMN_NAME = 'google_resource_id') = 0,
    'ALTER TABLE google_tokens ADD COLUMN google_resource_id VARCHAR(256) NULL AFTER google_channel_id',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'google_tokens' AND COLUMN_NAME = 'google_watch_expiration') = 0,
    'ALTER TABLE google_tokens ADD COLUMN google_watch_expiration BIGINT NULL AFTER google_resource_id',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'google_tokens' AND COLUMN_NAME = 'google_sync_token') = 0,
    'ALTER TABLE google_tokens ADD COLUMN google_sync_token TEXT NULL AFTER google_watch_expiration',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'google_tokens' AND INDEX_NAME = 'idx_google_tokens_channel') = 0,
    'CREATE INDEX idx_google_tokens_channel ON google_tokens (google_channel_id)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'google_tokens' AND INDEX_NAME = 'idx_google_tokens_resource') = 0,
    'CREATE INDEX idx_google_tokens_resource ON google_tokens (google_resource_id)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ۲) جدول ردیابی ایونت‌های پردازش‌شده
CREATE TABLE IF NOT EXISTS google_event_vacations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    paziresh24_user_id VARCHAR(64) NOT NULL,
    google_event_id VARCHAR(256) NOT NULL,
    event_summary VARCHAR(512) NULL,
    vacation_from BIGINT NOT NULL,
    vacation_to BIGINT NOT NULL,
    paziresh24_response JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_event (paziresh24_user_id, google_event_id),
    INDEX idx_event_vacations_user (paziresh24_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'google_tokens' AND COLUMN_NAME = 'import_future_vacations') = 0,
    'ALTER TABLE google_tokens ADD COLUMN import_future_vacations TINYINT(1) NOT NULL DEFAULT 0 AFTER google_account_email',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'google_tokens' AND COLUMN_NAME = 'import_future_vacations_done_at') = 0,
    'ALTER TABLE google_tokens ADD COLUMN import_future_vacations_done_at TIMESTAMP NULL AFTER import_future_vacations',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'google_tokens' AND COLUMN_NAME = 'import_future_vacations_window_end') = 0,
    'ALTER TABLE google_tokens ADD COLUMN import_future_vacations_window_end BIGINT NULL AFTER import_future_vacations_done_at',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'google_tokens' AND COLUMN_NAME = 'cancel_appointment_on_event_delete') = 0,
    'ALTER TABLE google_tokens ADD COLUMN cancel_appointment_on_event_delete TINYINT(1) NOT NULL DEFAULT 1 AFTER import_future_vacations_window_end',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'google_tokens' AND COLUMN_NAME = 'cancel_conflicting_appointments') = 0,
    'ALTER TABLE google_tokens ADD COLUMN cancel_conflicting_appointments TINYINT(1) NOT NULL DEFAULT 1 AFTER cancel_appointment_on_event_delete',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'google_tokens' AND COLUMN_NAME = 'vacation_sync_centers') = 0,
    'ALTER TABLE google_tokens ADD COLUMN vacation_sync_centers TEXT NULL AFTER cancel_conflicting_appointments',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'google_event_vacations' AND COLUMN_NAME = 'medical_center_id') = 0,
    'ALTER TABLE google_event_vacations ADD COLUMN medical_center_id VARCHAR(64) NOT NULL DEFAULT \'\' AFTER google_event_id',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'google_event_vacations' AND INDEX_NAME = 'uq_user_event') > 0,
    'ALTER TABLE google_event_vacations DROP INDEX uq_user_event',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'google_event_vacations' AND INDEX_NAME = 'uq_user_event_center') = 0,
    'ALTER TABLE google_event_vacations ADD UNIQUE KEY uq_user_event_center (paziresh24_user_id, google_event_id, medical_center_id)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
