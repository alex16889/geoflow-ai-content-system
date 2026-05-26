## ADDED Requirements

### Requirement: Show site SEO/GEO readiness
The admin UI SHALL provide a workbench that audits the current site's SEO/GEO readiness.

#### Scenario: Admin opens the workbench
- **WHEN** an authenticated admin opens the SEO/GEO workbench
- **THEN** the page shows a readiness score, grouped checks, discovery URLs, search submission provider status, content counts, and recommended actions.

#### Scenario: Missing required configuration
- **WHEN** a site lacks a public domain, sitemap, LLM file, quality gate, or search provider configuration
- **THEN** the workbench marks the check as warning or fail and explains the next action.

#### Scenario: Super admin switches sites
- **WHEN** the admin changes the current site
- **THEN** all workbench metrics and forms reflect only the selected site.

### Requirement: Avoid spending API budget during audits
The workbench SHALL compute readiness using local data and configuration by default.

#### Scenario: Audit refresh
- **WHEN** the admin refreshes the page
- **THEN** the system does not call DataForSEO, Search Console, Bing Webmaster, or AI-answer APIs automatically.
