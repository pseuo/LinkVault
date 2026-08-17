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
