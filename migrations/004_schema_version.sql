DROP TABLE IF EXISTS link_daily_stats_v4;

CREATE TABLE link_daily_stats_v4 (
    link_id INTEGER NOT NULL,
    accessed_on TEXT NOT NULL,
    clicks INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (link_id, accessed_on),
    FOREIGN KEY (link_id) REFERENCES links(id) ON DELETE CASCADE
);

INSERT INTO link_daily_stats_v4 (link_id, accessed_on, clicks)
SELECT stats.link_id, stats.accessed_on, stats.clicks
FROM link_daily_stats AS stats
INNER JOIN links ON links.id = stats.link_id;

DROP TABLE link_daily_stats;
ALTER TABLE link_daily_stats_v4 RENAME TO link_daily_stats;

CREATE INDEX link_daily_stats_date_idx ON link_daily_stats (accessed_on DESC);
