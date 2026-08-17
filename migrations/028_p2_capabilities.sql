CREATE TABLE tag_rules (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL COLLATE NOCASE UNIQUE CHECK (length(name) BETWEEN 1 AND 60),
    field TEXT NOT NULL CHECK (field IN ('url', 'host', 'path', 'title')),
    operator TEXT NOT NULL CHECK (operator IN ('contains', 'prefix', 'suffix', 'equals', 'regex')),
    pattern TEXT NOT NULL CHECK (length(pattern) BETWEEN 1 AND 500),
    tags_json TEXT NOT NULL CHECK (length(tags_json) BETWEEN 2 AND 1024),
    priority INTEGER NOT NULL DEFAULT 100 CHECK (priority BETWEEN 0 AND 10000),
    is_enabled INTEGER NOT NULL DEFAULT 1 CHECK (is_enabled IN (0, 1)),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE INDEX tag_rules_enabled_priority_idx ON tag_rules (is_enabled, priority, id);

CREATE TABLE funnels (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL COLLATE NOCASE UNIQUE CHECK (length(name) BETWEEN 1 AND 80),
    stages_json TEXT NOT NULL CHECK (length(stages_json) BETWEEN 3 AND 2048),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE conversion_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    event_id TEXT NOT NULL UNIQUE CHECK (length(event_id) BETWEEN 8 AND 128),
    token_id INTEGER NOT NULL,
    link_id INTEGER NOT NULL,
    event_name TEXT NOT NULL CHECK (length(event_name) BETWEEN 1 AND 80),
    occurred_at TEXT NOT NULL,
    value_minor INTEGER DEFAULT NULL CHECK (value_minor IS NULL OR value_minor BETWEEN 0 AND 9223372036854775807),
    currency TEXT DEFAULT NULL CHECK (currency IS NULL OR length(currency) = 3),
    metadata_json TEXT NOT NULL DEFAULT '{}' CHECK (length(metadata_json) <= 8192),
    idempotency_key_hash TEXT NOT NULL,
    payload_hash TEXT NOT NULL,
    created_at TEXT NOT NULL,
    UNIQUE (token_id, idempotency_key_hash),
    FOREIGN KEY (token_id) REFERENCES api_tokens(id) ON DELETE RESTRICT,
    FOREIGN KEY (link_id) REFERENCES links(id) ON DELETE CASCADE
);

CREATE INDEX conversion_events_link_time_idx ON conversion_events (link_id, occurred_at, id);
CREATE INDEX conversion_events_name_time_idx ON conversion_events (event_name, occurred_at, id);

CREATE TABLE abuse_reports (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    public_id TEXT NOT NULL UNIQUE CHECK (length(public_id) = 24),
    link_id INTEGER DEFAULT NULL,
    reported_url TEXT NOT NULL CHECK (length(reported_url) BETWEEN 1 AND 2048),
    reason TEXT NOT NULL CHECK (reason IN ('phishing', 'malware', 'spam', 'fraud', 'other')),
    details TEXT NOT NULL DEFAULT '' CHECK (length(details) <= 1000),
    reporter_contact TEXT NOT NULL DEFAULT '' CHECK (length(reporter_contact) <= 254),
    reporter_hash TEXT NOT NULL CHECK (length(reporter_hash) = 64),
    status TEXT NOT NULL DEFAULT 'open' CHECK (status IN ('open', 'reviewing', 'resolved', 'rejected')),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    resolved_at TEXT DEFAULT NULL,
    FOREIGN KEY (link_id) REFERENCES links(id) ON DELETE SET NULL
);

CREATE INDEX abuse_reports_status_created_idx ON abuse_reports (status, created_at, id);
CREATE INDEX abuse_reports_link_created_idx ON abuse_reports (link_id, created_at, id);

CREATE TABLE domain_blacklist (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    hostname TEXT NOT NULL COLLATE NOCASE UNIQUE CHECK (length(hostname) BETWEEN 1 AND 253),
    include_subdomains INTEGER NOT NULL DEFAULT 1 CHECK (include_subdomains IN (0, 1)),
    reason TEXT NOT NULL CHECK (length(reason) BETWEEN 1 AND 300),
    source TEXT NOT NULL DEFAULT 'manual' CHECK (length(source) BETWEEN 1 AND 80),
    is_enabled INTEGER NOT NULL DEFAULT 1 CHECK (is_enabled IN (0, 1)),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE INDEX domain_blacklist_enabled_host_idx ON domain_blacklist (is_enabled, hostname);

CREATE TABLE link_risk_scans (
    link_id INTEGER PRIMARY KEY,
    target_url_hash TEXT NOT NULL CHECK (length(target_url_hash) = 64),
    risk_level TEXT NOT NULL CHECK (risk_level IN ('low', 'medium', 'high', 'critical')),
    score INTEGER NOT NULL CHECK (score BETWEEN 0 AND 100),
    reasons_json TEXT NOT NULL CHECK (length(reasons_json) BETWEEN 2 AND 4096),
    scanned_at TEXT NOT NULL,
    FOREIGN KEY (link_id) REFERENCES links(id) ON DELETE CASCADE
);

CREATE INDEX link_risk_scans_level_idx ON link_risk_scans (risk_level, score, scanned_at);

CREATE TABLE abuse_actions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    report_id INTEGER DEFAULT NULL,
    link_id INTEGER DEFAULT NULL,
    action TEXT NOT NULL CHECK (action IN ('review', 'disable_link', 'enable_link', 'blacklist_domain', 'dismiss', 'note')),
    note TEXT NOT NULL DEFAULT '' CHECK (length(note) <= 1000),
    actor_type TEXT NOT NULL DEFAULT 'admin' CHECK (actor_type IN ('admin', 'system')),
    created_at TEXT NOT NULL,
    FOREIGN KEY (report_id) REFERENCES abuse_reports(id) ON DELETE SET NULL,
    FOREIGN KEY (link_id) REFERENCES links(id) ON DELETE SET NULL
);

CREATE INDEX abuse_actions_report_created_idx ON abuse_actions (report_id, created_at, id);
