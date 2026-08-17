CREATE TABLE api_tokens (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL CHECK (length(name) BETWEEN 1 AND 60),
    token_prefix TEXT NOT NULL CHECK (length(token_prefix) BETWEEN 8 AND 16),
    token_hash TEXT NOT NULL UNIQUE CHECK (length(token_hash) = 64),
    created_at TEXT NOT NULL,
    expires_at TEXT DEFAULT NULL,
    last_used_at TEXT DEFAULT NULL,
    use_count INTEGER NOT NULL DEFAULT 0 CHECK (use_count >= 0),
    revoked_at TEXT DEFAULT NULL,
    rotated_from_id INTEGER DEFAULT NULL,
    FOREIGN KEY (rotated_from_id) REFERENCES api_tokens(id) ON DELETE SET NULL
);

CREATE INDEX api_tokens_state_expiry_idx
    ON api_tokens (revoked_at, expires_at);

CREATE TABLE api_token_usage (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    token_id INTEGER DEFAULT NULL,
    token_name TEXT NOT NULL,
    token_prefix TEXT NOT NULL,
    used_at TEXT NOT NULL,
    outcome TEXT NOT NULL CHECK (outcome IN ('accepted', 'expired', 'revoked')),
    endpoint TEXT NOT NULL,
    request_id TEXT DEFAULT NULL,
    FOREIGN KEY (token_id) REFERENCES api_tokens(id) ON DELETE SET NULL
);

CREATE INDEX api_token_usage_used_idx
    ON api_token_usage (used_at DESC, id DESC);

CREATE INDEX api_token_usage_token_used_idx
    ON api_token_usage (token_id, used_at DESC, id DESC);
