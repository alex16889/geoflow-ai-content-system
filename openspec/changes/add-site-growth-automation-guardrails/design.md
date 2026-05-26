## Context

GEOflow already has multi-site tables, current-site resolution, site-scoped core lists, DataForSEO keyword import, and dynamic discovery endpoints. The missing layer is operational guardrails: a fast way to create new精品站 from a proven template, quality gates before publishing AI content, a changed-URL submission queue, and budget limits for paid SEO data.

The implementation must stay lightweight for a small VPS, avoid new long-running services, keep secrets in environment variables, and preserve the current Docker + PHP + PostgreSQL deployment model.

## Goals / Non-Goals

**Goals:**

- Let a super admin clone a site's reusable setup into a new active site.
- Score article quality before publish and block low-score automatic publishing.
- Queue changed public URLs and submit them through IndexNow with batching and retry state.
- Enforce site-level DataForSEO daily spend limits before paid keyword imports.
- Keep validation local and deployable with the existing PHP/web/scheduler/worker images.

**Non-Goals:**

- Full plugin/theme marketplace behavior.
- Fully automated article generation from zero input.
- Guaranteed AI-search citations or Google indexing.
- Complex billing/accounting across providers beyond DataForSEO spend guarding.

## Decisions

1. Use service files instead of embedding rules in admin pages.
   - Rationale: cloning, quality scoring, IndexNow, and spend limits will be reused by admin pages, API routes, cron, and workers.
   - Alternative considered: page-local helper functions. Rejected because it would duplicate business rules and increase串站 risk.

2. Store spend and IndexNow state in small append-only/queue tables.
   - Rationale: avoids external queue infrastructure and keeps deployment simple.
   - Alternative considered: log files. Rejected because per-site filtering and retry state are database problems.

3. Use site settings for thresholds and API flags.
   - Rationale: they are already site-scoped and easy to expose in the existing website settings UI.
   - Alternative considered: new columns on `sites`. Rejected for flexible tuning keys that may expand.

4. Gate publishing, not drafting.
   - Rationale: generated drafts can still be reviewed and improved; low-quality content should not be silently published.
   - Alternative considered: reject article creation. Rejected because it would throw away useful drafts.

5. Use IndexNow only when configured and only for the current site's public host.
   - Rationale: prevents accidental URL submission for tunnel/localhost or the wrong domain.
   - Alternative considered: automatic submission for every host. Rejected because it can submit invalid URLs and waste requests.

## Risks / Trade-offs

- Quality scoring is heuristic and can reject useful short pages → expose threshold settings and store issues for review.
- Site cloning can duplicate too much data → clone configuration and reusable libraries, not article history or job history.
- IndexNow requires a key file/endpoint and real domain → keep queueing enabled but submission disabled until the site has a non-local primary domain and key.
- Budget enforcement can block imports unexpectedly → show remaining daily budget on the import page before requests.
- Data migrations touch production tables → add backward-compatible `CREATE TABLE IF NOT EXISTS` and `ADD COLUMN IF NOT EXISTS` style guards, then verify on remote.

## Migration Plan

1. Add service files and lightweight tests for quality scoring, spend checks, and IndexNow URL eligibility.
2. Add schema guards to `DatabaseAdmin` so production initializes missing tables/columns safely.
3. Wire services into site management, site settings, DataForSEO import, article publish paths, and cron.
4. Build/redeploy containers after creating a remote backup.
5. Verify local syntax/build/audit/OpenSpec, then remote health, pages, and schema.
