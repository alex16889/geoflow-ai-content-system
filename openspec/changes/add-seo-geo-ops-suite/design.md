## Context

The project is now a multi-site AI content operations system. It can create sites, isolate site data, import keywords through DataForSEO with budget controls, block low-quality publishing, generate sitemap/robots, and queue submissions to IndexNow/Bing/Baidu. What is missing is an operator layer that makes SEO/GEO readiness visible and repeatable for 10-20精品站.

The implementation should stay lightweight. It should not introduce new long-running workers, OAuth flows, or extra paid APIs in this iteration. Where external systems are needed, the system should provide storage and workflow hooks first, then allow future API automation.

## Goals / Non-Goals

**Goals:**

- Serve dynamic, site-scoped `llms.txt` and `llms-full.txt`.
- Give admins one SEO/GEO workbench per current site.
- Add practical, local tracking for search performance and AI-answer visibility.
- Add technical SEO controls that are useful during site migration and cleanup.
- Improve schema and image metadata coverage without changing the public theme architecture.
- Keep Docker/Nginx packaging compatible with the new public endpoints.
- Keep the open-source release clean and explain the project as an SEO/GEO case.

**Non-Goals:**

- Google Search Console OAuth implementation in this iteration.
- Automated ChatGPT/Perplexity/Gemini scraping or paid AI answer monitoring.
- Full SERP crawling beyond existing DataForSEO keyword import.
- Replacing Nginx-level production controls; app-level redirects are for site content paths.
- Guaranteed indexing, citation, ranking, or AI answer inclusion.

## Decisions

1. Add small service files instead of folding everything into existing pages.
   - Rationale: the workbench should compose discovery, audit, tracking, and technical SEO helpers without making `admin/site-settings.php` too large.

2. Route `llms.txt` and `llms-full.txt` through PHP before static file fallback.
   - Rationale: static files are useful fallback for the public repo, but a multi-site runtime needs host-aware output.

3. Store search and AI visibility as manual/CSV-friendly snapshots first.
   - Rationale: API access varies by account and engine; tracking schema and UI are useful immediately and can be automated later.

4. Keep redirect rules site-scoped and path-based.
   - Rationale: content migrations usually need `/old-path -> /new-path`; host-wide TLS/Nginx concerns stay outside the app.

5. Compute the GEO audit score locally.
   - Rationale: the score should work offline and avoid spending API budget; it should guide operators rather than claim absolute ranking value.

6. Add image metadata compatibility columns without forcing file renames.
   - Rationale: renaming uploaded assets can break references; metadata coverage is safer than destructive file moves.

## Data Model

- `search_performance_snapshots`: site, source, date, query, page URL, clicks, impressions, CTR, average position.
- `ai_visibility_checks`: site, provider, query, brand mention flag, cited URL, answer excerpt, score, notes, checked time.
- `competitor_briefs`: site, seed keyword, competitor URL/title, notes, brief JSON.
- `redirect_rules`: site, source path, target URL/path, status code, active flag, hit counters.
- `not_found_logs`: site, path, referrer, user agent, hit counters, first/last seen.
- `images`: compatibility columns `alt_text`, `caption`, and `seo_filename`.

## Runtime Flow

1. Public request enters `router.php`.
2. Redirect service checks an active site-scoped redirect rule for the requested path.
3. Known dynamic endpoints route to PHP entrypoints, including `sitemap.xml`, `robots.txt`, `llms.txt`, and `llms-full.txt`.
4. If no public route matches, the router logs a site-scoped 404 and renders the standard not-found page.
5. Admins use the SEO/GEO workbench to view the current site's readiness, record visibility snapshots, create competitor briefs, manage redirects, and review image/technical SEO gaps.

## Risks / Trade-offs

- App-level redirect checks add a small DB lookup to uncached dynamic requests; keep the query indexed by `(site_id, source_path, is_active)`.
- Manual visibility tracking is less automated than OAuth/API integrations, but it is stable and does not block users without verified accounts.
- `llms.txt` is an emerging convention, not a formal search engine standard; keep it as an AI-crawler aid, not a ranking promise.
- Schema extraction from content can be imperfect; FAQ schema should only emit when clear question/answer pairs are found.
- The workbench score can be gamed if treated as a vanity metric; display concrete checks and missing items, not just a single score.

## Migration Plan

1. Add OpenSpec specs and task list.
2. Add database compatibility schema for new SEO/GEO tables and image metadata columns.
3. Implement services for LLM files, audit, visibility tracking, content research, redirects, internal links, and image metadata.
4. Add public routing and Docker/Nginx copy/rewrite support.
5. Add admin workbench and navigation strings.
6. Extend structured data on article and listing pages.
7. Add unit checks where practical, then run PHP syntax, guardrail tests, Tailwind build, npm audit, and OpenSpec validation.
8. Update documentation, prepare the clean public mirror, run the release check, and push to GitHub.
