## Context

The current discovery layer has dynamic `sitemap.xml`, `robots.txt`, and an IndexNow queue. The queue schema already has a `provider` column, so the stable path is to generalize queueing/submission rather than create separate tables or new workers.

Search engine API support is uneven. IndexNow is explicitly designed to fan out verified URL notifications among participating engines. Bing also has a Webmaster URL Submission API. Google's Indexing API is not a general article submission API, so integrating it for generic GEO/content pages would be unstable and potentially misleading. Baidu URL submission is useful for Chinese search coverage, but the safest admin UX is to let the user paste the endpoint/token URL from Baidu's platform rather than derive it from assumptions.

## Goals / Non-Goals

**Goals:**

- Let each site enable/disable IndexNow, Bing URL Submission, and Baidu URL submit independently.
- Display the current site's sitemap and robots endpoints in website settings.
- Queue article URLs for all enabled providers on publish/update.
- Process each provider's pending queue from the existing scheduler.
- Keep localhost/private hosts from being submitted to external services.

**Non-Goals:**

- Google generic URL push for normal content pages.
- OAuth flows for Bing or Google in this iteration.
- Third-party paid indexer integrations.
- Guaranteed indexing or ranking outcomes.

## Decisions

1. Add `SearchSubmissionService` as an orchestration layer.
   - Rationale: IndexNow remains provider-specific, while queue fan-out and provider selection belong in a general service.

2. Reuse `url_indexing_queue`.
   - Rationale: the existing unique `(site_id, provider, url)` index already fits multi-provider queueing.

3. Store provider settings as site settings.
   - Rationale: discovery setup is per-site, and the site settings UI already owns site-level SEO configuration.

4. Keep Google as guidance only.
   - Rationale: the project is generating article/content sites, and Google Indexing API is restricted to specific page types rather than generic content pushes.

5. For Baidu, store the full API endpoint URL.
   - Rationale: Baidu accounts expose endpoint/token combinations in the platform UI; pasting the endpoint reduces mismatch and avoids storing separate token parsing rules.

## Risks / Trade-offs

- Bing API key is user-level, not site-level → document that the site must be verified in Bing Webmaster Tools and use masked admin inputs.
- Baidu endpoint availability depends on the user's verified site and quota → validate endpoint shape but avoid promising indexing.
- Queueing disabled providers would cause surprise submissions after toggling settings → queue only currently enabled providers on publish/update.
- Provider failures should not block publishing → update provider queue rows independently and keep publish flow non-blocking.

## Migration Plan

1. Add tests for provider selection, queue fan-out, and provider base URL eligibility.
2. Implement `SearchSubmissionService` and keep `IndexNowService` focused on the protocol.
3. Replace direct article publish queue calls with `SearchSubmissionService::queueArticle()`.
4. Extend scheduler processing from IndexNow-only to all provider queues.
5. Add settings UI for sitemap URLs and provider tokens.
6. Validate locally, deploy with backup, and verify production health plus queue behavior without real external submissions.
