## Why

GEOflow now targets multi-site boutique SEO/GEO publishing, but it lacks dynamic discovery endpoints for crawlers. Each site needs a current sitemap and robots policy so published pages can be found without relying on manual submission.

## What Changes

- Add dynamic `sitemap.xml` output scoped to the currently resolved site.
- Add dynamic `robots.txt` output with the current site's sitemap URL.
- Route those endpoints before generic static-file fallback.
- Fix stale password-policy copy that still mentions 6 characters while the real policy requires 8+ characters and complexity.

## Capabilities

### New Capabilities

- `search-discovery-endpoints`: Public crawlers can discover per-site homepage, category, archive, and article URLs through `sitemap.xml` and `robots.txt`.

### Modified Capabilities

- None.

## Impact

- Affected code: `router.php`, new public endpoint files, and security-language strings.
- Affected SEO behavior: search engines get a site-scoped URL list and robots policy.
- Affected deployment: rebuild PHP/Web containers so the router and endpoints are available.
