CREATE INDEX IF NOT EXISTS links_state_id_idx ON links (deleted_at, id DESC);
