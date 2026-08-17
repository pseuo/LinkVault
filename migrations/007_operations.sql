CREATE TABLE audit_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    created_at TEXT NOT NULL,
    actor_type TEXT NOT NULL,
    action TEXT NOT NULL,
    outcome TEXT NOT NULL,
    entity_type TEXT DEFAULT NULL,
    entity_id TEXT DEFAULT NULL,
    request_id TEXT DEFAULT NULL,
    details_json TEXT NOT NULL DEFAULT '{}'
);

CREATE INDEX audit_events_created_idx ON audit_events (created_at DESC, id DESC);
CREATE INDEX audit_events_outcome_created_idx ON audit_events (outcome, created_at DESC);
CREATE INDEX audit_events_entity_created_idx ON audit_events (entity_type, entity_id, created_at DESC);

CREATE TABLE idempotency_requests (
    operation TEXT NOT NULL,
    key_hash TEXT NOT NULL CHECK (length(key_hash) = 64),
    payload_hash TEXT NOT NULL CHECK (length(payload_hash) = 64),
    response_status INTEGER NOT NULL CHECK (response_status BETWEEN 200 AND 299),
    response_body TEXT NOT NULL,
    created_at INTEGER NOT NULL,
    expires_at INTEGER NOT NULL CHECK (expires_at > created_at),
    PRIMARY KEY (operation, key_hash)
) WITHOUT ROWID;

CREATE INDEX idempotency_requests_expires_idx ON idempotency_requests (expires_at);
