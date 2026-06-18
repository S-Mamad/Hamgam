-- Migration: add Patient_center toggle for appointment event descriptions
-- Run once on existing MySQL databases (new installs use mysql_google_tokens.sql)

USE hamgam;

SET @has_patient_center := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'google_tokens'
      AND COLUMN_NAME = 'Patient_center'
);

SET @sql := IF(
    @has_patient_center = 0,
    'ALTER TABLE google_tokens ADD COLUMN Patient_center TINYINT(1) NOT NULL DEFAULT 0 AFTER Patient_phone',
    'SELECT 1'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
