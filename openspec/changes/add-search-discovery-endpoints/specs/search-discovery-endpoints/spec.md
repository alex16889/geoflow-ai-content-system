## ADDED Requirements

### Requirement: Public sitemap is site-scoped
The system SHALL serve a `sitemap.xml` endpoint that lists only public URLs for the currently resolved site.

#### Scenario: Sitemap includes published content only
- **WHEN** a crawler requests `/sitemap.xml`
- **THEN** the response is XML and includes homepage, category, archive, and published article URLs for the current site

#### Scenario: Sitemap excludes private content
- **WHEN** draft, deleted, private, admin, preview, or search URLs exist
- **THEN** the sitemap does not list those URLs

### Requirement: Public robots policy advertises sitemap
The system SHALL serve a `robots.txt` endpoint that advertises the current site's sitemap and blocks non-public operational routes.

#### Scenario: Robots response includes sitemap
- **WHEN** a crawler requests `/robots.txt`
- **THEN** the response is plain text and contains the absolute sitemap URL for the current site

#### Scenario: Robots blocks admin and duplicate routes
- **WHEN** robots rules are generated
- **THEN** admin, API, preview, and search routes are disallowed
