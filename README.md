# LinkVault

Language: [English](README.md) | [Chinese](README.zh-CN.md)

Your links, in your control.

## License

This project uses the [Non-Commercial Source License](LICENSE): use and modification are permitted for personal, educational, research, and other non-commercial purposes; commercial use, sale, and paid distribution are prohibited. Any commercial use requires the prior written consent of the copyright holder, and all copies and modified versions must retain the copyright and license notices.

LinkVault is a self-use short-link service written for PHP 8.5. It requires no Composer and uses SQLite to store data.

## Requirements

- PHP 8.5
- The `pdo_sqlite` and `sqlite3` extensions enabled; the SQLite build must include FTS5. The `curl` extension is also required when periodic target health checks are enabled.
- Backup and recovery operations require the `sqlite3` CLI to be callable from `PATH`; production off-site backups also require `age` and `rclone`.

## Features

- Admin password login
- Random or custom short-code generation
- Short-link redirection
- Redirect click counting
- Filtering by tags, favorites, and status, plus sorting and bulk operations
- Per-link 7/14/30-day trends, creation/last-visited times, and status-change history
- Duplicate destination warnings and reuse confirmation
- QR codes, system sharing, and one-click copying
- Editing, disabling/enabling, scheduled enabling, quick expiration, click limits, and one-time links; one-time links can be consumed after visit confirmation
- Optional link access passwords: hashed storage, independent rate limiting, failure auditing, and one-time unlock sessions
- Custom explanations or fallback redirects after a link is disabled, expires, or exhausts its click limit
- Search by title, short code, tag, or destination URL, with pagination above 100 results
- Recycle bin, restore, and permanent deletion
- JSON import Dry Run, error preview, merge modes that skip/overwrite/generate a new short code on short-code conflicts, field-difference preview, and export of all/current-view/selected links plus irreversible audit-data snapshots
- Bookmarklet and Bearer Token API with `links:create`, `links:read`, `links:write`, and `links:delete` scopes; Token rotation, expiration, and usage records are supported
- Redirect statistics aggregated by UTC calendar day and current view
- 14-day consecutive trends, popular links, status distribution, and zero-click link statistics
- Anonymous audience profiles: browser-local-timezone date ranges, previous-period comparison, and linked filtering by links, tags, campaigns, sources, media, devices, regions, and traffic types
- Clickable trend and profile drill-downs; rankings for fastest growth, largest decline, highest bot share, first traffic, and long-term no-traffic
- CSV exports for trends, sources, devices, regions, campaigns, and current filter results; campaign fields are attributed from the snapshot at the time of the visit
- Link maintenance workspace: expiring soon, long-term zero-click, nearing click exhaustion, invalid, and target-health-anomalous links
- Saved common filters, plus daily Webhook notifications for expiring links, quota thresholds, long-term zero-click links, and backup anomalies
- Business-summary subscriptions for completed calendar weeks or months: new links, click trends, automated anomaly sources, invalid links, popular links, target health, and backup status can be delivered by email or Webhook
- Signed lifecycle Webhooks for link creation, impending invalidation, disabling, and target anomalies; events are delivered through a transactional outbox with retries
- Multiple short-link domains, DNS TXT ownership verification, domain enable/disable, and per-domain brand name, tagline, and fixed-theme configuration
- Global operation auditing and a status center covering release version, synthetic monitoring, database, Schema, disk, backups, API, and recovery drills
- Structured before/after auditing for key fields; cumulative clicks are retained permanently, while daily statistics can be archived or deleted according to policy
- API `Idempotency-Key`, OpenAPI 3.1 documentation, and unified error codes
- Chromium browser extension: automatic tag suggestions, one-click save with `Ctrl/Cmd + Shift + L`, context-menu saving, quick search, advanced creation fields, offline queue, and link-health notices; plus `Ctrl/Cmd + K` quick commands, duplicate-link merging, and bulk tag rules on the admin page
- Conversion-event API with the `conversions:write` scope, timestamp HMAC signatures, and mandatory idempotency checks, plus funnel conversion rates
- Public malicious-link reports, independent report quotas, domain blacklists, risk scanning, and an auditable abuse-handling process
- Optional TOTP admin second factor and single-use offline recovery codes
- Regular automated recovery drills in an isolated database
- Regular target health checks with full DNS validation, IP pinning, and manual redirect handling
- Local SQLite storage
- Layered health checks at `/livez`, `/readyz`, and `/healthz`

## Local Development

```powershell
$securePassword = Read-Host "Enter admin password" -AsSecureString
$env:LINKVAULT_ADMIN_PASSWORD = [System.Net.NetworkCredential]::new("", $securePassword).Password
php bin/doctor.php
php bin/migrate.php
php bin/build-assets.php
php -S 127.0.0.1:8080 -t public public/router.php
```

To view access analytics locally, start the aggregation loop in another PowerShell window:

```powershell
while ($true) { php bin/aggregate-analytics.php; Start-Sleep -Seconds 300 }
```

The local router writes only short-link GET/HEAD requests and confirmation POST requests to the anonymous analytics log. It does not record IP addresses, query strings, Cookies, or complete referrer URLs. In production, Nginx/Caddy still collects the data and the system timer aggregates it.

`bin/doctor.php` is a read-only installation check: it centrally displays the PHP version and extensions, SQLite FTS5, migrations, directory permissions, public HTTPS, backup tools, and Linux systemd timer status, and outputs the next command based on current failures. It exits with code `1` when blocking items exist; local development may continue when there are only advisory items.

Open the home page in a browser at `http://127.0.0.1:8080`; the admin login is at `http://127.0.0.1:8080/login`.

The application has no default password. Without a configured strong password, the service returns `503` and will not show the login page or process short-link redirects.

## Quick-Create API

