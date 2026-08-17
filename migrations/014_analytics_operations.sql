CREATE TABLE visitor_daily_stats (
    link_id INTEGER NOT NULL,
    accessed_on TEXT NOT NULL,
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
        link_id, accessed_on, country_code, device_type, browser, operating_system,
        referrer_domain, visitor_kind, request_kind, campaign_name, campaign_source,
        campaign_medium, campaign_content
    ),
    FOREIGN KEY (link_id) REFERENCES links(id) ON DELETE CASCADE
) WITHOUT ROWID;

CREATE INDEX visitor_daily_stats_date_idx
    ON visitor_daily_stats (accessed_on);

CREATE INDEX visitor_daily_stats_link_date_idx
    ON visitor_daily_stats (link_id, accessed_on);

CREATE TABLE analytics_alert_state (
    anomaly_type TEXT PRIMARY KEY,
    is_active INTEGER NOT NULL DEFAULT 0 CHECK (is_active IN (0, 1)),
    last_notified_at TEXT DEFAULT NULL,
    last_value TEXT NOT NULL DEFAULT '',
    updated_at TEXT NOT NULL
) WITHOUT ROWID;
