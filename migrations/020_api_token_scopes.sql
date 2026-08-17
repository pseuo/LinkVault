ALTER TABLE api_tokens
ADD COLUMN scopes TEXT NOT NULL DEFAULT 'links:create'
CHECK (length(scopes) BETWEEN 1 AND 255);
