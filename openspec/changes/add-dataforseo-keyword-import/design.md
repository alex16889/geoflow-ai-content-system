## Context

GEOflow already has site-scoped keyword libraries and manual keyword import. The next useful automation step is to let an admin seed a boutique site with real search data from DataForSEO while keeping each selected admin site isolated. DataForSEO uses Basic Auth credentials from its dashboard and charges keyword endpoints per request, so the integration must avoid accidental high-volume calls and must not persist secrets in the repository, database, or Obsidian notes.

## Goals / Non-Goals

**Goals:**

- Provide a small service wrapper for DataForSEO user-data and keyword-suggestions endpoints.
- Let admins test connectivity and import limited suggestions into the current site's keyword library.
- Preserve existing manual keyword import behavior.
- Capture useful metrics when returned by DataForSEO without storing full raw API responses.
- Keep deployment secret handling environment-variable based.

**Non-Goals:**

- Full autonomous site creation from one click.
- Rank tracking, SERP crawling, backlink analysis, or LLM mentions APIs.
- Storing DataForSEO credentials in the admin UI.
- Bulk campaign scheduling or high-volume keyword mining in this change.

## Decisions

- Use `DATAFORSEO_LOGIN` and `DATAFORSEO_PASSWORD` environment variables only. Alternative considered: encrypted site settings. Environment variables are safer for this deployment because the credential is shared service infrastructure, not content data, and avoids database export leakage.
- Start with `google/keyword_suggestions/live`. Alternative considered: keyword ideas and keyword overview. Suggestions are best for seed-to-long-tail expansion and match the immediate need to populate libraries; other endpoints can be added later behind the same service wrapper.
- Add a hard application cap for requested results. Alternative considered: trusting the admin form value. A cap prevents accidental spend spikes and keeps early testing predictable.
- Store normalized metric columns on `keywords`. Alternative considered: raw JSON payload only. Columns keep the library useful for sorting and later content planning without bloating the database.
- Import into the selected site's existing or newly created keyword library. Alternative considered: global library import. Site-local import is required by the multi-site architecture.

## Risks / Trade-offs

- Paid API calls can be triggered repeatedly -> limit seeds per request, limit result count, show request cost when returned, and default to conservative limits.
- API credentials may be missing or reset -> expose a safe connection-test state without displaying passwords.
- DataForSEO response shapes can evolve -> normalize defensively and ignore unknown fields.
- Duplicate keywords can hide useful updated metrics -> on duplicate, update metric columns when DataForSEO returns fresher metrics.

## Migration Plan

- Add nullable metric columns to `keywords` via the existing compatibility schema path.
- Add environment-variable passthrough to Docker Compose; deploy with empty defaults first.
- Set real credentials only in production `.env.prod`.
- Verify first with the free `appendix/user_data` endpoint, then one low-limit keyword import.
- Rollback is code-only for the admin page/service; the new columns are nullable and do not affect existing manual import.

## Open Questions

- The initial default target is United States / English (`2840` / `en`). Per-site defaults can be added later if Alex wants different countries per boutique site.
