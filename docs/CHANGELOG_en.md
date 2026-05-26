# GEOFlow Changelog

This document tracks user-facing updates in the public repository. For future GitHub pushes, update this file together with the Chinese version in `CHANGELOG.md`.

## 2026-05-27

### SEO/GEO ops suite

- Added dynamic `llms.txt` and `llms-full.txt`, generated per current site for AI crawler guidance.
- Added the admin `SEO/GEO Workbench` for readiness checks, search snapshots, AI visibility records, competitor briefs, internal-link opportunities, redirect rules, 404 logs, and image SEO coverage.
- Added Organization, FAQPage, and ItemList structured-data helpers; article pages emit FAQPage when clear FAQ pairs are detected.
- Added image SEO metadata columns; new uploads and URL-import images now receive alt/caption/SEO filename metadata.
- Added `ROADMAP.md` and `docs/seo-geo-audit-checklist.md` to document implemented capability, next steps, and launch audit boundaries.

### Open-source downstream release prep

- Added origin and downstream-development notes while preserving the upstream Apache-2.0 license, `LICENSE`, `NOTICE`, and source author attributions.
- Added `OPEN_SOURCE_RELEASE.md` with the public release boundary, downstream change list, and pre-push checklist.
- Removed fixed default admin password references from public docs and the legacy bootstrap path; use `INITIAL_ADMIN_PASSWORD` or the generated random initial password instead.
- Updated the admin footer and welcome panel to distinguish upstream source, current repository, and downstream maintenance.
- Added GitHub Actions CI and an open-source release audit covering PHP syntax, Tailwind build, guardrail tests, dependency audit, and sensitive-content scanning.

## 2026-04-18

### v1.2

- Added first-stage Chinese/English interface support:
  - English is now available across the formal admin pages
  - The login page now has its own language selector
  - The frontend shell follows the admin language selection
- Added `Smart Model Failover` for tasks:
  - Tasks can now use `Fixed Model` or `Smart Failover`
  - When the primary model fails, GEOFlow automatically tries the next available chat model by priority
- Improved provider endpoint handling:
  - Supports versioned chat and embedding endpoints for OpenAI, DeepSeek, MiniMax, Zhipu GLM, and Volcengine Ark
  - Model settings now accept either a base URL or a full endpoint
- Improved task execution behavior:
  - `task-execute.php` now queues execution instead of blocking the page synchronously
  - `published_count` is now updated correctly for tasks that publish directly
- Added frontend theme preview and activation:
  - dynamic `preview/<theme-id>` routes for safe preview-first inspection
  - theme package support under `themes/<theme-id>`
  - admin-side theme preview and activation in Site Settings
  - sample theme `qiaomu-editorial-20260418` is now included in the public repository
  - homepage, category, and archive card summaries now strip Markdown artifacts before rendering
- Added an admin first-login welcome panel:
  - shown automatically after the first admin login
  - redesigned as a single welcome letter instead of a multi-card module layout
  - defaults to Chinese with an in-panel English switch
  - footer now includes a `Project Intro` entry that reopens the panel
  - implementation notes are documented in `project/ADMIN_WELCOME_en.md`
- Added the companion `geoflow-template` skill entry:
  - maps reference URLs into GEOFlow-compatible theme packages
  - outputs `tokens.json`, `mapping.json`, and preview-first theme plans
- Upgraded default GEO prompt templates:
  - Long-form templates now cover article generation, ranking articles, keywords, and descriptions
  - Templates are aligned with GeoFlow's variable rules
- Fixed multiple admin usability issues:
  - PostgreSQL timezone drift
  - Missing leading `/` in generated image paths
  - PostgreSQL boolean write error when saving AI-generated titles
  - Default provider examples now use a neutral DeepSeek sample instead of the old third-party domain
