CREATE TABLE notification_claims (
    notification_type TEXT NOT NULL,
    dedupe_key TEXT NOT NULL,
    leased_until TEXT NOT NULL,
    completed_at TEXT DEFAULT NULL,
    last_error TEXT DEFAULT NULL CHECK (last_error IS NULL OR length(last_error) <= 300),
    updated_at TEXT NOT NULL,
    PRIMARY KEY (notification_type, dedupe_key)
);

CREATE INDEX notification_claims_cleanup_idx
    ON notification_claims (completed_at, leased_until);

CREATE TABLE short_domain_retirement_jobs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    source_id INTEGER NOT NULL UNIQUE,
    destination_id INTEGER DEFAULT NULL,
    status TEXT NOT NULL CHECK (status IN ('pending', 'running', 'completed', 'failed')),
    migrated_count INTEGER NOT NULL DEFAULT 0 CHECK (migrated_count >= 0),
    last_error TEXT DEFAULT NULL CHECK (last_error IS NULL OR length(last_error) <= 300),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    completed_at TEXT DEFAULT NULL
);

CREATE INDEX short_domain_retirement_jobs_status_idx
    ON short_domain_retirement_jobs (status, updated_at, id);
