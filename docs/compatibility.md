# Version Compatibility Policy

## Application Releases

LinkVault uses Semantic Versioning and annotated `vX.Y.Z` tags. Patch releases preserve API, import/export, database, and deployment compatibility. Minor releases may add backward-compatible behavior. Major releases may remove or change a published contract only with a migration and a documented support window.

## Database

- Migrations are ordered, append-only, and executed by `php bin/migrate.php`; web requests never migrate schema.
- A release must support the immediately preceding production schema during rolling deployment, unless an approved maintenance window prevents mixed versions.
- Destructive schema or data changes require an ADR, tested restore, explicit rollback path, and a release-owner approval. The migration is not removed or edited after publication.

## External Contracts

- `/api/*`, OpenAPI, error codes, import formats v1-v3, signed webhook payloads, and Prometheus metric names are published contracts.
- Additive fields and metrics are allowed in minor releases. Removing or changing meaning requires a major release or a documented deprecation period of at least two minor releases and 90 days, whichever is longer.
- Legacy environment variables remain supported only when stated in the release notes. Replacement variables must be documented with an end date.

## Deployment Artifacts

The release ZIP is immutable and must match its tag. PHP 8.5, SQLite with FTS5, and the required extensions in `README.md` are the supported runtime baseline. Caddy/Nginx configuration is versioned with the application and must pass `bin/check-deployment-domains.php` before reload.
