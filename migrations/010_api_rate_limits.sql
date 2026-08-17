CREATE TABLE api_rate_limits (
    identifier TEXT PRIMARY KEY,
    request_count INTEGER NOT NULL CHECK (request_count >= 0),
    window_started_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL
);

CREATE INDEX api_rate_limits_updated_idx
    ON api_rate_limits (updated_at);