After login, the “System Status” page can create a Token with a name, scopes, optional expiration time, an independent window quota, and a CIDR allowlist; the plaintext value is shown only once, and the database stores only its SHA-256 digest. The admin page supports immediate rotation or revocation and shows usage count, last-used time, recent authentication records, and alerts for CIDR rejection or quota exhaustion. A rotated Token inherits the original quota and CIDR policy. Existing Tokens have only `links:create` by default and do not automatically gain read or modification permissions through migration.

`GET /api/links` and `GET /api/links/{id}` require `links:read`; `PATCH /api/links/{id}` and `POST /api/links/{id}/disable` require `links:write`; `DELETE /api/links/{id}` requires `links:delete`. Modifications, disabling, and deletion must include the resource’s latest `If-Match` ETag; deletion moves the item to the recycle bin rather than permanently deleting it. The short-link domain field may select only a verified and enabled domain.

Existing deployments can still configure a separate compatibility Token of at least 24 characters through an environment variable:

```powershell
$env:LINKVAULT_API_TOKEN = "replace-with-a-long-random-token"
```

```bash
curl -X POST https://s.example.com/api/shorten \
  -H 'Authorization: Bearer replace-with-a-long-random-token' \
  -H 'Idempotency-Key: 6f45b837-0277-4aa0-92dd-dfe88a2f96ee' \
  -H 'Content-Type: application/json' \
  -d '{"url":"https://example.com/long/path","title":"Documentation","tags":["Work","Documentation"]}'
```

The request body limit is 64 KiB, and `application/json` is required. Optional fields are the string fields `slug`, `expires_at`, `starts_at`, `one_time_mode`, `campaign_name`, `source`, `medium`, and `content`; the integer field `max_clicks`; and the Boolean fields `one_time`, `favorite`, and `force`; `tags` may be a string or an array of strings. Campaign fields automatically write the corresponding `utm_*` parameters to the destination URL. `one_time_mode` supports `immediate` (consume on first visit) and `confirm` (consume after visit confirmation), and applies only when `one_time=true`. By default, a duplicate destination URL returns the existing short link; creation continues only when the JSON Boolean `force=true` is supplied. The “Quick Create” area of the admin page provides a copyable bookmarklet.

`Idempotency-Key` is optional and retained for 24 hours by default. The same key with the same normalized parameters returns the status code and response body saved by the first successful request and sets `Idempotency-Replayed: true`; using the same key with different parameters returns `409 idempotency_conflict`. The service stores only the key’s SHA-256 digest, not the original key. See the [OpenAPI 3.1](docs/openapi.json) contract and [API error codes](docs/api-errors.md).

When rotating a database Token, a parallel window for the old and new Tokens can be set to at most 24 hours, with a default of 15 minutes; the old Token automatically expires when the transition deadline is reached, and the new Token’s natural expiration is configured independently. Requests using expired or revoked Tokens return `401 invalid_token`, consume no business quota, and create no usage record. Environment-variable Tokens cannot be rotated from the page; after migrating to database Tokens, remove the old value from the process environment and restart the service.

## Admin Second Factor and Recovery

TOTP is optional. Before enabling it, configure an independently generated random `LINKVAULT_SECURITY_KEY` of at least 32 characters through the process environment, and ensure that the Web process and command-line tasks read the same value. Setup can begin on the System Status page; after the first one-time password is verified, 10 recovery codes are generated. The TOTP key is stored encrypted with AES-256-GCM, while recovery codes are stored only as SHA-256 digests.

This is a single-admin recovery path: the second-factor field on the login page accepts any unused recovery code, and that code is invalidated immediately after successful use. After recovery login, check the security key, set up TOTP again, or reset the recovery codes. Recovery codes are shown only when generated and should be stored offline separately from database backups and `LINKVAULT_SECURITY_KEY`; if the key is lost, one-time passwords cannot be decrypted, but a recovery code can still log in and disable the old TOTP.

## Change the Password

The password must be at least 12 characters and include at least three of lowercase letters, uppercase letters, digits, and special characters. Setting it only through an environment variable is recommended:

```powershell
$securePassword = Read-Host "Enter admin password" -AsSecureString
$env:LINKVAULT_ADMIN_PASSWORD = [System.Net.NetworkCredential]::new("", $securePassword).Password
php -S 127.0.0.1:8080 -t public public/router.php
```

Do not commit a real password to the repository or write it in public documentation.

## Server Deployment

1. Upload the project to the server.
2. Point the Web root at `public`.
3. Confirm that PHP has the `pdo_sqlite` and `sqlite3` extensions enabled; also enable `curl` when target health checks are enabled or any Webhook is configured.
4. Set `LINKVAULT_ADMIN_PASSWORD` through the process manager, container Secret, or system environment variables.
5. Run `php bin/build-assets.php` on the first deployment and after every upgrade to generate content-hashed CSS, JS, fonts, SVGs, and `public/assets/manifest.json`.
6. Run `php bin/migrate.php` on the first deployment and after every upgrade, before starting Web traffic. Web requests do not automatically create tables or alter the schema; the migration command is repeatable. A production server can use the systemd one-shot task below so migration and PHP-FPM read the same environment file.
7. Always set a fixed public address in production. Local development may omit it only when the request Host is `localhost`, `127.0.0.1`, or `::1`, and the directly connected peer address is also loopback:

```bash
LINKVAULT_BASE_URL=https://s.example.com
```

The application rejects a `Host` whose domain or port does not match that address. The Nginx example also rejects unknown Hosts and uses the fixed domain to generate HTTP-to-HTTPS redirects.

Add custom short-link domains in “System Status”. Configure the TXT value shown by the page for `_linkvault-challenge.<domain>`; the domain can be selected for link creation only after successful verification. Admin, API, and health-check routes still allow only `LINKVAULT_BASE_URL`. The Nginx/Caddy `server_name` or site label and the TLS certificate must explicitly include the same domain; do not use a catch-all that accepts arbitrary Hosts. Current short codes are globally unique across all domains.

