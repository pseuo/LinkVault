CREATE TABLE short_domains (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    hostname TEXT NOT NULL UNIQUE CHECK (length(hostname) BETWEEN 4 AND 253),
    verification_token TEXT NOT NULL UNIQUE CHECK (length(verification_token) = 48),
    verified_at TEXT DEFAULT NULL,
    is_enabled INTEGER NOT NULL DEFAULT 0 CHECK (is_enabled IN (0, 1)),
    brand_name TEXT NOT NULL DEFAULT '链匣 LinkVault' CHECK (length(brand_name) BETWEEN 1 AND 60),
    brand_tagline TEXT NOT NULL DEFAULT '你的链接，收放自如。' CHECK (length(brand_tagline) <= 160),
    brand_theme TEXT NOT NULL DEFAULT 'graphite' CHECK (brand_theme IN ('graphite', 'indigo', 'emerald', 'crimson')),
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE INDEX short_domains_state_idx ON short_domains (is_enabled, verified_at, hostname);

ALTER TABLE links
ADD COLUMN short_domain_id INTEGER DEFAULT NULL REFERENCES short_domains(id) ON DELETE SET NULL;

CREATE INDEX links_short_domain_id_idx ON links (short_domain_id, id);

CREATE TABLE webhook_outbox (
    event_id TEXT PRIMARY KEY CHECK (length(event_id) = 32),
    event_type TEXT NOT NULL CHECK (event_type IN (
        'link.created', 'link.expiring', 'link.disabled', 'link.target_unhealthy'
    )),
    link_id INTEGER DEFAULT NULL,
    dedupe_key TEXT NOT NULL UNIQUE CHECK (length(dedupe_key) BETWEEN 1 AND 255),
    payload_json TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'delivered', 'dead')),
    attempts INTEGER NOT NULL DEFAULT 0 CHECK (attempts >= 0),
    available_at TEXT NOT NULL,
    leased_until TEXT DEFAULT NULL,
    last_error TEXT DEFAULT NULL,
    created_at TEXT NOT NULL,
    delivered_at TEXT DEFAULT NULL,
    FOREIGN KEY (link_id) REFERENCES links(id) ON DELETE SET NULL
);

CREATE INDEX webhook_outbox_delivery_idx
    ON webhook_outbox (status, available_at, leased_until, created_at);
