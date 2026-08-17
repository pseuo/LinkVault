# Production Change Checklist

Complete this in the PR and attach command output or monitoring links to the change record.

## Before Approval

- [ ] Change record, owner, reviewer, release owner, scheduled window, and rollback owner are named.
- [ ] `CHANGELOG.md`, compatibility impact, and ADR impact are updated.
- [ ] CI is green; relevant smoke, static analysis, migration, privacy, and deployment-domain tests passed.
- [ ] Database migration is additive or has database-owner approval, backup confirmation, restore evidence, and a rollback plan.
- [ ] Capacity impact is measured against `docs/slo-and-capacity.md`.
- [ ] Domain changes passed `php bin/check-deployment-domains.php --server=<caddy|nginx> --config=<active-config>` and TLS SAN coverage was verified.
- [ ] Secret, trusted-proxy, logging, and privacy impacts were reviewed.

## Deployment

- [ ] Record tag, commit, release version, build time, previous verified tag, schema versions before/after, and operator.
- [ ] Run assets build and migrations before serving new traffic.
- [ ] Validate proxy syntax and reload only after the domain inventory command passes.
- [ ] Validate `/livez`, `/readyz`, `/healthz`, authenticated admin access, and the canary redirect.
- [ ] Confirm backup age, analytics consumer status, queue backlog, SQLite lock failures, and alert delivery are within SLO.

## After Deployment Or Rollback

- [ ] Watch the release for 30 minutes or one full scheduled analytics interval, whichever is longer.
- [ ] Record outcome and baseline deltas. Create follow-up work for every waived item.
- [ ] On rollback, stop promotion, restore the prior application tag, execute the database plan, validate health and canary, and open an incident review.