Reverse-proxy deployments must also configure the proxy’s directly connected IPs through `LINKVAULT_TRUSTED_PROXIES`, with multiple IPs separated by English commas, for example `127.0.0.1,10.0.0.10`. Only `X-Forwarded-Proto` and `X-Forwarded-For` from these IPs are used for `Secure` Cookie detection and login IP rate limiting; the proxy must overwrite rather than append client-supplied request headers with the same names. API edge rate limiting protects ingress capacity; application-level business quotas are counted independently per Token only after Token authentication succeeds.

The repository provides [Nginx](deploy/nginx.conf) and [Caddy](deploy/Caddyfile) examples. Replace the domain, project path, and PHP-FPM Socket before use; with an upstream proxy, also replace the Caddy `trusted_proxies` example address with the accurate directly connected proxy IP. Whenever a short-link domain is added, enabled, or disabled, and whenever trusted proxies are changed, first run `php bin/check-deployment-domains.php --server=caddy --config=/etc/caddy/Caddyfile` or `--server=nginx --config=/etc/nginx/sites-enabled/linkvault.conf`; `--generate` outputs a domain/proxy checklist for review. Both configurations allow FastCGI to execute only `index.php`; Apache’s [`.htaccess`](public/.htaccess) likewise rejects other PHP files. The Caddy rate-limiting example requires a build using `xcaddy build --with github.com/mholt/caddy-ratelimit`. Content-hashed assets are cached for one year and marked `immutable`; unhashed source assets are cached for one hour, and dynamic HTML/API responses are not cached. Nginx enables gzip for compressible static types, while Caddy uses zstd/gzip.

The Nginx/Caddy examples also apply per-client and site-wide rate limits to `/login`, and set initial limits of 20 RPS per client and 200 RPS globally for valid short-code paths; adjust them after capacity testing based on PHP-FPM workers, SQLite write latency, and actual traffic. Nginx additionally limits connections per IP and site-wide and writes login requests to the dedicated `linkvault-security.log`. A client-facing edge server can install the [Fail2ban configuration](deploy/fail2ban) into `/etc/fail2ban`, then enable only the jail matching the current Web server; the rules ban a source for one hour after consecutive login `429` responses. When deployed behind an upstream proxy, reliably restore the real client IP first and connect the ban action to the upstream proxy or firewall; do not directly ban the proxy’s directly connected IP on the application host.

Environment variables should be configured in the PHP-FPM systemd service, pool configuration, or container Secret. If PHP-FPM uses the default `clear_env = yes`, explicitly pass them through pool entries such as `env[LINKVAULT_ADMIN_PASSWORD]` and `env[LINKVAULT_BASE_URL]`. The repository provides a separate [PHP-FPM pool](deploy/php-fpm-linkvault.conf), [systemd environment pass-through configuration](deploy/php-fpm-linkvault.service.conf), [migration task](deploy/linkvault-migrate.service), and [production preflight task](deploy/linkvault-preflight.service). Both the Nginx and Caddy examples are connected to this separate pool.

Debian/Ubuntu installation example (adjust service names and the PHP version for the system):

```bash
sudo install -d -o root -g root -m 0755 /etc/linkvault
sudo useradd --system --home /nonexistent --shell /usr/sbin/nologin linkvault-backup
sudo install -d -o www-data -g www-data -m 0750 /var/lib/linkvault /var/log/linkvault
sudo install -d -o linkvault-backup -g linkvault-backup -m 0700 /var/backups/linkvault
sudo install -d -o linkvault-backup -g www-data -m 2750 /var/lib/linkvault-backup-status
sudo install -o root -g root -m 0600 deploy/linkvault.env.example /etc/linkvault/linkvault.env
sudoedit /etc/linkvault/linkvault.env
# Required when LINKVAULT_RESTORE_DRILL_SOURCE=remote. Keep both root-only.
sudo install -o root -g root -m 0400 /secure/offline/age-identity /etc/linkvault/restore-age-identity
sudo install -o root -g root -m 0400 /secure/rclone.conf /etc/linkvault/restore-rclone.conf

sudo install -m 0644 deploy/php-fpm-linkvault.conf /etc/php/8.5/fpm/pool.d/linkvault.conf
sudo install -d -m 0755 /etc/systemd/system/php8.5-fpm.service.d
sudo install -m 0644 deploy/php-fpm-linkvault.service.conf /etc/systemd/system/php8.5-fpm.service.d/linkvault.conf
sudo install -m 0644 deploy/linkvault-migrate.service deploy/linkvault-preflight.service /etc/systemd/system/
sudo systemctl daemon-reload

sudo systemctl restart php8.5-fpm
sudo nginx -t && sudo systemctl reload nginx
curl --fail --silent --show-error https://s.example.com/healthz
```

Replace `REPLACE_ME`, the example domain, and the example rclone target in the environment file; the admin password must be at least 12 characters and satisfy the complexity requirements above. Keep `LINKVAULT_TRUSTED_PROXIES` empty when Nginx faces clients directly; fill it with the actual directly connected IPs only when a CDN or load balancer exists. The PHP-FPM drop-in uses `Requires=` and `After=` to start migration and then run the production preflight; failure of either task prevents PHP-FPM from starting. `linkvault-preflight.service` runs as `www-data` with the same environment file and checks placeholders, password policy, public HTTPS address, proxy IPs, backup and alert destinations, file permissions, database integrity, foreign keys, and the current schema. After restart, `/healthz` must return 200 to prove that the PHP-FPM workers actually processing requests also inherited the configuration.

In production, set `display_errors = Off` and `display_startup_errors = Off` in the `php.ini` used by PHP-FPM or in the pool configuration, and enable `log_errors = On`. See [deploy/php-production.ini](deploy/php-production.ini). The application entry point also disables error display, but PHP startup and syntax errors occur before the entry point executes and can only be prevented from exposing paths and stacks through PHP-FPM configuration.

