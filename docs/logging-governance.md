# Production Logging Governance

## Purpose And Access

| Dataset | Minimum fields | Retention | Access | Backup and deletion |
|---|---|---:|---|---|
| Application log | UTC time, event, request ID, release/schema version, redacted failure context | 7 days | Security and on-call only | Encrypted operational backup if required; remove expired rotations and backup copies together. |
| Security log | Source IP only for login/unlock rate-limit and Fail2ban decisions, method, path without query, status | 7 days | Security and on-call only | `linkvault-security.log` rotates daily; do not send to analytics. |
| Endpoint log | UTC time, request ID, method, path without query, status, latency, bytes, limit status | 30 days unless an incident requires a shorter approved hold | On-call and platform operators | Exclude IP, headers, cookies, authorization, and query strings; delete with proxy log retention. |
| Analytics raw log | UTC time, method, path without query, status, country, User-Agent, referrer host only | 30 days | Analytics operators only | Rotate with rename/reopen, never `copytruncate`; remove expired raw files after aggregation. |
| Analytics aggregates | Hourly dimensions and counts, then daily aggregates | 90 days hourly, 365 days daily by default | Administrators and analytics operators | Retain through `linkvault-stats-retention.timer`; no client identifiers are stored. |

The proxy's generic Caddy access log is deliberately discarded. A broad Nginx access log must not be enabled for LinkVault unless a privacy review documents its fields, retention, access controls, and an exception expiry. Nginx endpoint and analytics formats in `deploy/nginx.conf` are the permitted baseline.

## Controls

- Logs must live outside the web root with least-privilege ownership. Never include passwords, tokens, cookies, Authorization headers, full referrers, client/forwarded IPs in analytics, or request query strings.
- Authentication event IPs in application logs are reduced to IPv4 `/24` or IPv6 `/64`; User-Agent is not retained there.
- Log access is audited through the hosting platform. Exporting raw logs requires security-owner approval and an incident or operational ticket.
- Retention changes, new fields, new sinks, or any identifier-derived metric require security-owner review and an ADR when the privacy boundary changes.
- Test rotation and analytics ingestion after every proxy logging change. The analytics status must remain current before dashboards interpret zero traffic.
