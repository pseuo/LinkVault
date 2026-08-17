-- Frozen v6 schema fixture.
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
    last_accessed_at TEXT DEFAULT NULL,
    is_favorite INTEGER NOT NULL DEFAULT 0 CHECK (is_favorite IN (0, 1)),
    starts_at TEXT DEFAULT NULL,
    max_clicks INTEGER DEFAULT NULL CHECK (max_clicks IS NULL OR max_clicks > 0),
    is_one_time INTEGER NOT NULL DEFAULT 0 CHECK (is_one_time IN (0, 1))
);

CREATE INDEX links_state_id_idx ON links (deleted_at, id DESC);
CREATE INDEX links_target_url_idx ON links (target_url);
CREATE INDEX links_favorite_id_idx ON links (deleted_at, is_favorite, id DESC);

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

CREATE TABLE link_tags (
    link_id INTEGER NOT NULL,
    tag TEXT NOT NULL,
    PRIMARY KEY (link_id, tag),
    FOREIGN KEY (link_id) REFERENCES links(id) ON DELETE CASCADE
);

CREATE INDEX link_tags_tag_idx ON link_tags (tag, link_id);

CREATE TABLE link_status_history (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    link_id INTEGER NOT NULL,
    event TEXT NOT NULL,
    from_status TEXT DEFAULT NULL,
    to_status TEXT NOT NULL,
    created_at TEXT NOT NULL,
    FOREIGN KEY (link_id) REFERENCES links(id) ON DELETE CASCADE
);

CREATE INDEX link_status_history_link_date_idx ON link_status_history (link_id, created_at DESC);

CREATE TABLE create_requests (
    request_id TEXT PRIMARY KEY,
    payload_hash TEXT NOT NULL,
    link_id INTEGER NOT NULL,
    created_at TEXT NOT NULL,
    FOREIGN KEY (link_id) REFERENCES links(id) ON DELETE CASCADE
);

CREATE INDEX create_requests_created_idx ON create_requests (created_at);

CREATE TABLE healthcheck_probe (
    id INTEGER PRIMARY KEY CHECK (id = 1),
    checked_at TEXT NOT NULL
);

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
    created_at, updated_at, last_accessed_at, is_favorite, starts_at, max_clicks, is_one_time
) VALUES (
    1, 'legacy-v6', 'https://example.com/v6', 'Version 6 fixture', 12, 1, NULL, NULL,
    '2026-06-01T00:00:00Z', '2026-06-02T00:00:00Z', '2026-06-02T00:00:00Z',
    1, NULL, NULL, 0
);

INSERT INTO link_daily_stats (link_id, accessed_on, clicks) VALUES (1, '2026-06-02', 12);
INSERT INTO link_tags (link_id, tag) VALUES (1, 'legacy');
INSERT INTO link_status_history (link_id, event, from_status, to_status, created_at)
VALUES (1, 'created', NULL, 'active', '2026-06-01T00:00:00Z');

PRAGMA user_version = 6;
