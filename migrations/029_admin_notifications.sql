CREATE TABLE admin_notifications (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    notification_key TEXT NOT NULL UNIQUE,
    notification_type TEXT NOT NULL CHECK (length(notification_type) BETWEEN 1 AND 40),
    severity TEXT NOT NULL CHECK (severity IN ('info', 'attention', 'error')),
    title TEXT NOT NULL CHECK (length(title) BETWEEN 1 AND 160),
    body TEXT NOT NULL DEFAULT '' CHECK (length(body) <= 1000),
    entity_type TEXT DEFAULT NULL CHECK (entity_type IS NULL OR length(entity_type) <= 40),
    entity_id TEXT DEFAULT NULL CHECK (entity_id IS NULL OR length(entity_id) <= 128),
    action_url TEXT DEFAULT NULL CHECK (action_url IS NULL OR length(action_url) <= 500),
    created_at TEXT NOT NULL,
    read_at TEXT DEFAULT NULL,
    dismissed_at TEXT DEFAULT NULL
);

CREATE INDEX admin_notifications_inbox_idx
    ON admin_notifications (dismissed_at, read_at, created_at, id);
