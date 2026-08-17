# Changelog

This project follows Keep a Changelog and Semantic Versioning. Release notes are immutable after the corresponding `vX.Y.Z` tag is created.

## [Unreleased]

### Added

- Deployment domain inventory validation and generation for Caddy and Nginx.
- Production governance, SLO, change-control, compatibility, ADR, and logging-policy records.

### Changed

- Caddy no longer retains a broad generic access log; endpoint logs exclude client addresses, headers, and query strings.

## [2.0.0] - 2026-08-05

### Added

- Release status, synthetic monitoring, metrics, backup and analytics operational controls.

## Release Rules

- Use annotated tags named `vX.Y.Z` only after production approval.
- Add user-visible behavior, migration, operational, and rollback notes to `Unreleased` before the release PR.
- Move `Unreleased` notes into the tagged version as part of the release PR. Do not rewrite a published tag.
