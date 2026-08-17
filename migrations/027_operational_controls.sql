ALTER TABLE api_tokens ADD COLUMN quota_requests INTEGER DEFAULT NULL
CHECK (quota_requests IS NULL OR quota_requests BETWEEN 1 AND 1000000);

ALTER TABLE api_tokens ADD COLUMN quota_window_seconds INTEGER DEFAULT NULL
CHECK (quota_window_seconds IS NULL OR quota_window_seconds BETWEEN 1 AND 86400);

ALTER TABLE api_tokens ADD COLUMN allowed_cidrs TEXT NOT NULL DEFAULT ''
CHECK (length(allowed_cidrs) <= 2000);

CREATE TABLE api_token_alerts (
    token_id INTEGER NOT NULL,
    alert_type TEXT NOT NULL CHECK (alert_type IN ('cidr_denied', 'rate_limited')),
    occurrence_count INTEGER NOT NULL DEFAULT 1 CHECK (occurrence_count >= 1),
    first_seen_at TEXT NOT NULL,
    last_seen_at TEXT NOT NULL,
    last_endpoint TEXT NOT NULL,
    last_client_ip TEXT NOT NULL,
    PRIMARY KEY (token_id, alert_type),
    FOREIGN KEY (token_id) REFERENCES api_tokens(id) ON DELETE CASCADE
);

ALTER TABLE short_domain_retirement_jobs RENAME TO short_domain_retirement_jobs_v24;

CREATE TABLE short_domain_retirement_jobs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    source_id INTEGER NOT NULL UNIQUE,
    source_hostname TEXT NOT NULL,
    destination_id INTEGER DEFAULT NULL,
    status TEXT NOT NULL CHECK (status IN ('pending', 'running', 'paused', 'completed', 'failed', 'canceled')),
    total_count INTEGER NOT NULL DEFAULT 0 CHECK (total_count >= 0),
    migrated_count INTEGER NOT NULL DEFAULT 0 CHECK (migrated_count >= 0),
    attempt_count INTEGER NOT NULL DEFAULT 0 CHECK (attempt_count >= 0),
    last_error TEXT DEFAULT NULL CHECK (last_error IS NULL OR length(last_error) <= 300),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    completed_at TEXT DEFAULT NULL
);

INSERT INTO short_domain_retirement_jobs (
    id, source_id, source_hostname, destination_id, status, total_count, migrated_count,
    attempt_count, last_error, created_at, updated_at, completed_at
)
SELECT j.id, j.source_id, COALESCE(d.hostname, '#' || j.source_id), j.destination_id,
       j.status, j.migrated_count, j.migrated_count,
       CASE WHEN j.status IN ('running', 'completed', 'failed') THEN 1 ELSE 0 END,
       j.last_error, j.created_at, j.updated_at, j.completed_at
FROM short_domain_retirement_jobs_v24 j
LEFT JOIN short_domains d ON d.id = j.source_id;

DROP TABLE short_domain_retirement_jobs_v24;

CREATE INDEX short_domain_retirement_jobs_status_idx
    ON short_domain_retirement_jobs (status, updated_at, id);
