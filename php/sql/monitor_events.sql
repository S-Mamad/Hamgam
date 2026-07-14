CREATE TABLE IF NOT EXISTS monitor_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    request_id TEXT NOT NULL DEFAULT '',
    channel TEXT NOT NULL DEFAULT 'system',
    level TEXT NOT NULL DEFAULT 'info',
    category TEXT NOT NULL DEFAULT 'system',
    action TEXT,
    message TEXT NOT NULL,
    user_id TEXT,
    entity_type TEXT,
    entity_id TEXT,
    context_json TEXT,
    duration_ms INTEGER,
    http_status INTEGER,
    ip_address TEXT,
    source_file TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_monitor_events_created ON monitor_events (created_at DESC);
CREATE INDEX IF NOT EXISTS idx_monitor_events_channel ON monitor_events (channel, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_monitor_events_level ON monitor_events (level, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_monitor_events_user ON monitor_events (user_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_monitor_events_request ON monitor_events (request_id);
CREATE INDEX IF NOT EXISTS idx_monitor_events_category ON monitor_events (category, created_at DESC);

CREATE TABLE IF NOT EXISTS monitor_daily_rollups (
    rollup_date TEXT NOT NULL,
    channel TEXT NOT NULL DEFAULT '_all',
    level TEXT NOT NULL DEFAULT '_all',
    event_count INTEGER NOT NULL DEFAULT 0,
    error_count INTEGER NOT NULL DEFAULT 0,
    avg_duration_ms REAL,
    PRIMARY KEY (rollup_date, channel, level)
);
