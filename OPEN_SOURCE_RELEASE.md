# GEOFlow Open Source Release Notes

This repository is prepared as a public, secondary-development distribution of
GEOFlow. It keeps the upstream Apache-2.0 license and NOTICE attribution, then
documents the downstream changes separately instead of removing the original
author notices.

## Origin and Attribution

- Upstream idea/project: [GEOFlow](https://github.com/yaojingang/GEOFlow) by Yao Jingang, originally distributed under the Apache License 2.0.
- License in this distribution: Apache License 2.0. Keep `LICENSE` and `NOTICE` in every public release.
- This distribution adds downstream modifications by Alex in 2026.
- The upstream project and author do not endorse or maintain downstream changes unless they explicitly say so.

## Main Downstream Changes

- Multi-site operations: `sites`, `site_domains`, current-site switching, site-level settings, and site-isolated admin workflows.
- Site cloning: copy a clean site template without copying articles, jobs, API spend, logs, uploads, or queue history.
- Keyword automation: DataForSEO keyword import with site-level budget guardrails and spend ledger.
- Search discovery: dynamic `sitemap.xml`, `robots.txt`, `llms.txt`, `llms-full.txt`, IndexNow key file endpoint, and search-submission queues.
- SEO/GEO operations: local readiness workbench, search snapshots, AI visibility checks, competitor briefs, internal-link opportunities, redirects, 404 logs, and image SEO metadata.
- Multi-provider push: IndexNow, Bing URL Submission API, and Baidu active push can be enabled per site.
- Quality guardrails: publishing gates for minimum score, minimum length, and reviewable quality issues.
- Security and deployment hardening: no fixed admin password, environment-only secrets, local static assets, reduced inline handlers, and stricter public error handling.
- Admin UI polish: cleaner multi-site navigation, task dashboard, and public/private release boundaries.

## Public Release Boundary

Do not publish these items:

- `.env`, `.env.*`, production Docker override files with real secrets
- API keys, DataForSEO password, Bing/Baidu tokens, IndexNow keys, cookies, SSH keys, or session tokens
- `uploads/`, generated images, knowledge-base source files, production article data, database dumps, and backups
- `logs/`, `bin/logs/`, `data/db/`, `data/backups/`, `data/login_attempts.json`
- `node_modules/`, `.playwright-cli/`, `output/`, `.DS_Store`, temporary exports, and local automation state

Use `.env.example` for variable names only. Real values belong in the server
environment or private deployment config.

## Release Checklist

Before pushing a public GitHub repository:

1. Prepare a clean mirror instead of pushing the private production tree directly.
2. Run `sh bin/git/prepare-open-source-release.sh /absolute/path/to/public-repo`.
3. In the public mirror, run `sh bin/git/check-open-source-release.sh`.
4. Run the relevant validation commands:
   - `php tests/unit_growth_guardrails.php`
   - `php tests/unit_seo_geo_ops.php`
   - PHP syntax check for `admin/`, `includes/`, `api/`, `bin/`, and root entry files
   - `npm ci`
   - `npm run build:tailwind`
   - `npm audit --audit-level=moderate`
5. Review README, NOTICE, SECURITY, and changelog before the first public push.

## Legal Note

This file is a practical release note, not legal advice. If the public release
becomes commercially important, have the final repository reviewed for third-party
licenses, bundled assets, and attribution completeness.
