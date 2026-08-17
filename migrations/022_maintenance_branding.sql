ALTER TABLE target_health
ADD COLUMN redirect_chain_json TEXT NOT NULL DEFAULT '[]' CHECK (json_valid(redirect_chain_json));

ALTER TABLE short_domains
ADD COLUMN brand_color TEXT NOT NULL DEFAULT '#18181b' CHECK (brand_color GLOB '#[0-9A-Fa-f][0-9A-Fa-f][0-9A-Fa-f][0-9A-Fa-f][0-9A-Fa-f][0-9A-Fa-f]');

ALTER TABLE short_domains
ADD COLUMN logo_url TEXT NOT NULL DEFAULT '' CHECK (length(logo_url) <= 2048);

ALTER TABLE short_domains
ADD COLUMN favicon_url TEXT NOT NULL DEFAULT '' CHECK (length(favicon_url) <= 2048);

ALTER TABLE short_domains
ADD COLUMN invalid_page_title TEXT NOT NULL DEFAULT '链接不可用' CHECK (length(invalid_page_title) BETWEEN 1 AND 80);

ALTER TABLE short_domains
ADD COLUMN invalid_page_message TEXT NOT NULL DEFAULT '此链接已失效或不存在。' CHECK (length(invalid_page_message) BETWEEN 1 AND 500);
