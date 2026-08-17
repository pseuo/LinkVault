CREATE TABLE link_presets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL COLLATE NOCASE UNIQUE CHECK (length(name) BETWEEN 1 AND 60),
    values_json TEXT NOT NULL CHECK (length(values_json) BETWEEN 2 AND 8192),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE link_aliases (
    alias TEXT PRIMARY KEY CHECK (length(alias) BETWEEN 3 AND 64),
    link_id INTEGER NOT NULL,
    created_at TEXT NOT NULL,
    FOREIGN KEY (link_id) REFERENCES links(id) ON DELETE CASCADE
);

CREATE INDEX link_aliases_link_idx ON link_aliases (link_id, created_at);

CREATE TRIGGER link_aliases_slug_collision_insert
BEFORE INSERT ON link_aliases
WHEN EXISTS (SELECT 1 FROM links WHERE slug = NEW.alias)
BEGIN
    SELECT RAISE(ABORT, 'short code is already in use');
END;

CREATE TRIGGER links_alias_collision_insert
BEFORE INSERT ON links
WHEN EXISTS (SELECT 1 FROM link_aliases WHERE alias = NEW.slug)
BEGIN
    SELECT RAISE(ABORT, 'short code is already in use');
END;

CREATE TRIGGER links_alias_collision_update
BEFORE UPDATE OF slug ON links
WHEN NEW.slug <> OLD.slug AND EXISTS (SELECT 1 FROM link_aliases WHERE alias = NEW.slug)
BEGIN
    SELECT RAISE(ABORT, 'short code is already in use');
END;

ALTER TABLE webhook_outbox
ADD COLUMN replay_count INTEGER NOT NULL DEFAULT 0 CHECK (replay_count >= 0);

CREATE TABLE webhook_delivery_attempts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    event_id TEXT NOT NULL,
    attempt_number INTEGER NOT NULL CHECK (attempt_number >= 1),
    attempted_at TEXT NOT NULL,
    http_status INTEGER DEFAULT NULL CHECK (http_status IS NULL OR http_status BETWEEN 100 AND 599),
    duration_ms INTEGER NOT NULL CHECK (duration_ms >= 0),
    error TEXT DEFAULT NULL CHECK (error IS NULL OR length(error) <= 300),
    FOREIGN KEY (event_id) REFERENCES webhook_outbox(event_id) ON DELETE CASCADE
);

CREATE INDEX webhook_delivery_attempts_event_idx
    ON webhook_delivery_attempts (event_id, attempted_at DESC, id DESC);

ALTER TABLE target_health ADD COLUMN ignored_at TEXT DEFAULT NULL;
ALTER TABLE target_health ADD COLUMN ignored_reason TEXT NOT NULL DEFAULT '' CHECK (length(ignored_reason) <= 200);
