CREATE VIRTUAL TABLE links_fts USING fts5(
    title,
    slug,
    target_url,
    content = 'links',
    content_rowid = 'id',
    tokenize = 'unicode61'
);

CREATE TRIGGER links_fts_insert AFTER INSERT ON links BEGIN
    INSERT INTO links_fts(rowid, title, slug, target_url)
    VALUES (new.id, new.title, new.slug, new.target_url);
END;

CREATE TRIGGER links_fts_delete AFTER DELETE ON links BEGIN
    INSERT INTO links_fts(links_fts, rowid, title, slug, target_url)
    VALUES ('delete', old.id, old.title, old.slug, old.target_url);
END;

CREATE TRIGGER links_fts_update AFTER UPDATE OF title, slug, target_url ON links BEGIN
    INSERT INTO links_fts(links_fts, rowid, title, slug, target_url)
    VALUES ('delete', old.id, old.title, old.slug, old.target_url);
    INSERT INTO links_fts(rowid, title, slug, target_url)
    VALUES (new.id, new.title, new.slug, new.target_url);
END;

INSERT INTO links_fts(links_fts) VALUES ('rebuild');
