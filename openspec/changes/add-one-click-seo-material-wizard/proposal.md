## Why

GEOflow is moving from expert-only SEO configuration toward a foolproof boutique-site workflow. The current Materials page still requires admins to manually create title libraries, image libraries, and AI knowledge bases after keyword import, which blocks non-SEO users from getting a site ready for content generation.

## What Changes

- Add a one-click SEO material wizard for the currently selected site.
- Build a site-context snapshot from site name, title, description, configured keywords, categories, and existing articles.
- Generate title ideas, image素材 cards, and an AI knowledge base from that context.
- Use the configured chat AI model when available, with deterministic local generation as a safe fallback.
- Keep all generated materials site-scoped and immediately usable by existing task creation flows.

## Capabilities

### New Capabilities

- `one-click-seo-material-wizard`: Admins can generate a complete SEO starter material pack for the current site from one page.

### Modified Capabilities

- `materials-dashboard`: Materials gets a clear "SEO素材助手" entry so non-SEO users do not need to know which library to create first.

## Impact

- Affected code: `admin/materials.php`, `admin/seo-material-wizard.php`, `admin/includes/seo-material-wizard-helpers.php`, admin navigation mapping.
- Affected database: inserts into existing title, image, and knowledge-base tables only; no schema migration required.
- Affected files: generated SVG images stored under `uploads/images/seo-wizard/`.
- Affected external API: optional chat AI model call only when an active model exists; no DataForSEO calls and no extra paid keyword requests.
