# Repository And Release Governance

## Repository Setup

The project must be hosted in the organization's private GitHub repository. The canonical branch is `main`; direct pushes and force pushes are prohibited. This workspace could not initialize Git because the `git` executable is unavailable, so remote creation and first push remain an owner action.

1. Create the private remote repository and push this source with `main` as the default branch.
2. Enable branch protection for `main`: require pull requests, one approval from a code owner or release owner, resolved conversations, passing `CI`, `Capacity baseline`, and `Synthetic` checks where applicable, and up-to-date branches; block force pushes and deletions.
3. Configure a protected `production` GitHub Environment with required approval by the release owner. Store deployment credentials only as environment secrets.
4. Require signed annotated `vX.Y.Z` tags for releases. Protect the `v*` tag pattern so only release owners can create it.

## Roles

| Role | Responsibility |
|---|---|
| Service owner | Service risk acceptance, SLO ownership, and incident commander assignment. |
| Release owner | Approves production deployment, records deployed tag and rollback tag, and coordinates rollback. |
| Database owner | Reviews migrations, backup health, restore drills, and SQLite capacity decisions. |
| Security owner | Reviews auth, proxy trust, logging, domain, and privacy-impacting changes. |
| On-call operator | Executes the runbook, triages alerts, and escalates to the assigned owner. |

Each role must name a primary and backup in the production service catalog before the first release.

## Pull Requests

- Every change uses a PR linked to an issue or change record.
- One independent reviewer is required; database, proxy, logging, authentication, or privacy changes also require the matching owner above.
- CI must pass. Capacity or synthetic evidence is mandatory when changing redirect, SQLite, queue, analytics, or proxy behavior.
- The PR includes the completed production change checklist and any ADR reference.

## Release And Rollback

- Release from a reviewed `main` commit with an annotated `vX.Y.Z` tag. `LINKVAULT_RELEASE_VERSION`, build time, changelog summary, and previous verified tag must be set in the production environment.
- The release owner approves the protected production deployment and records the deployment, schema version, operator, and rollback tag in the change record.
- Rollback responsibility belongs to the release owner; the database owner approves any rollback across an irreversible migration. Prefer forward-compatible, additive migrations. Restore only through the documented backup process and an incident record.
- Test the last verified release, `/readyz`, `/healthz`, canary redirect, backup age, and domain deployment validation before declaring success.
