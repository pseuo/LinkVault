ALTER TABLE links ADD COLUMN access_password_hash TEXT DEFAULT NULL
    CHECK (access_password_hash IS NULL OR length(access_password_hash) BETWEEN 20 AND 255);
ALTER TABLE links ADD COLUMN invalid_message TEXT NOT NULL DEFAULT ''
    CHECK (length(invalid_message) <= 500);
ALTER TABLE links ADD COLUMN fallback_url TEXT DEFAULT NULL
    CHECK (fallback_url IS NULL OR length(fallback_url) <= 2048);

CREATE TABLE link_password_attempts (
    link_id INTEGER NOT NULL,
    client_identifier_hash TEXT NOT NULL CHECK (length(client_identifier_hash) = 64),
    failures INTEGER NOT NULL CHECK (failures >= 0),
    window_started_at INTEGER NOT NULL,
    last_failed_at INTEGER NOT NULL,
    locked_until INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (link_id, client_identifier_hash),
    FOREIGN KEY (link_id) REFERENCES links(id) ON DELETE CASCADE
) WITHOUT ROWID;

CREATE INDEX link_password_attempts_cleanup_idx
    ON link_password_attempts (last_failed_at, locked_until);
