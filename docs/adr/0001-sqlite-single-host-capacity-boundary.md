# ADR 0001: SQLite Single-Host Capacity Boundary

## Status
Accepted 2026-08-13

## Context

LinkVault stores transactional links and analytics aggregates in SQLite on one host. At one million links, the recorded cold analytics P95 is 3.315 seconds and search P95 is 252.502 ms. Redirect lookup remains low latency, but analysis and background writes can contend with foreground work.

## Decision

SQLite remains supported for the current single-host service. The performance record is a capacity baseline governed by `docs/slo-and-capacity.md`, not a scale guarantee. When red lines are reached, first separate analytics and queue writes from redirect and administrative work; do not wait for an outage to plan that boundary.

## Consequences

Releases affecting storage or background workload require capacity evidence. A database migration beyond the single-host boundary needs a follow-up ADR with restore, cutover, compatibility, and rollback plans.

## Rollout And Rollback

Background work can be rate-limited or paused first. No emergency data-store migration occurs without service-owner and database-owner approval.
