## Why

GEOflow already generates dynamic `sitemap.xml` and can submit changed URLs through IndexNow, but a multi-site精品站 system should let each site choose which discovery channels to use. Alex needs a practical backend where each site can expose its sitemap, fill its own search-engine tokens, and choose stable official submission providers without hardcoding one channel.

## What Changes

- Add a site-level search submission settings surface that shows sitemap and robots URLs.
- Keep IndexNow as the recommended universal push channel for participating search engines.
- Add optional Bing Webmaster URL Submission API support using the site's Bing API key.
- Add optional Baidu URL submit support by letting the site owner paste the Baidu Search Resource Platform API endpoint/token URL.
- Queue changed article URLs for every enabled provider, then let the scheduler submit each provider independently.
- Do not add Google generic URL push because Google's Indexing API is restricted to specific structured page types; expose Google as sitemap/Search Console guidance instead.
- Keep tokens out of code, OpenSpec, and Obsidian; store only user-entered site settings.

## Capabilities

### New Capabilities

- `multi-provider-url-submission`: Queue and submit changed URLs through selected official/provider-supported channels.
- `search-discovery-settings`: Let each site configure discovery providers and see sitemap/robots endpoints.

### Modified Capabilities

- None.

## Impact

- Affected admin surfaces: `admin/site-settings.php`.
- Affected services: article publish queueing, scheduler URL submission processing, IndexNow key serving.
- Affected schema: existing `url_indexing_queue.provider` is reused; no new tables required.
- Affected runtime: PHP/web/scheduler/worker images must include the new search submission service.
