-- Frozen v1 schema fixture. Do not replace this with current migrations.
PRAGMA foreign_keys = OFF;

CREATE TABLE links (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    slug TEXT NOT NULL UNIQUE,
    target_url TEXT NOT NULL,
    title TEXT NOT NULL DEFAULT '',
    clicks INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    last_accessed_at TEXT DEFAULT NULL
);

CREATE TABLE link_daily_stats (
    link_id INTEGER NOT NULL,
    accessed_on TEXT NOT NULL,
    clicks INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (link_id, accessed_on)
);

CREATE INDEX link_daily_stats_date_idx ON link_daily_stats (accessed_on DESC);

CREATE TABLE login_attempts (
    identifier TEXT PRIMARY KEY,
    failures INTEGER NOT NULL,
    window_started_at INTEGER NOT NULL,
    last_failed_at INTEGER NOT NULL,
    locked_until INTEGER NOT NULL DEFAULT 0
);

CREATE INDEX login_attempts_expiry_idx ON login_attempts (last_failed_at, locked_until);

INSERT INTO links (
    id, slug, target_url, title, clicks, created_at, updated_at, last_accessed_at
) VALUES (
    1, 'legacy-v1', 'https://example.com/v1', 'Version 1 fixture', 7,
    '2026-01-01T00:00:00Z', '2026-01-02T00:00:00Z', '2026-01-02T00:00:00Z'
);

INSERT INTO link_daily_stats (link_id, accessed_on, clicks) VALUES
    (1, '2026-01-02', 7),
    (999, '2026-01-02', 3);

PRAGMA user_version = 1;
