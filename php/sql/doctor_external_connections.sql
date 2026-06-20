CREATE TABLE IF NOT EXISTS doctor_external_connections (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    doctor_id TEXT NOT NULL,
    provider TEXT NOT NULL,
    access_token TEXT NOT NULL,
    refresh_token TEXT,
    expires_at INTEGER,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (doctor_id, provider)
);

CREATE INDEX IF NOT EXISTS idx_doctor_external_connections_doctor ON doctor_external_connections (doctor_id);
