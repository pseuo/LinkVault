-- Frozen v2 schema fixture. Its statistics table intentionally has no foreign key.
PRAGMA foreign_keys = OFF;

CREATE TABLE links (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    slug TEXT NOT NULL UNIQUE,
    target_url TEXT NOT NULL,
    title TEXT NOT NULL DEFAULT '',
    clicks INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    last_accessed_at TEXT DEFAULT NULL,
    is_active INTEGER NOT NULL DEFAULT 1,
    expires_at TEXT DEFAULT NULL,
    deleted_at TEXT DEFAULT NULL
);

CREATE INDEX links_state_id_idx ON links (deleted_at, id DESC);

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
    id, slug, target_url, title, clicks, created_at, updated_at, last_accessed_at,
    is_active, expires_at, deleted_at
) VALUES (
    1, 'legacy-v2', 'https://example.com/v2', 'Version 2 fixture', 8,
    '2026-02-01T00:00:00Z', '2026-02-02T00:00:00Z', '2026-02-02T00:00:00Z',
    1, NULL, NULL
);

INSERT INTO link_daily_stats (link_id, accessed_on, clicks) VALUES
    (1, '2026-02-02', 8),
    (999, '2026-02-02', 3);

PRAGMA user_version = 2;
