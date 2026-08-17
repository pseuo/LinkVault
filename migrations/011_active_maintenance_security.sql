ALTER TABLE api_tokens ADD COLUMN rotation_expires_at TEXT DEFAULT NULL;

CREATE INDEX api_tokens_rotation_expiry_idx
    ON api_tokens (rotation_expires_at);

CREATE TABLE admin_security (
    id INTEGER PRIMARY KEY CHECK (id = 1),
    totp_secret_encrypted TEXT NOT NULL,
    totp_enabled_at TEXT NOT NULL,
    totp_last_counter INTEGER NOT NULL DEFAULT -1 CHECK (totp_last_counter >= -1),
    updated_at TEXT NOT NULL
);

CREATE TABLE admin_recovery_codes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code_hash TEXT NOT NULL UNIQUE CHECK (length(code_hash) = 64),
    created_at TEXT NOT NULL,
    used_at TEXT DEFAULT NULL
);

CREATE INDEX admin_recovery_codes_used_idx
    ON admin_recovery_codes (used_at, id);
