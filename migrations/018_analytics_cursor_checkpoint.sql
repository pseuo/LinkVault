ALTER TABLE analytics_ingest_state
ADD COLUMN checkpoint_hash TEXT NOT NULL DEFAULT ''
    CHECK (length(checkpoint_hash) IN (0, 64));
