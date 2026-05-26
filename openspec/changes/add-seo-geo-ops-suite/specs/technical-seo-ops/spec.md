## ADDED Requirements

### Requirement: Manage redirects and 404 logs
The system SHALL provide site-scoped redirect rules and 404 logging.

#### Scenario: Active redirect matches
- **WHEN** a public request path matches an active redirect rule for the current site
- **THEN** the system responds with the configured 301 or 302 redirect and increments hit metadata.

#### Scenario: Public route not found
- **WHEN** no public route matches a request path
- **THEN** the system records a site-scoped 404 log with path, referrer, user agent, hit count, and last-seen time.

### Requirement: Improve structured data coverage
The system SHALL expose reusable helpers for Organization, FAQPage, and ItemList structured data.

#### Scenario: Article contains FAQ pairs
- **WHEN** an article includes clear FAQ-style question/answer pairs
- **THEN** the article page emits FAQPage structured data in addition to Article/Breadcrumb data.

### Requirement: Track image SEO metadata
The system SHALL support image alt text, captions, and SEO filenames.

#### Scenario: Image metadata columns missing
- **WHEN** an existing database initializes compatibility schema
- **THEN** the image metadata columns are added without deleting or renaming existing files.
