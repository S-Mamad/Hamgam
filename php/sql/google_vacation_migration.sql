-- SQLite migration: Google Calendar → Paziresh24 Vacation Sync

ALTER TABLE google_tokens ADD COLUMN auto_vacation INTEGER NOT NULL DEFAULT 0;
ALTER TABLE google_tokens ADD COLUMN center_id TEXT;
ALTER TABLE google_tokens ADD COLUMN google_channel_id TEXT;
ALTER TABLE google_tokens ADD COLUMN google_resource_id TEXT;
ALTER TABLE google_tokens ADD COLUMN google_watch_expiration INTEGER;
ALTER TABLE google_tokens ADD COLUMN google_sync_token TEXT;

CREATE INDEX IF NOT EXISTS idx_google_tokens_channel ON google_tokens (google_channel_id);
CREATE INDEX IF NOT EXISTS idx_google_tokens_resource ON google_tokens (google_resource_id);

CREATE TABLE IF NOT EXISTS google_event_vacations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    paziresh24_user_id TEXT NOT NULL,
    google_event_id TEXT NOT NULL,
    event_summary TEXT,
    vacation_from INTEGER NOT NULL,
    vacation_to INTEGER NOT NULL,
    paziresh24_response TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (paziresh24_user_id, google_event_id)
);

CREATE INDEX IF NOT EXISTS idx_event_vacations_user ON google_event_vacations (paziresh24_user_id);

ALTER TABLE google_tokens ADD COLUMN import_future_vacations INTEGER NOT NULL DEFAULT 0;
ALTER TABLE google_tokens ADD COLUMN import_future_vacations_done_at DATETIME;
ALTER TABLE google_tokens ADD COLUMN import_future_vacations_window_end INTEGER;
ALTER TABLE google_tokens ADD COLUMN cancel_appointment_on_event_delete INTEGER NOT NULL DEFAULT 1;
ALTER TABLE google_tokens ADD COLUMN cancel_conflicting_appointments INTEGER NOT NULL DEFAULT 1;
