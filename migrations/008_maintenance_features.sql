ALTER TABLE links ADD COLUMN one_time_mode TEXT NOT NULL DEFAULT 'immediate'
    CHECK (one_time_mode IN ('immediate', 'confirm'));

CREATE TABLE saved_filters (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL COLLATE NOCASE UNIQUE CHECK (length(name) BETWEEN 1 AND 60),
    view TEXT NOT NULL CHECK (view IN ('active', 'trash')),
    search TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'all'
        CHECK (status IN ('all', 'active', 'inactive', 'scheduled', 'expired', 'exhausted')),
    sort TEXT NOT NULL DEFAULT 'created_desc'
        CHECK (sort IN ('created_desc', 'created_asc', 'clicks_desc', 'clicks_asc', 'last_accessed_desc', 'title_asc')),
    tag TEXT NOT NULL DEFAULT '',
    favorites_only INTEGER NOT NULL DEFAULT 0 CHECK (favorites_only IN (0, 1)),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE link_daily_stats_archive (
    link_id INTEGER NOT NULL,
    slug TEXT NOT NULL,
    title TEXT NOT NULL DEFAULT '',
    accessed_on TEXT NOT NULL,
    clicks INTEGER NOT NULL CHECK (clicks >= 0),
    archived_at TEXT NOT NULL,
    PRIMARY KEY (link_id, accessed_on)
) WITHOUT ROWID;

CREATE INDEX link_daily_stats_archive_date_idx
    ON link_daily_stats_archive (accessed_on);
