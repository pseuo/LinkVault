# SLO And SQLite Capacity Policy

## Service Objectives And Alerts

| Signal | Objective / warning | Critical threshold | Action |
|---|---:|---:|---|
| Redirect availability | 99.95% monthly successful canary redirects | below 99.9% over 30 minutes | Page on-call; release owner declares rollback or incident. |
| P95 redirect latency | under 250 ms over 15 minutes | 500 ms over 15 minutes | Check proxy, PHP-FPM saturation, and SQLite locks; shed nonessential work. |
| SQLite lock wait | under 100 ms max and zero failures in a 15-minute window | max over 250 ms or any lock failure for 5 minutes | Pause bulk writes; split analysis/queue writes; database owner investigates. |
| Analytics export queue | under 100 pending/running jobs | 500 jobs or oldest job over 30 minutes | Add worker capacity or pause export admission. |
| Webhook outbox | under 100 pending and zero dead letters | 500 pending for 15 minutes or any dead letter | Investigate destination and retry policy; notify integrations owner. |
| Backup freshness | local and required remote backup age under 8 hours | age over 8 hours or health check failure | Page on-call; database owner starts backup/restore investigation. |
| Analytics delay | under 10 minutes | over 15 minutes or analytics state missing/stale | Check log rotation and aggregation; pause zero-traffic conclusions. |

Prometheus names are defined by `/metrics`: `linkvault_redirect_latency_seconds`, `linkvault_sqlite_lock_wait_seconds`, `linkvault_sqlite_lock_failures`, `linkvault_queue_backlog`, `linkvault_backup_age_seconds`, and `linkvault_analytics_lag_seconds`. Availability comes from the external canary and `/healthz`, not an application counter.

## SQLite Single-Host Baseline

At 1,000,000 links on the recorded local hardware, cold analytics P95 is 3.315 seconds and search P95 is 252.502 ms. This is the operating baseline, not proof of unlimited SQLite headroom. See `docs/performance-baseline.md` for methodology.

Capacity review is mandatory before any sustained workload exceeds this baseline, cold analytics P95 exceeds 4 seconds, search P95 exceeds 300 ms, database plus WAL exceeds 70% of allocated storage, or the lock/queue thresholds above alert. Record links, analytics rows, database/WAL size, PHP-FPM workers, CPU, memory, and P95 evidence after each release or material traffic change.

## Red-Line Escalation

1. First isolate analytics aggregation, exports, webhook dispatch, and other queue writes from redirect and administrative write paths. Rate-limit or pause nonessential background work.
2. If contention persists, place analysis and queue ingestion on a separate database/write boundary before scaling the redirect path.
3. Do not begin a database migration during an active availability incident unless the incident commander and database owner approve it. Migrate only after a tested restore and cutover plan are approved through an ADR.
