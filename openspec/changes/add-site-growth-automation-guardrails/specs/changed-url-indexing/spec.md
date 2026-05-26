## ADDED Requirements

### Requirement: Queue changed public URLs
The system SHALL record changed public URLs after article publish/update events so they can be submitted to discovery services.

#### Scenario: Article publication queues URL
- **WHEN** an article becomes published
- **THEN** the article URL is queued for the article's site with pending status.

#### Scenario: Submit eligible IndexNow URLs
- **WHEN** IndexNow is configured for a site with a non-local primary domain
- **THEN** the system submits pending URLs for that host in batches and records submitted or failed state.

#### Scenario: Skip local hosts
- **WHEN** the current site resolves to localhost, 127.0.0.1, or an empty host
- **THEN** the system MUST NOT submit URLs to IndexNow.
