## Why

GEOflow already has multi-site publishing, quality gates, DataForSEO keyword import, dynamic sitemap/robots, and multi-provider URL submission. The next useful layer is an SEO/GEO operations suite that turns those pieces into a repeatable精品站 workflow: every site should expose AI-readable discovery files, audit its own GEO readiness, track search/AI visibility signals, and manage technical SEO issues without extra one-off scripts.

## What Changes

- Generate dynamic `/llms.txt` and `/llms-full.txt` per site, backed by site settings, categories, and published articles.
- Add an admin SEO/GEO workbench with readiness checks, scorecards, discovery URLs, provider status, and action guidance.
- Expand structured data beyond basic Article/Breadcrumb coverage with organization, FAQ, and item-list helpers.
- Add local tracking tables and admin forms for Google/Bing search performance snapshots and AI-answer visibility checks.
- Add competitor brief records for SERP/content research and future DataForSEO-backed workflows.
- Add internal-link suggestion logic based on same-site categories, tags, and recent published content.
- Add redirect rules and 404 logging so精品站 migrations can be managed without Nginx edits.
- Add image SEO metadata fields and coverage checks for uploaded/assigned images.
- Add public-case documentation that makes the project understandable as an open-source SEO/GEO system.
- Keep all external credentials and paid API secrets in environment variables or site settings only; no secrets in code, OpenSpec, or Obsidian.

## Capabilities

### New Capabilities

- `ai-discovery-files`: Generate site-scoped LLM discovery files for AI crawlers and answer engines.
- `seo-geo-workbench`: Provide an admin workbench that audits SEO/GEO readiness and shows actionable fixes.
- `visibility-tracking`: Store search performance snapshots and AI answer visibility checks per site.
- `content-research-ops`: Manage competitor briefs and internal-link suggestions for content planning.
- `technical-seo-ops`: Manage redirects, 404 logs, image SEO metadata, and structured data expansion.
- `public-case-packaging`: Document the open-source case, roadmap, and audit checklist.

### Modified Capabilities

- None.

## Impact

- Affected public endpoints: `router.php`, `/llms.txt`, `/llms-full.txt`, `robots.php`, article/category/archive pages.
- Affected admin surfaces: navigation, SEO/GEO workbench, site settings adjacency, redirect/search visibility forms.
- Affected services: new SEO/GEO audit, LLM discovery, visibility, content research, redirect, internal-link, and image SEO helpers.
- Affected schema: new site-scoped tables for visibility checks, search performance snapshots, competitor briefs, redirect rules, 404 logs; image metadata compatibility columns.
- Affected documentation: README/CASE_STUDY/ROADMAP/audit checklist and public release mirror.
