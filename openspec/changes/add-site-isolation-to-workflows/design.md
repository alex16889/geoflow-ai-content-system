## Context

GEOflow is moving from a single-site content generator to a shared backend for multiple boutique sites. The first phase created `sites`, `site_domains`, and `site_id` fields, but several workflows still used global IDs. That creates a cross-site risk: an admin could select a title library, category, author, or task from another site, and a worker could generate an article without the correct site context.

## Goals / Non-Goals

**Goals:**

- Keep the existing admin and service APIs usable while enforcing site ownership internally.
- Make task and article workflows safe for multiple sites sharing one database.
- Ensure worker generation uses the task's site, not the current request host or default site.
- Keep global configuration such as prompts and AI models shared.

**Non-Goals:**

- Do not add per-user site permissions in this phase.
- Do not add cross-site shared content libraries yet.
- Do not split sites into separate schemas or databases.
- Do not change public routing beyond using the existing site context.

## Decisions

1. Use application-level ownership checks before writes and mutations.

   Rationale: the current app uses one database account across admin, worker, and scheduler paths. Adding PostgreSQL row-level security would require a broader connection/session design. Application checks are the smallest safe step for this phase.

   Alternative considered: enable database RLS immediately. Rejected for now because every connection path would need reliable session variables before queries, which is too large for this iteration.

2. Add a runtime site override for workers and CLI generation.

   Rationale: request-host lookup works for front-end pages, and session selection works for admin pages, but workers do not have either. The worker must derive site context from the task being executed.

   Alternative considered: pass site IDs manually through every generation function. Rejected because it would create a broad signature change and leave older call paths easy to miss.

3. Treat prompts and AI models as global configuration.

   Rationale: these are operational settings, not site-owned content assets. Keeping them global reduces duplicated maintenance while content-facing assets remain isolated.

4. Fail closed on cross-site references.

   Rationale: if a task references a category, author, title library, image library, or knowledge base from another site, generation should stop with a clear error instead of silently falling back to global defaults.

## Risks / Trade-offs

- [Risk] A legacy page that was not part of this phase may still query globally. -> Mitigation: the critical task, article, material, title generation, URL import, dashboard, and service-layer paths are now scoped; remaining low-traffic pages should be audited in the next optimization pass.
- [Risk] Existing data may contain null `site_id` values after manual imports. -> Mitigation: schema bootstrap backfills defaults, and service writes now set `site_id` when the column exists.
- [Risk] Future cross-site shared libraries will need exceptions to the current strict ownership checks. -> Mitigation: this phase intentionally excludes shared libraries; add an explicit `scope` or linking table later instead of weakening the default checks.
- [Risk] Browser-level admin workflows can still hide UI issues that syntax tests miss. -> Mitigation: remote HTTP health, container health, and two-site service validation are required for this phase; manual browser checks remain a follow-up for deeper UX verification.

## Migration Plan

1. Apply code changes locally and run PHP syntax checks across `admin`, `includes`, `api`, and `bin`.
2. Run a two-site PostgreSQL fixture to verify cross-site references are rejected and new task/article records inherit the selected site.
3. Create a remote backup of `/opt/geoflow` before sync.
4. Sync code while excluding production environment files and runtime upload/storage directories.
5. Rebuild `php`, `web`, `scheduler`, and `worker` containers.
6. Verify remote HTTP status, container health, and `site_id` coverage on workflow tables.

## Open Questions

- Should production delete the remaining `/opt/geoflow/.git` directory after a separate backup?
- Should cross-site shared prompt, title, image, or knowledge libraries be supported later, or should every content asset remain site-owned?
- Should external APIs get explicit site tokens/domains for multi-site automation?
