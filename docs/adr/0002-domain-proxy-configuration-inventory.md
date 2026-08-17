# ADR 0002: Domain And Proxy Configuration Inventory

## Status
Accepted 2026-08-13

## Context

Verified short-link domains live in SQLite, while Caddy/Nginx site labels, certificates, and trusted proxy settings are deployed separately. Manual synchronization can make a verified domain unreachable or make forwarded headers trusted by the wrong boundary.

## Decision

`bin/check-deployment-domains.php` derives the expected host inventory from `LINKVAULT_BASE_URL` and verified, enabled SQLite domains. It validates the active Caddy/Nginx host and proxy lists against `LINKVAULT_TRUSTED_PROXIES`; Nginx additionally requires configured certificate directives. Its `--generate` mode produces a reviewable inventory snippet, not an automatic proxy rewrite.

## Consequences

Domain enablement and proxy changes are one production change. Operators must validate syntax, certificate SAN coverage, inventory parity, reload, and canary behavior before completion.

## Rollout And Rollback

Keep a domain disabled until proxy/TLS configuration is deployed. On error, remove the domain from the proxy inventory and disable it in LinkVault; do not remove a domain with active links until its retirement workflow is complete.
