ALTER TABLE links ADD COLUMN is_favorite INTEGER NOT NULL DEFAULT 0 CHECK (is_favorite IN (0, 1));
ALTER TABLE links ADD COLUMN starts_at TEXT DEFAULT NULL;
ALTER TABLE links ADD COLUMN max_clicks INTEGER DEFAULT NULL CHECK (max_clicks IS NULL OR max_clicks > 0);
ALTER TABLE links ADD COLUMN is_one_time INTEGER NOT NULL DEFAULT 0 CHECK (is_one_time IN (0, 1));

CREATE TABLE link_tags (
    link_id INTEGER NOT NULL,
    tag TEXT NOT NULL,
    PRIMARY KEY (link_id, tag),
    FOREIGN KEY (link_id) REFERENCES links(id) ON DELETE CASCADE
);

CREATE TABLE link_status_history (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    link_id INTEGER NOT NULL,
    event TEXT NOT NULL,
    from_status TEXT DEFAULT NULL,
    to_status TEXT NOT NULL,
    created_at TEXT NOT NULL,
    FOREIGN KEY (link_id) REFERENCES links(id) ON DELETE CASCADE
);

INSERT INTO link_status_history (link_id, event, from_status, to_status, created_at)
SELECT id,
       'created',
       NULL,
       CASE WHEN deleted_at IS NOT NULL THEN 'deleted'
            WHEN is_active = 0 THEN 'inactive'
            ELSE 'active' END,
       created_at
FROM links;

CREATE INDEX links_target_url_idx ON links (target_url);
CREATE INDEX links_favorite_id_idx ON links (deleted_at, is_favorite, id DESC);
CREATE INDEX link_tags_tag_idx ON link_tags (tag, link_id);
CREATE INDEX link_status_history_link_date_idx ON link_status_history (link_id, created_at DESC);
