## ADDED Requirements

### Requirement: Serve site-scoped LLM discovery files
The system SHALL serve dynamic `/llms.txt` and `/llms-full.txt` for the current site.

#### Scenario: Summary file requested
- **WHEN** a public visitor requests `/llms.txt`
- **THEN** the response is plain text and includes the current site name, description, canonical base URL, sitemap URL, important public sections, and selected recent published articles.

#### Scenario: Full file requested
- **WHEN** a public visitor requests `/llms-full.txt`
- **THEN** the response is plain text and includes expanded site guidance, categories, published article links, structured data notes, and indexing guidance.

#### Scenario: Multi-site host resolution
- **WHEN** the same runtime serves two configured domains
- **THEN** each domain's LLM file output uses that domain's site settings and content only.

### Requirement: Keep AI discovery deployable
The system SHALL include the dynamic LLM endpoints in Docker/Nginx runtime packaging.

#### Scenario: Container image built
- **WHEN** the production image is built
- **THEN** the LLM entrypoint and supporting service files are present and reachable through the web container.