The database is created at `data/linkvault.sqlite` by default; change its location with `LINKVAULT_DATABASE_PATH`. Application logs are written to `data/application.log` by default; change the location with `LINKVAULT_LOG_PATH`. Login failures, login lockouts, rate-limit storage errors, redirect-statistics update errors, and uncaught errors are written there as JSON Lines. Source IPs in authentication risk-control events retain only an IPv4 `/24` or IPv6 `/64` network identifier, and User-Agent is not retained. Logs are for detecting brute-force attempts, investigating security incidents, and validating rate-limit policies, not for user profiling. Authentication risk-control logs rotate daily and are retained for 7 days by default; access should be limited to security operations personnel, and expired logs should be deleted from online directories and backups. Each log entry includes `LINKVAULT_RELEASE_VERSION`, `LINKVAULT_BUILD_TIME`, and the current Schema version; the response `X-Request-ID` and the request number on error pages can be matched to the log’s `request_id`. Passwords are not logged. Production environments should use system log rotation tools to limit log size and retention, and monitor `login_failed`, `login_blocked`, `unhandled_exception`, `fatal_error`, and database error events.

The application rate-limits source IPs and the current session separately: five failures within 15 minutes cause a 15-minute lockout. Login requests no longer wait synchronously inside a PHP worker, so public deployments must retain the proxy-layer rate limits above. Authentication sessions have a 30-minute idle timeout and an 8-hour absolute timeout.

The SQLite lock-wait budget for admin requests is 5 seconds by default, with at most 3 retries; short-link redirects use a 250-millisecond lock wait and 2 attempts. Redirect statistics are best-effort: a statistics write failure is logged, but a valid 302 redirect is still returned so lock contention on statistics cannot block user access.

Session Cookies explicitly enable `HttpOnly`, `SameSite=Lax`, and strict session mode. `Secure` is enabled automatically for HTTPS requests; the application itself does not force HTTPS redirects, so public deployments should provide HTTPS at the Web-server or reverse-proxy layer.

The application also consistently sends CSP, `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, and `Permissions-Policy`; HTTPS requests send HSTS. HSTS for the default admin domain includes subdomains, while custom short-link domains send a policy for the current host only. Before enabling public HTTPS, confirm that all subdomains under the default primary domain support HTTPS. Ordinary requests and public health probes check only `PRAGMA user_version`; full table, index, foreign-key, and trigger validation is performed by the migration command, production preflight, and the post-login System Status page.

## Engineering and Operations

The entry file is responsible only for startup and route dispatch. Startup configuration and common security logic are in `app/bootstrap.php`; link CRUD and statistics/import/export are in `app/LinkService.php`; Token lifecycle is in `app/ApiTokenService.php`; page templates are in `templates/`; browser assets are in `public/assets/`. Database migrations run consecutively from `migrations/001_*.sql` through the current version; run `php bin/migrate.php` both before and after upgrades.

Imports accept `kind=link_export` files with `version=1`, `version=2`, or domain-aware `version=3`; audit-data snapshots and untyped JSON are not accepted. v2 migrates `password_protected`, `invalid_message`, and `fallback_url`; password material is not exported, so protected links remain disabled after import, and persisted state requires their password to be set again before they can be enabled. The conflict strategy is fixed at upload and written into the Dry Run plan: `skip` skips existing short codes, `overwrite` covers only migratable fields while preserving the record ID, click statistics, recycle-bin state, and local access protection, and `new_slug` previews a deterministically generated new short code. During confirmation, record fingerprints and short-code occupancy are rechecked inside `BEGIN IMMEDIATE`; any stale plan is rejected as a whole. Limits are 2 MiB, 5,000 records, and 2,048 bytes per destination URL; JSON is still parsed in memory first, so leave headroom according to PHP `memory_limit`.

Health checks do not require login: `GET /livez` only confirms that the PHP process can respond; `GET /readyz` checks the strong password, database version and readability, database-file and directory writability, and remaining disk space without acquiring a SQLite write lock; `GET /healthz` adds backup-file existence, size, SHA-256, and freshness checks based on `.last-local-success.json`, and also requires a valid off-site-success marker when off-site backup is required. All three responses include the release version, build time, and supported Schema version. The Nginx example configures separate per-IP and global rate limits for the latter two probes. Failures uniformly return HTTP `503`, and responses do not expose paths or exceptions. Fixed-domain deployments still reject mismatched `Host` values. When the database file is missing, read-only, full, affected by an I/O error, or corrupt, public short links also return `503`; they return `404` only when the database is readable and the slug is confirmed not to exist or be available.

Application logs are JSON Lines and are written to `data/application.log` by default; development-server logs are also written under `data/`. In production, install [deploy/linkvault-logrotate.conf](deploy/linkvault-logrotate.conf) and adjust its file header for the actual `LINKVAULT_LOG_PATH`. Application logs retain 7 compressed rotations and rotate when a single file exceeds 10 MiB; the authentication risk-control events they contain must also be no more than 7 days old. Raw analytics logs use rename/reopen rotation and retain 30 uncompressed daily rotations by default. The aggregator drains the old inode before switching to the new file; the cursor also saves a content checkpoint before its offset, detects truncation followed by growth on the same inode caused by an old `copytruncate` configuration, and resumes consumption from the start of the new active file. Production configuration still prohibits `copytruncate` because records written between copying and truncating cannot be recovered by the consumer. The Caddy example disables built-in analytics-log rotation so only this policy manages the file. The log directory should not be under a Web-downloadable path; `.gitignore` excludes runtime logs, backups, and recovery-drill directories.

Automated backups use SQLite’s online `.backup` and do not directly copy the primary database while it is in WAL mode. `bin/backup.php` performs integrity, application-schema, and foreign-key checks; production mode then encrypts with an age recipient public key, uploads to object storage through `rclone copyto --immutable`, verifies the remote object size, and atomically updates the success marker. Routine backup hosts need only the public key; the age private key and rclone configuration used by remote recovery drills should be supplied by a dedicated recovery host or controlled systemd credential, not placed in the application environment file. The remote bucket should enable versioning, lifecycle management, and optionally object lock; do not use `rclone sync` to propagate local retention cleanup. Linux systemd installation example:

```bash
sudo install -m 0644 deploy/linkvault-backup.service deploy/linkvault-backup.timer \
  deploy/linkvault-backup-age.service deploy/linkvault-backup-age.timer \
  deploy/linkvault-restore-drill.service deploy/linkvault-restore-drill.timer \
  deploy/linkvault-endpoint-monitor.service deploy/linkvault-endpoint-monitor.timer \
  deploy/linkvault-maintenance-notify.service deploy/linkvault-maintenance-notify.timer \
  deploy/linkvault-lifecycle-webhook.service deploy/linkvault-lifecycle-webhook.timer \
  deploy/linkvault-domain-retirement.service deploy/linkvault-domain-retirement.timer \
  deploy/linkvault-data-cleanup.service deploy/linkvault-data-cleanup.timer \
  deploy/linkvault-stats-retention.service deploy/linkvault-stats-retention.timer \
  deploy/linkvault-analytics.service deploy/linkvault-analytics.timer \
  deploy/linkvault-analytics-export.service deploy/linkvault-analytics-export.timer \
  deploy/linkvault-analytics-rollup-backfill.service \
  deploy/linkvault-analytics-anomaly.service deploy/linkvault-analytics-anomaly.timer \
  deploy/linkvault-target-health.service deploy/linkvault-target-health.timer \
  deploy/linkvault-notify@.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo install -d -o www-data -g www-data -m 0700 /var/lib/linkvault-restore-drill
