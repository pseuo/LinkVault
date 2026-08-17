CREATE TABLE analytics_export_jobs (
    id TEXT PRIMARY KEY CHECK (length(id) = 32),
    owner_hash TEXT NOT NULL CHECK (length(owner_hash) = 64),
    report TEXT NOT NULL CHECK (report IN ('filtered', 'trend', 'sources', 'devices', 'countries', 'campaigns')),
    request_json TEXT NOT NULL,
    status TEXT NOT NULL CHECK (status IN ('pending', 'running', 'completed', 'failed')),
    attempts INTEGER NOT NULL DEFAULT 0 CHECK (attempts >= 0),
    available_at TEXT NOT NULL,
    leased_until TEXT DEFAULT NULL,
    lease_token TEXT DEFAULT NULL CHECK (lease_token IS NULL OR length(lease_token) = 32),
    row_count INTEGER NOT NULL DEFAULT 0 CHECK (row_count >= 0),
    artifact_name TEXT DEFAULT NULL CHECK (artifact_name IS NULL OR length(artifact_name) <= 80),
    download_name TEXT DEFAULT NULL CHECK (download_name IS NULL OR length(download_name) <= 180),
    size_bytes INTEGER DEFAULT NULL CHECK (size_bytes IS NULL OR size_bytes >= 0),
    last_error TEXT DEFAULT NULL CHECK (last_error IS NULL OR length(last_error) <= 300),
    created_at TEXT NOT NULL,
    started_at TEXT DEFAULT NULL,
    completed_at TEXT DEFAULT NULL,
    expires_at TEXT NOT NULL
) WITHOUT ROWID;

CREATE INDEX analytics_export_jobs_queue_idx
    ON analytics_export_jobs (status, available_at, leased_until, created_at);

CREATE INDEX analytics_export_jobs_owner_idx
    ON analytics_export_jobs (owner_hash, created_at);

CREATE TABLE analytics_daily_dimensions (
    link_id INTEGER NOT NULL,
    accessed_on TEXT NOT NULL,
    country_code TEXT NOT NULL,
    device_type TEXT NOT NULL,
    browser TEXT NOT NULL,
    operating_system TEXT NOT NULL,
    referrer_domain TEXT NOT NULL,
    visitor_kind TEXT NOT NULL,
    request_kind TEXT NOT NULL,
    campaign_name TEXT NOT NULL,
    campaign_source TEXT NOT NULL,
    campaign_medium TEXT NOT NULL,
    campaign_content TEXT NOT NULL,
    clicks INTEGER NOT NULL DEFAULT 0 CHECK (clicks >= 0),
    PRIMARY KEY (
        link_id, accessed_on, country_code, device_type, browser, operating_system,
        referrer_domain, visitor_kind, request_kind, campaign_name, campaign_source,
        campaign_medium, campaign_content
    ),
    FOREIGN KEY (link_id) REFERENCES links(id) ON DELETE CASCADE
) WITHOUT ROWID;

CREATE INDEX analytics_daily_dimensions_date_idx
    ON analytics_daily_dimensions (accessed_on);

CREATE INDEX analytics_daily_dimensions_link_date_idx
    ON analytics_daily_dimensions (link_id, accessed_on);

CREATE TABLE analytics_rollup_state (
    id INTEGER PRIMARY KEY CHECK (id = 1),
    status TEXT NOT NULL CHECK (status IN ('pending', 'running', 'ready', 'failed')),
    checkpoint_date TEXT DEFAULT NULL,
    last_error TEXT DEFAULT NULL CHECK (last_error IS NULL OR length(last_error) <= 300),
    updated_at TEXT NOT NULL,
    completed_at TEXT DEFAULT NULL
);

INSERT INTO analytics_rollup_state (id, status, updated_at)
VALUES (1, 'pending', strftime('%Y-%m-%dT%H:%M:%SZ', 'now'));
