CREATE TABLE target_health (
    link_id INTEGER PRIMARY KEY,
    target_url_hash TEXT NOT NULL CHECK (length(target_url_hash) = 64),
    state TEXT NOT NULL CHECK (state IN ('healthy', 'unhealthy', 'anomaly', 'error')),
    reason TEXT NOT NULL CHECK (length(reason) BETWEEN 1 AND 64),
    checked_at TEXT NOT NULL,
    next_check_at TEXT NOT NULL,
    last_healthy_at TEXT DEFAULT NULL,
    http_status INTEGER DEFAULT NULL CHECK (http_status IS NULL OR http_status BETWEEN 100 AND 599),
    latency_ms INTEGER DEFAULT NULL CHECK (latency_ms IS NULL OR latency_ms >= 0),
    effective_url TEXT DEFAULT NULL CHECK (effective_url IS NULL OR length(effective_url) <= 2048),
    redirect_count INTEGER NOT NULL DEFAULT 0 CHECK (redirect_count >= 0),
    redirect_state TEXT NOT NULL DEFAULT 'none' CHECK (length(redirect_state) BETWEEN 1 AND 32),
    consecutive_failures INTEGER NOT NULL DEFAULT 0 CHECK (consecutive_failures >= 0),
    FOREIGN KEY (link_id) REFERENCES links(id) ON DELETE CASCADE
);

CREATE INDEX target_health_due_idx
    ON target_health (next_check_at, link_id);

CREATE INDEX target_health_state_checked_idx
    ON target_health (state, checked_at, link_id);
