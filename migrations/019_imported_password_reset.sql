ALTER TABLE links ADD COLUMN access_password_reset_required INTEGER NOT NULL DEFAULT 0
    CHECK (access_password_reset_required IN (0, 1));

CREATE TRIGGER links_password_reset_insert_guard
BEFORE INSERT ON links
WHEN NEW.access_password_reset_required = 1 AND NEW.is_active = 1
BEGIN
    SELECT RAISE(ABORT, 'password reset required before activation');
END;

CREATE TRIGGER links_password_reset_activation_guard
BEFORE UPDATE OF is_active, access_password_reset_required ON links
WHEN NEW.access_password_reset_required = 1 AND NEW.is_active = 1
BEGIN
    SELECT RAISE(ABORT, 'password reset required before activation');
END;
