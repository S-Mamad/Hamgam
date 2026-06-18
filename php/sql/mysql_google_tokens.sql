-- نصب دیتابیس MySQL برای Hamgam روی zamanak24.ir
-- این فایل را در phpMyAdmin یا خط فرمان MySQL اجرا کنید

CREATE DATABASE IF NOT EXISTS hamgam
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE hamgam;

CREATE TABLE IF NOT EXISTS google_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    paziresh24_user_id VARCHAR(64) NOT NULL,
    google_refresh_token TEXT NULL,
    google_access_token TEXT NULL,
    hamdast_access_token TEXT NULL,
    color_id VARCHAR(8) NOT NULL DEFAULT '9',
    Patient_name TINYINT(1) NOT NULL DEFAULT 1,
    Patient_date_time TINYINT(1) NOT NULL DEFAULT 0,
    Patient_national TINYINT(1) NOT NULL DEFAULT 0,
    Patient_phone TINYINT(1) NOT NULL DEFAULT 0,
    Patient_center TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_google_tokens_user (paziresh24_user_id),
    INDEX idx_google_tokens_user (paziresh24_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
