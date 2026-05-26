## Why

GEOflow is moving from a single-site content tool into a long-term multi-site精品站 operating system. The current system can create isolated sites and import DataForSEO keywords, but it still lacks fast site bootstrapping, publish-quality guardrails, changed-URL discovery push, and per-site spend controls.

## What Changes

- Add a site clone workflow so a proven site skeleton can be copied into a new site without manually recreating categories, settings, authors, keyword libraries, title libraries, and knowledge bases.
- Add article quality scoring and publish gates so low-value generated content is held for review instead of being published automatically.
- Add a changed-URL queue and IndexNow service so published/updated URLs can be submitted intentionally instead of relying only on crawler discovery.
- Add per-site DataForSEO budget settings and a spend ledger so keyword imports have a hard daily cap and visible spend history.
- Keep all secrets in environment variables; do not persist API passwords or IndexNow secrets in code or notes.

## Capabilities

### New Capabilities

- `site-template-cloning`: Clone reusable site-level configuration and library scaffolding from one site into a new site.
- `content-quality-gates`: Score article quality and block automatic publishing when content does not meet the current site's minimum threshold.
- `changed-url-indexing`: Track changed public URLs and submit them to IndexNow with per-site host validation.
- `site-spend-guardrails`: Enforce per-site DataForSEO daily budget limits and record API spend by site.

### Modified Capabilities

- None.

## Impact

- Affected admin surfaces: `admin/sites.php`, `admin/site-settings.php`, `admin/keyword-research.php`.
- Affected services: article workflow, cron auto-publish, DataForSEO imports, search discovery endpoints.
- Affected schema: new lightweight tables for URL indexing queue and site API spend; new article quality columns; optional site setting keys.
- Affected runtime: PHP/web/scheduler/worker images must include new service files.