sudo install -o root -g root -m 0400 /secure/offline/age-identity /etc/linkvault/restore-age-identity
sudo install -o root -g root -m 0400 /secure/rclone.conf /etc/linkvault/restore-rclone.conf
sudo systemctl enable --now linkvault-backup.timer linkvault-backup-age.timer \
  linkvault-restore-drill.timer linkvault-endpoint-monitor.timer \
  linkvault-maintenance-notify.timer linkvault-lifecycle-webhook.timer linkvault-domain-retirement.timer linkvault-data-cleanup.timer \
  linkvault-stats-retention.timer linkvault-analytics.timer linkvault-analytics-export.timer \
  linkvault-analytics-anomaly.timer linkvault-target-health.timer
sudo systemctl start linkvault-backup.service
sudo systemctl start linkvault-backup-age.service
sudo systemctl start linkvault-restore-drill.service
sudo systemctl start linkvault-analytics-rollup-backfill.service
```

`linkvault-backup.service` uses the unprivileged, non-login `linkvault-backup` account. The backup directory must be a `0700` directory owned exclusively by that account; do not add `www-data` to its group or grant it an ACL. The backup account accesses SQLite read-only through a supplementary group. After validation, the task publishes a proof marker without backup contents to `LINKVAULT_BACKUP_STATUS_DIR`. This directory uses setgid `2750`, is owned by `linkvault-backup`, and has group `www-data`, so Web access can read health status but cannot read, overwrite, or delete backups.

Backups require off-site upload by default and need `LINKVAULT_BACKUP_AGE_RECIPIENT`, `LINKVAULT_BACKUP_RCLONE_REMOTE`, and an HTTPS alert Webhook. `LINKVAULT_BACKUP_COMMAND_TIMEOUT_SECONDS` limits the maximum execution time of each SQLite, age, and rclone command, defaulting to 900 seconds. An immediate backup-task failure or hourly age-check failure triggers `OnFailure=linkvault-notify@%n.service`. After first enablement, a backup must succeed once or `/healthz` remains `503`; verify results with `journalctl -u linkvault-backup.service -u linkvault-backup-age.service`. In isolated deployments, `/healthz` validates the protected proof marker and its freshness; the backup task itself handles SQLite integrity, SHA-256, and remote-object-size validation.

The Nginx and Caddy examples configure independent rate limits for `/api/*`, `/readyz`, and `/healthz`, and write these requests to the `linkvault-endpoints` JSON log. The application also applies cross-worker atomic rate limiting to API Tokens through `LINKVAULT_API_RATE_LIMIT_REQUESTS` and `LINKVAULT_API_RATE_LIMIT_WINDOW_SECONDS`; Tokens without an independent quota inherit these defaults. Exceeding the limit returns `429` and `Retry-After`; a CIDR rejection returns `403 source_not_allowed`. Both are aggregated into admin alerts and written to the application log. After configuring the dedicated `LINKVAULT_METRICS_TOKEN` with at least 24 characters, Prometheus can scrape `GET /metrics` with a Bearer Token; without it, the endpoint returns `404`. Metrics include request volume, canary redirect latency, SQLite lock waits, queue backlog, Webhook dead letters, backup age, analytics latency, and target-check failure rate. Every 5 minutes, `linkvault-endpoint-monitor.timer` probes the home page, login form, readiness, operational health, pre-seeded canary short link, and API authentication entry point through `LINKVAULT_BASE_URL`; failures reuse `linkvault-notify@.service` to send Webhook alerts. Each run also atomically writes the HTTP status, duration, and validation result for every probe to `LINKVAULT_SYNTHETIC_STATUS_PATH`, which the status center uses to show specific failures; result freshness is controlled by `LINKVAULT_SYNTHETIC_STATUS_MAX_AGE_SECONDS`. After enabling `LINKVAULT_CANARY_ENABLED=1`, run `php bin/seed-canary.php` to idempotently create the canary; probes use `HEAD` and do not increase link clicks. External monitoring uses `.github/workflows/synthetic.yml`; configure the repository with `SYNTHETIC_BASE_URL`, `SYNTHETIC_CANARY_TARGET_URL` Secrets, and the optional `SYNTHETIC_CANARY_SLUG` Variable.

`linkvault-restore-drill.timer` uses `LINKVAULT_RESTORE_DRILL_SOURCE=local` by default; production can switch it to `remote`. Remote mode reads only the strictly validated object basename, size, and SHA-256 in `.last-remote-success.json`, downloads that object from the configured target with `rclone copyto` without listing bucket contents, then decrypts it with `age --decrypt --identity` to a `.part` file and atomically publishes an isolated SQLite copy. The drill first checks pre-migration integrity, `user_version`, and the `links` table, then runs current migrations plus integrity, foreign-key, Schema, rollback-write, and up to 10 redirect spot checks. The plaintext runtime directory must be fully deleted before the v2 success marker is published, and results are not written to the production database audit table. The systemd unit supplies `/etc/linkvault/restore-age-identity` and `/etc/linkvault/restore-rclone.conf` through `LoadCredential`; when a separate rclone configuration is not needed, remove the latter’s `LoadCredential` and `Environment` lines and leave `LINKVAULT_RESTORE_RCLONE_CONFIG` unset.

Every day, `linkvault-maintenance-notify.timer` summarizes expiring links, click-quota thresholds, long-term zero-click links, and local/off-site backup anomalies, then sends JSON to `LINKVAULT_MAINTENANCE_WEBHOOK_URL`; the task safely skips when the URL is not configured, and successful notifications on the same UTC day are not repeated by default. The maintenance page and notifications read `LINKVAULT_MAINTENANCE_EXPIRING_DAYS`, `LINKVAULT_MAINTENANCE_STALE_DAYS`, and `LINKVAULT_MAINTENANCE_QUOTA_PERCENT` from the same policy and share the same UTC time within one evaluation. Immediate backup-task failures are still sent through the separate alert Webhook.

All outbound Webhooks allow only public HTTPS/443 addresses without credentials or fragments. Before sending, all A/AAAA answers are resolved and checked; any private, reserved, or mixed public/private result is rejected. Connections are pinned to verified addresses through `CURLOPT_RESOLVE` and the actual primary connection IP is checked, with redirects not followed automatically, so Bearer Tokens are not forwarded to another origin. Lifecycle events also carry a stable event ID, timestamp, and `HMAC-SHA256(timestamp.event_id.body)` signature; recipients must deduplicate by event ID. A Webhook response of 3xx is treated as failure, and the outbox retries at most 8 times before entering the dead-letter queue.

`linkvault-data-cleanup.timer` regularly deletes expired admin-created requests, API idempotency records, bulk-operation records, Token usage records, expired Token metadata, and audit records every day. Bulk previews are valid for 15 minutes; reversible operations can be undone within 24 hours after application, and operation evidence is retained for 7 days. Cleanup is no longer triggered randomly by ordinary requests. The idempotency retention period is set by `LINKVAULT_IDEMPOTENCY_RETENTION_SECONDS`; Token usage records are retained for 90 days by default, and revoked or expired Token metadata is retained for an additional 180 days by default, controlled by `LINKVAULT_API_TOKEN_USAGE_RETENTION_DAYS` and `LINKVAULT_API_TOKEN_RETENTION_DAYS`, respectively. Audit retention is set by `LINKVAULT_AUDIT_RETENTION_DAYS`.

`linkvault-stats-retention.timer` daily processes redirect daily statistics older than `LINKVAULT_DAILY_STATS_RETENTION_DAYS`. `LINKVAULT_DAILY_STATS_RETENTION_MODE=archive` first writes to an archive table and then deletes online details, while `delete` deletes the details directly; neither mode modifies the cumulative `links.clicks`. The same task also transactionally rolls up hourly access aggregates older than `LINKVAULT_ANALYTICS_HOURLY_RETENTION_DAYS` (90 days by default) into the daily table, then removes daily data according to `LINKVAULT_ANALYTICS_RETENTION_DAYS`.

Every 5 minutes, `linkvault-analytics.timer` parses Nginx/Caddy JSON access logs. Parsing reads User-Agent and referrer sites only in memory; SQLite stores only aggregate counts for UTC hour, link, country/region, device category, browser/OS category, referrer domain, traffic type, and campaign fields. Consumption position and aggregate writes are in the same transaction. The analytics page displays the latest data time, aggregation completion time, aggregation status, and log backlog. When status is missing, stale, failed, the log is missing, or backlog exists, the page does not display zero-traffic conclusions, and sustained-zero alerts are also paused. Neither example analytics log includes client or proxy-chain IPs, Cookies, Authorization, request query strings, referrer paths, or referrer query strings. Raw logs are retained for 30 days by logrotate by default, daily aggregates for 365 days by default, and the admin status center shows these governance boundaries as well. With Caddy, change `LINKVAULT_ANALYTICS_LOG_PATH` to `/var/log/caddy/linkvault-analytics.log`. The example reads the CDN-provided `CF-IPCountry` country code; this field is trustworthy only when the origin accepts traffic exclusively from trusted CDN sources, while direct deployments should leave the region unknown or connect a trusted GeoIP variable.

Every 5 minutes, `linkvault-analytics-anomaly.timer` checks closed hours. It detects traffic surges relative to the previous 24-hour baseline, sustained zero traffic after a baseline exists, anomalous bot share after the minimum request volume is reached, and aggregation status that is stale, continuously failing, or missing logs. Notifications reuse `LINKVAULT_ALERT_WEBHOOK_URL`; the status table deduplicates by anomaly type, and repeated reminders are controlled by `LINKVAULT_ANALYTICS_ANOMALY_COOLDOWN_SECONDS`.

Every 15 minutes, `linkvault-target-health.timer` starts an independent CLI batch. It selects only enabled, non-deleted links that have reached their check time, with a default maximum of 50 per batch; public short-link redirects never probe targets. The checker permits only HTTP/HTTPS on configured ports, rejects credentials and fragments, resolves CNAMEs with bounds before connecting, validates all A/AAAA answers, and rejects private, reserved, documentation, benchmarking, or transition addresses. Each hop is pinned to a verified IP through `CURLOPT_RESOLVE`, while retaining the original Host/SNI and checking the actual primary connection IP; on transport failure it tries at most 4 verified addresses within the same total timeout budget. The application handles redirects hop by hop, does not forward credentials, and disallows HTTPS downgrade, cross-origin redirects, private-network targets, loops, or exceeding the limit. Target anomalies appear only in the maintenance page and status center and do not affect `/readyz` or `/healthz`.

By default, the analytics page calculates 7/30/90-day and custom date boundaries using the browser’s IANA timezone. It also displays the actual start/end dates of aggregated data and retention boundaries, clearly distinguishing zero traffic within the retention period from data that has been cleaned up. Active periods read only the hourly table and cover the latest 90 days by default; data beyond the hourly retention window has only UTC daily precision, so long local-timezone queries can approximate only by day at the boundary ends. Run `linkvault-analytics-rollup-backfill.service` once after migration. Before it completes, reports continue to read the original fact table; after it completes, complete UTC dates read the daily rollup, while non-UTC boundary ends still read the hourly table. Analytics exports enter a persistent background queue and poll for status, with a default maximum of 500,000 rows and 24-hour file retention; the limit and retention period are set by `LINKVAULT_ANALYTICS_EXPORT_MAX_ROWS` and `LINKVAULT_ANALYTICS_EXPORT_RETENTION_HOURS`, respectively. The old synchronous export interface remains limited to 50,000 rows. “Likely human” on the page is a User-Agent classification, not UV.

UV digests are not currently collected or stored. Before enabling a daily rotating HMAC, first define the processing purpose and notice method, the IP policy under trusted proxies, key custody and rotation, digest retention, access permissions, and deletion response. Even when irreversible, digests should still be managed as pseudonymized data.

Conversion events use the separate `conversions:write` scope. `POST /api/conversions` must include `Idempotency-Key`, Unix-second `X-LinkVault-Timestamp`, and `X-LinkVault-Signature`; the signature is `sha256=<HMAC-SHA256>` calculated over `timestamp.idempotencyKey.rawBody` using the current Bearer Token as the key. Requests are accepted only within 300 seconds before or after the current time by default. The admin “Conversions” workspace uses cumulative short-link clicks as the entry point and displays event-stage counts and event-count/click-count conversion rates; this ratio is not a user-level funnel, and no UV identifier is collected.

`browser-extension/` can be installed from the Chromium extension management page using “Load unpacked”. The Token is stored only in the browser extension’s local storage; creating a Token for the extension with only `links:create`, a short expiration, an independent quota, and an accurate CIDR is recommended.

Before publishing to the Chrome Web Store, configure the monitored `LINKVAULT_BROWSER_EXTENSION_PRIVACY_CONTACT` and submit the public HTTPS address `https://<your-service-domain>/browser-extension-privacy` on the store privacy page. Link search and health status also require adding the `links:read` scope to the extension Token.

The public report entry point is `GET/POST /report`, with a default limit of 5 per source per hour. The source address is used only for the daily hashed quota and report deduplication and is not written to report records. Administrators can review, disable, or restore links, maintain the domain blacklist, and run a single-link scan in the “Trust & Safety” workspace; scheduled batch scans can be run with `php bin/scan-link-risks.php`.

## Time and Expiration Settings

Times in the database and JSON import/export are stored uniformly as UTC. The admin page displays creation, expiration, and last-redirect times in the browser’s current timezone; `datetime-local` inputs are also interpreted in browser local time and carry the UTC offset for that date when submitted. This lets you set “tomorrow at 18:00” without manually converting to UTC.

Daily statistics are written by UTC calendar day. The admin page’s recent 14-day totals and details follow the current search, status, tag, and favorite filters; the detail page can switch a single link among 7, 14, and 30 UTC calendar-day trends.

`expires_at` and `starts_at` in import files should use timezone-aware ISO 8601 values, such as `2026-08-01T10:00:00Z` or `2026-08-01T18:00:00+08:00`. Import times without a timezone are marked invalid to avoid ambiguity. Imports also support `tags`, `is_favorite`, `max_clicks`, `is_one_time`, and `one_time_mode`.

## Smoke Tests

Tests create a temporary database, run independent migrations, and start a temporary PHP service; they do not read or write the production database:

```powershell
php tests/smoke.php
```

Coverage includes the v1-v23 historical upgrade matrix, failed rollback and retry recovery across multiple versions, backup validation, layered health probes, database-missing `503`, creation idempotency, one-time-link confirmation, saved filters, structured auditing, statistics archiving, scoped Tokens and CRUD API, import rollback across the 100-item boundary, login and CSRF, link status, security response headers, and concurrent redirect counting. After installing development dependencies, you can also run:

```bash
composer install && composer check
npm install && npx playwright install chromium && npm run test:e2e
k6 run -e BASE_URL=https://staging.example.com -e SLUG=capacity01 tests/capacity.js
```

## Performance Baselines and SQLite Maintenance

Production PHP-FPM must load `deploy/php-production.ini` and confirm `opcache.enable=1` in the Web SAPI; do not substitute results from the CLI SAPI, where OPcache is disabled by default. Each PHP-FPM worker uses an independent SQLite page cache; `LINKVAULT_SQLITE_CACHE_SIZE_KIB` defaults to 32768. Budget memory at least as `cache_size × pm.max_children`, leaving additional headroom for PHP, OPcache, Nginx, and the operating-system page cache.

Release migrations and the daily data-cleanup service both run `php bin/optimize-database.php`. It runs lightweight `PRAGMA optimize` and does not perform blocking `VACUUM`. Application logs record duration, operation type, table name, and SQL fingerprint for database operations exceeding `LINKVAULT_SQLITE_SLOW_QUERY_MS`; parameter values are not logged. Lock timeouts are recorded separately as `sqlite_lock_wait`.

Analytics reports materialize the current range into request-level temporary tables when it is no larger than `LINKVAULT_ANALYTICS_MATERIALIZE_MAX_ROWS` (250,000 by default), reusing them for totals, trends, dimensions, and rankings; set it to `0` to disable. Results are cached by database/WAL fingerprint for `LINKVAULT_ANALYTICS_REPORT_CACHE_SECONDS` (60 by default) seconds; any database write changes the fingerprint and automatically invalidates the cache. The cache directory is specified by `LINKVAULT_ANALYTICS_REPORT_CACHE_DIR` and must be accessible only to the PHP-FPM user. The migration service automatically starts one daily-dimension Rollup backfill; you can also run `php bin/backfill-analytics-rollups.php` manually. With large production datasets, start with a materialization limit of 100,000–250,000 and observe PHP-FPM RSS, temporary disk, and P95 at the same time.

Offline benchmarks cover the first and last admin pages, search, analytics reports, filtered CSV, and redirect queries:

```bash
php tests/performance/benchmark.php --sizes=10000,100000,1000000 --iterations=7 --output=benchmark.json
php tests/performance/benchmark.php --sizes=100000 --iterations=7 --rollup-ready
```

Run mixed load against the real production topology with a logged-in admin session, and check hashed static assets separately:

```bash
k6 run -e BASE_URL=https://staging.example.com -e SLUG=capacity01 -e ADMIN_COOKIE='linkvault_session=REDACTED' tests/capacity-workloads.js
k6 run -e ASSET_URL=https://staging.example.com/assets/app.45121adb305c.js tests/static-assets.js
```

Nginx writes performance records for routes without query parameters to `linkvault-performance.log` and static-transfer records to `linkvault-static.log`. The following command reports, for the last 24 hours, P50/P95/P99 for each request class, 5xx responses, static 304 ratio, transferred bytes, slow SQL, lock failures, and WAL size:

```bash
php bin/performance-report.php --window-seconds=86400
```

Hashed assets use a one-year `immutable` cache, and browser hits do not reach Nginx. Therefore, `validation_hit_ratio` in server logs represents only the 304 ratio of conditional requests and is not the complete browser-cache hit rate. Collect the complete hit rate from RUM/CDN metrics as well. After each release and capacity change, save percentile baselines for the home page, redirects, admin list, search, analytics, and exports, and record PHP-FPM worker count, CPU, memory, database/WAL size, and data volume alongside them.

## Backup and Recovery

“Export Links” in the admin page contains only migratable fields from non-recycle-bin links and can be imported again through JSON. “Audit Data Snapshot” also includes the recycle bin, cumulative clicks, creation/update times, last-visited time, daily statistics, bulk operations, saved analytics views, target health, analytics alerts, redacted Token metadata, and usage records. Its `table_manifest` explicitly lists included tables, excluded tables, and redacted fields. It is for auditing or offline processing only, contains no Token digests or password material, cannot be imported, and is not a database recovery file. Recoverable backups must use the SQLite online-backup process below.

Deleting a short link moves it to the recycle bin by default; data is removed only when “Permanent Delete” is executed from the recycle bin. After deployment, continue to back up regularly with the SQLite online-backup command. Do not directly copy the primary database while the application is running because WAL may contain unmerged data.

Create a backup and immediately check SQLite integrity:

```powershell
New-Item -ItemType Directory -Force .\backups
$stamp = Get-Date -Format "yyyyMMdd-HHmmss"
$backup = ".\backups\linkvault-$stamp.sqlite"
sqlite3 .\data\linkvault.sqlite ".backup '$backup'"
sqlite3 $backup "PRAGMA integrity_check;"
```

`integrity_check` must output `ok`, but it does not verify that the database can be used by the current application. Routine automated backups should be created with `php bin/backup.php`. To manually recover an off-site object, first download it with `rclone copyto <remote-object> linkvault.sqlite.age`, then decrypt it on an isolated recovery host with `age --decrypt --identity <offline-identity> --output linkvault.sqlite linkvault.sqlite.age`, and run the integrity check and recovery procedure below. Automated drills can set `LINKVAULT_RESTORE_DRILL_SOURCE=remote` and `LINKVAULT_RESTORE_AGE_IDENTITY`, and set `LINKVAULT_RESTORE_RCLONE_CONFIG` when needed; verify key availability and remote downloads at least quarterly.

Recovery steps:

1. Stop the application or enter maintenance mode and ensure there are no write requests.
2. Use the `.backup` command above to make a retained copy of the current database, ensuring committed WAL data is included.
3. Run `sqlite3 <backup-file> "PRAGMA integrity_check;"` against the backup to be restored and confirm that it outputs `ok`.
4. Replace `data/linkvault.sqlite` with the backup file, and while the application is stopped remove any `linkvault.sqlite-wal` and `linkvault.sqlite-shm` remnants in the same directory.
5. Run `sqlite3 .\data\linkvault.sqlite "PRAGMA integrity_check;"` again, then run `php bin/migrate.php` to upgrade the restored data to the current structure.
6. Start the application and spot-check short links.

Perform a recovery drill at least quarterly. Restore to an isolated path and verify it on a separate port:

```powershell
$sourceBackup = ".\backups\linkvault-YYYYMMDD-HHMMSS.sqlite"
$drillDir = ".\restore-drill\$(Get-Date -Format 'yyyyMMdd-HHmmss')"
New-Item -ItemType Directory -Force $drillDir
Copy-Item $sourceBackup "$drillDir\linkvault.sqlite"
$env:LINKVAULT_DATABASE_PATH = (Resolve-Path "$drillDir\linkvault.sqlite")
$env:LINKVAULT_LOG_PATH = "$drillDir\application.log"
$securePassword = Read-Host "Enter drill admin password" -AsSecureString
$env:LINKVAULT_ADMIN_PASSWORD = [System.Net.NetworkCredential]::new("", $securePassword).Password
php bin/migrate.php
php -S 127.0.0.1:8081 -t public public/router.php
```

Record the drill date, backup used, `integrity_check` result, link spot-check results, and actual recovery duration.
