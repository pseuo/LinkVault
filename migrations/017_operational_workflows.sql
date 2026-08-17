CREATE TABLE bulk_operations (
    id TEXT PRIMARY KEY CHECK (length(id) = 32),
    action TEXT NOT NULL CHECK (action IN (
        'favorite', 'unfavorite', 'enable', 'disable', 'delete', 'restore', 'purge',
        'extend', 'add_tags', 'remove_tags'
    )),
    parameters_json TEXT NOT NULL,
    items_json TEXT NOT NULL,
    status TEXT NOT NULL CHECK (status IN ('previewed', 'applied', 'undone', 'conflicted', 'expired')),
    reversible INTEGER NOT NULL CHECK (reversible IN (0, 1)),
    selected_count INTEGER NOT NULL CHECK (selected_count >= 0),
    eligible_count INTEGER NOT NULL CHECK (eligible_count >= 0),
    changed_count INTEGER NOT NULL DEFAULT 0 CHECK (changed_count >= 0),
    result_json TEXT DEFAULT NULL,
    created_at INTEGER NOT NULL,
    preview_expires_at INTEGER NOT NULL,
    applied_at INTEGER DEFAULT NULL,
    undo_expires_at INTEGER DEFAULT NULL,
    undone_at INTEGER DEFAULT NULL,
    retain_until INTEGER NOT NULL
);

CREATE INDEX bulk_operations_retain_idx ON bulk_operations (retain_until);
CREATE INDEX bulk_operations_status_created_idx ON bulk_operations (status, created_at);

CREATE TABLE saved_analytics_views (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL COLLATE NOCASE UNIQUE CHECK (length(name) BETWEEN 1 AND 60),
    request_json TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
