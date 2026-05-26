## Context

The frontend already emits canonical URLs and Schema.org blocks. However, a multi-site content system also needs machine-readable discovery endpoints per host/domain. GEOflow resolves the current site from host or admin selection, so public discovery should use that same runtime context and only include published, non-deleted content.

## Goals / Non-Goals

**Goals:**

- Serve `sitemap.xml` and `robots.txt` without static files per site.
- Include accurate URLs and last modification timestamps where the database has them.
- Keep the implementation read-only and cheap.

**Non-Goals:**

- IndexNow push automation.
- Google Search Console or Bing API integration.
- Sitemap indexes for very large sites; the first version caps output to a safe page count.

## Decisions

- Generate endpoints dynamically through PHP rather than static files. This keeps host-based multi-site routing correct without writing files per domain.
- Include homepage, categories, monthly archives, and published articles. This matches the current route surface and avoids private/admin/preview URLs.
- Use database `updated_at`, `published_at`, or `created_at` as `lastmod` only when available. This avoids fake timestamps.

## Risks / Trade-offs

- Very large sites can exceed single sitemap limits -> cap first version and later add sitemap indexes when content volume grows.
- Public search pages can create thin or duplicate URLs -> disallow `/search/` in robots and omit search URLs from sitemap.
