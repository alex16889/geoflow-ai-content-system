# SEO/GEO Audit Checklist

Use this checklist before launching or expanding a GEOFlow-powered精品站.

## Site Setup

- Real public domain is configured in site management.
- Canonical URLs use the public domain, not `127.0.0.1`, `localhost`, or a tunnel host.
- `sitemap.xml`, `robots.txt`, `llms.txt`, and `llms-full.txt` return `200`.
- Admin, API, preview, internal includes, and upload execution paths are blocked from crawling.

## Content Quality

- Each site has clear categories and one focused topic boundary.
- Keyword libraries include search volume, CPC, competition, or other imported metrics.
- Quality gate is enabled before publishing.
- Low-score drafts are reviewed instead of auto-published.
- Articles include useful summaries, clear headings, entity names, source links when relevant, and FAQ-style sections when useful.

## GEO / AI Search

- `llms.txt` gives AI crawlers a concise map of the site.
- `llms-full.txt` includes categories, article index, structured-data expectations, and crawl boundaries.
- Article pages emit Article, WebSite, Organization, and BreadcrumbList structured data.
- FAQPage is emitted only when the content includes clear question/answer pairs.
- AI visibility checks are recorded for important prompts across ChatGPT, Perplexity, Gemini, Claude, Grok, AI Overviews, or other target answer engines.

## Search Discovery

- IndexNow is configured when the domain is public and the key file is reachable.
- Bing URL Submission API is configured only after the site is verified in Bing Webmaster Tools.
- Baidu active push is configured only with the exact endpoint from Baidu Search Resource Platform.
- Google is handled through sitemap and Search Console rather than generic URL push.
- Provider failures are checked per provider; one failed queue should not block others.

## Technical SEO

- Redirect rules are added for migrated paths before public traffic is sent.
- Recent 404 logs are reviewed and converted into redirects or internal-link fixes when useful.
- Internal-link opportunities are reviewed after each content batch.
- Uploaded images have meaningful alt text; captions are added when useful.
- Sitemap size is monitored; use sitemap index/chunking later for large sites.

## Public Release Safety

- Public mirror is regenerated from the private source through `bin/git/prepare-open-source-release.sh`.
- Public release check passes through `bin/git/check-open-source-release.sh`.
- `.env`, uploads, logs, database dumps, backups, generated outputs, local agent files, and real secrets are not included.
- README, CASE_STUDY, NOTICE, SECURITY, and changelog explain the upstream origin and downstream changes clearly.

