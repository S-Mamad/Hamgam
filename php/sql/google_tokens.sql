-- جدول معادل Data Table در n8n
CREATE TABLE IF NOT EXISTS google_tokens (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    paziresh24_user_id VARCHAR(64) NOT NULL UNIQUE,
    google_refresh_token TEXT,
    google_access_token TEXT,
    hamdast_access_token TEXT,
    color_id VARCHAR(8) NOT NULL DEFAULT '9',
    Patient_name INTEGER NOT NULL DEFAULT 1,
    Patient_date_time INTEGER NOT NULL DEFAULT 0,
    Patient_national INTEGER NOT NULL DEFAULT 0,
    Patient_phone INTEGER NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_google_tokens_user_id ON google_tokens (paziresh24_user_id);
