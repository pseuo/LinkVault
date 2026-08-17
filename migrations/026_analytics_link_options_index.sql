CREATE INDEX links_analytics_options_idx
    ON links (deleted_at, campaign_name COLLATE NOCASE, id DESC);
