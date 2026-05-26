## Why

The multi-site foundation added site records and scoped the main list pages, but workflow paths still accepted global IDs. Before GEOflow can safely run 10-20 boutique sites from one backend, task creation, task execution, article editing, and generation must prevent cross-site data leakage.

## What Changes

- Add reusable current-site and runtime-site ownership checks for admin, worker, and CLI flows.
- Scope task create/edit option lists and reference validation to the selected admin site.
- Scope task start, stop, status, and execution endpoints so one selected site cannot operate another site's tasks.
- Run AI generation under the task's site context and write generated articles with the task `site_id`.
- Make article and task service reference validation site-aware.
- Extend URL import jobs and detail pages so imported content and generated assets stay attached to the current site.

## Capabilities

### New Capabilities

- `site-isolated-workflows`: Ensures content workflow reads, writes, validation, and execution are isolated by site.

### Modified Capabilities

- None.

## Impact

- Affected code: `includes/site_context.php`, task admin pages, task execution endpoints, `includes/ai_engine.php`, `includes/ai_service.php`, `includes/article_service.php`, `includes/task_lifecycle_service.php`, article detail/review/trash pages, material detail pages, title generation, and URL import.
- Affected database: `url_import_jobs` now participates in the same `site_id` migration pattern as the other site-scoped workflow tables.
- Affected deployment: requires rebuilding the PHP/Web/Scheduler/Worker containers so workers use the same site-isolated runtime code as the admin UI.
