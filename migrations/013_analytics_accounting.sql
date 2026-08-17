ALTER TABLE visitor_hourly_stats RENAME TO visitor_hourly_stats_v12;

DROP INDEX visitor_hourly_stats_hour_idx;
DROP INDEX visitor_hourly_stats_link_hour_idx;

CREATE TABLE visitor_hourly_stats (
    link_id INTEGER NOT NULL,
    accessed_hour TEXT NOT NULL,
    country_code TEXT NOT NULL DEFAULT 'ZZ' CHECK (length(country_code) BETWEEN 2 AND 8),
    device_type TEXT NOT NULL CHECK (device_type IN ('desktop', 'mobile', 'tablet', 'other')),
    browser TEXT NOT NULL CHECK (length(browser) BETWEEN 1 AND 40),
    operating_system TEXT NOT NULL CHECK (length(operating_system) BETWEEN 1 AND 40),
    referrer_domain TEXT NOT NULL CHECK (length(referrer_domain) BETWEEN 1 AND 180),
    visitor_kind TEXT NOT NULL
        CHECK (visitor_kind IN ('suspected_human', 'bot', 'scanner', 'unknown')),
    request_kind TEXT NOT NULL
        CHECK (request_kind IN ('redirect_get', 'redirect_head', 'confirmation_post', 'legacy_unknown')),
    campaign_name TEXT NOT NULL DEFAULT '' CHECK (length(campaign_name) <= 100),
    campaign_source TEXT NOT NULL DEFAULT '' CHECK (length(campaign_source) <= 100),
    campaign_medium TEXT NOT NULL DEFAULT '' CHECK (length(campaign_medium) <= 100),
    campaign_content TEXT NOT NULL DEFAULT '' CHECK (length(campaign_content) <= 100),
    clicks INTEGER NOT NULL DEFAULT 0 CHECK (clicks >= 0),
    PRIMARY KEY (
        link_id, accessed_hour, country_code, device_type, browser, operating_system,
        referrer_domain, visitor_kind, request_kind, campaign_name, campaign_source,
        campaign_medium, campaign_content
    ),
    FOREIGN KEY (link_id) REFERENCES links(id) ON DELETE CASCADE
) WITHOUT ROWID;

INSERT INTO visitor_hourly_stats (
    link_id, accessed_hour, country_code, device_type, browser, operating_system,
    referrer_domain, visitor_kind, request_kind, campaign_name, campaign_source,
    campaign_medium, campaign_content, clicks
)
SELECT link_id, accessed_hour, country_code, device_type, browser, operating_system,
       referrer_domain,
       CASE WHEN visitor_kind = 'human' THEN 'suspected_human' ELSE visitor_kind END,
       'legacy_unknown', campaign_name, campaign_source, campaign_medium, campaign_content, clicks
FROM visitor_hourly_stats_v12;

DROP TABLE visitor_hourly_stats_v12;

CREATE INDEX visitor_hourly_stats_hour_idx
    ON visitor_hourly_stats (accessed_hour);

CREATE INDEX visitor_hourly_stats_link_hour_idx
    ON visitor_hourly_stats (link_id, accessed_hour);

CREATE TABLE analytics_ingest_state (
    source_path TEXT PRIMARY KEY,
    inode TEXT NOT NULL,
    byte_offset INTEGER NOT NULL CHECK (byte_offset >= 0),
    updated_at TEXT NOT NULL
) WITHOUT ROWID;

CREATE TABLE link_campaign_snapshots (
    link_id INTEGER NOT NULL,
    effective_at_ms INTEGER NOT NULL CHECK (effective_at_ms >= 0),
    campaign_name TEXT NOT NULL DEFAULT '' CHECK (length(campaign_name) <= 100),
    campaign_source TEXT NOT NULL DEFAULT '' CHECK (length(campaign_source) <= 100),
    campaign_medium TEXT NOT NULL DEFAULT '' CHECK (length(campaign_medium) <= 100),
    campaign_content TEXT NOT NULL DEFAULT '' CHECK (length(campaign_content) <= 100),
    PRIMARY KEY (link_id, effective_at_ms),
    FOREIGN KEY (link_id) REFERENCES links(id) ON DELETE CASCADE
) WITHOUT ROWID;

INSERT INTO link_campaign_snapshots (
    link_id, effective_at_ms, campaign_name, campaign_source, campaign_medium, campaign_content
)
SELECT id,
       COALESCE(
           CAST(strftime('%s', created_at) AS INTEGER) * 1000,
           CAST((julianday('now') - 2440587.5) * 86400000 AS INTEGER)
       ),
       campaign_name, campaign_source, campaign_medium, campaign_content
FROM links;

CREATE TRIGGER link_campaign_snapshot_insert
AFTER INSERT ON links
BEGIN
    INSERT INTO link_campaign_snapshots (
        link_id, effective_at_ms, campaign_name, campaign_source, campaign_medium, campaign_content
    ) VALUES (
        NEW.id,
        COALESCE(
            CAST(strftime('%s', NEW.created_at) AS INTEGER) * 1000,
            CAST((julianday('now') - 2440587.5) * 86400000 AS INTEGER)
        ),
        NEW.campaign_name, NEW.campaign_source, NEW.campaign_medium, NEW.campaign_content
    )
    ON CONFLICT(link_id, effective_at_ms) DO UPDATE SET
        campaign_name = excluded.campaign_name,
        campaign_source = excluded.campaign_source,
        campaign_medium = excluded.campaign_medium,
        campaign_content = excluded.campaign_content;
END;

CREATE TRIGGER link_campaign_snapshot_update
AFTER UPDATE OF campaign_name, campaign_source, campaign_medium, campaign_content ON links
WHEN OLD.campaign_name IS NOT NEW.campaign_name
  OR OLD.campaign_source IS NOT NEW.campaign_source
  OR OLD.campaign_medium IS NOT NEW.campaign_medium
  OR OLD.campaign_content IS NOT NEW.campaign_content
BEGIN
    INSERT INTO link_campaign_snapshots (
        link_id, effective_at_ms, campaign_name, campaign_source, campaign_medium, campaign_content
    ) VALUES (
        NEW.id,
        CAST((julianday('now') - 2440587.5) * 86400000 AS INTEGER),
        NEW.campaign_name, NEW.campaign_source, NEW.campaign_medium, NEW.campaign_content
    )
    ON CONFLICT(link_id, effective_at_ms) DO UPDATE SET
        campaign_name = excluded.campaign_name,
        campaign_source = excluded.campaign_source,
        campaign_medium = excluded.campaign_medium,
        campaign_content = excluded.campaign_content;
END;
