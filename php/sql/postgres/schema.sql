-- Hamgam / Zamanak PostgreSQL schema reference
-- Tables are also created automatically by Database::migrate() on first connection.

CREATE TABLE IF NOT EXISTS google_tokens (
    id SERIAL PRIMARY KEY,
    paziresh24_user_id VARCHAR(64) NOT NULL UNIQUE,
    google_refresh_token TEXT NULL,
    google_access_token TEXT NULL,
    hamdast_access_token TEXT NULL,
    color_id VARCHAR(8) NOT NULL DEFAULT '9',
    patient_name SMALLINT NOT NULL DEFAULT 1,
    patient_date_time SMALLINT NOT NULL DEFAULT 0,
    patient_national SMALLINT NOT NULL DEFAULT 1,
    patient_phone SMALLINT NOT NULL DEFAULT 0,
    patient_center SMALLINT NOT NULL DEFAULT 1,
    auto_vacation SMALLINT NOT NULL DEFAULT 0,
    center_id VARCHAR(64) NULL,
    google_channel_id VARCHAR(128) NULL,
    google_resource_id VARCHAR(256) NULL,
    google_watch_expiration BIGINT NULL,
    google_sync_token TEXT NULL,
    google_account_email VARCHAR(255) NULL,
    import_future_vacations SMALLINT NOT NULL DEFAULT 0,
    import_future_vacations_done_at TIMESTAMP NULL,
    import_future_vacations_window_end BIGINT NULL,
    import_future_vacations_last_cleared_at TIMESTAMP NULL,
    cancel_appointment_on_event_delete SMALLINT NOT NULL DEFAULT 1,
    cancel_conflicting_appointments SMALLINT NOT NULL DEFAULT 0,
    vacation_sync_centers TEXT NULL,
    last_sync_status TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_google_tokens_user_id ON google_tokens (paziresh24_user_id);

CREATE TABLE IF NOT EXISTS google_event_vacations (
    id SERIAL PRIMARY KEY,
    paziresh24_user_id VARCHAR(64) NOT NULL,
    google_event_id VARCHAR(256) NOT NULL,
    medical_center_id VARCHAR(64) NOT NULL DEFAULT '',
    event_summary VARCHAR(512) NULL,
    vacation_from BIGINT NOT NULL,
    vacation_to BIGINT NOT NULL,
    paziresh24_response TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (paziresh24_user_id, google_event_id, medical_center_id)
);

CREATE TABLE IF NOT EXISTS google_calendar_bookings (
    id SERIAL PRIMARY KEY,
    paziresh24_user_id VARCHAR(64) NOT NULL,
    book_id VARCHAR(128) NOT NULL,
    google_event_id VARCHAR(256) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (paziresh24_user_id, book_id)
);

CREATE TABLE IF NOT EXISTS import_future_vacations_doctor_lock (
    paziresh24_user_id VARCHAR(64) NOT NULL PRIMARY KEY,
    used_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS import_future_vacations_backfill_slots (
    id SERIAL PRIMARY KEY,
    paziresh24_user_id VARCHAR(64) NOT NULL,
    google_event_id VARCHAR(256) NULL,
    medical_center_id VARCHAR(64) NOT NULL,
    vacation_from BIGINT NOT NULL,
    vacation_to BIGINT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    UNIQUE (paziresh24_user_id, medical_center_id, vacation_from, vacation_to)
);

CREATE TABLE IF NOT EXISTS doctor_external_connections (
    id SERIAL PRIMARY KEY,
    doctor_id VARCHAR(64) NOT NULL,
    provider VARCHAR(32) NOT NULL,
    access_token TEXT NOT NULL,
    refresh_token TEXT NULL,
    expires_at BIGINT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (doctor_id, provider)
);

CREATE TABLE IF NOT EXISTS drdr_pending_otp (
    doctor_id VARCHAR(64) NOT NULL PRIMARY KEY,
    mobile VARCHAR(16) NOT NULL,
    init_payload TEXT NULL,
    sent_at INTEGER NOT NULL,
    expires_at INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS monitor_events (
    id BIGSERIAL PRIMARY KEY,
    request_id VARCHAR(64) NOT NULL DEFAULT '',
    channel VARCHAR(64) NOT NULL DEFAULT 'system',
    level VARCHAR(16) NOT NULL DEFAULT 'info',
    category VARCHAR(32) NOT NULL DEFAULT 'system',
    action VARCHAR(128) NULL,
    message TEXT NOT NULL,
    user_id VARCHAR(64) NULL,
    entity_type VARCHAR(32) NULL,
    entity_id VARCHAR(256) NULL,
    context_json TEXT NULL,
    duration_ms INTEGER NULL,
    http_status INTEGER NULL,
    ip_address VARCHAR(45) NULL,
    source_file VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS monitor_daily_rollups (
    rollup_date DATE NOT NULL,
    channel VARCHAR(64) NOT NULL DEFAULT '_all',
    level VARCHAR(16) NOT NULL DEFAULT '_all',
    event_count INTEGER NOT NULL DEFAULT 0,
    error_count INTEGER NOT NULL DEFAULT 0,
    avg_duration_ms DOUBLE PRECISION NULL,
    PRIMARY KEY (rollup_date, channel, level)
);
