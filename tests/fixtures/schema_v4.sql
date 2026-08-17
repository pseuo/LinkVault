-- Frozen v4 schema fixture.
PRAGMA foreign_keys = OFF;

CREATE TABLE links (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    slug TEXT NOT NULL UNIQUE,
    target_url TEXT NOT NULL,
    title TEXT NOT NULL DEFAULT '',
    clicks INTEGER NOT NULL DEFAULT 0,
    is_active INTEGER NOT NULL DEFAULT 1,
    expires_at TEXT DEFAULT NULL,
    deleted_at TEXT DEFAULT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    last_accessed_at TEXT DEFAULT NULL
);

CREATE INDEX links_state_id_idx ON links (deleted_at, id DESC);

CREATE TABLE link_daily_stats (
    link_id INTEGER NOT NULL,
    accessed_on TEXT NOT NULL,
    clicks INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (link_id, accessed_on),
    FOREIGN KEY (link_id) REFERENCES links(id) ON DELETE CASCADE
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

CREATE VIRTUAL TABLE links_fts USING fts5(
    title, slug, target_url, content = 'links', content_rowid = 'id', tokenize = 'unicode61'
);

CREATE TRIGGER links_fts_insert AFTER INSERT ON links BEGIN
    INSERT INTO links_fts(rowid, title, slug, target_url)
    VALUES (new.id, new.title, new.slug, new.target_url);
END;

CREATE TRIGGER links_fts_delete AFTER DELETE ON links BEGIN
    INSERT INTO links_fts(links_fts, rowid, title, slug, target_url)
    VALUES ('delete', old.id, old.title, old.slug, old.target_url);
END;

CREATE TRIGGER links_fts_update AFTER UPDATE OF title, slug, target_url ON links BEGIN
    INSERT INTO links_fts(links_fts, rowid, title, slug, target_url)
    VALUES ('delete', old.id, old.title, old.slug, old.target_url);
    INSERT INTO links_fts(rowid, title, slug, target_url)
    VALUES (new.id, new.title, new.slug, new.target_url);
END;

INSERT INTO links (
    id, slug, target_url, title, clicks, is_active, expires_at, deleted_at,
    created_at, updated_at, last_accessed_at
) VALUES (
    1, 'legacy-v4', 'https://example.com/v4', 'Version 4 fixture', 10, 1, NULL, NULL,
    '2026-04-01T00:00:00Z', '2026-04-02T00:00:00Z', '2026-04-02T00:00:00Z'
);

INSERT INTO link_daily_stats (link_id, accessed_on, clicks) VALUES (1, '2026-04-02', 10);

PRAGMA user_version = 4;
