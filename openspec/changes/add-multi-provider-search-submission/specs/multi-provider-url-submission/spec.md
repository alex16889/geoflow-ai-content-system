## ADDED Requirements

### Requirement: Queue URLs for selected providers
The system SHALL queue changed public article URLs for each enabled search submission provider for the article's site.

#### Scenario: Multiple providers enabled
- **WHEN** a published article URL changes and IndexNow plus Bing are enabled
- **THEN** the URL is queued once for `indexnow` and once for `bing`.

#### Scenario: Provider disabled
- **WHEN** a provider is disabled for the current site
- **THEN** the system MUST NOT create new queue rows for that provider.

#### Scenario: Local host protection
- **WHEN** the current site resolves to localhost, 127.0.0.1, a private IP, or an empty host
- **THEN** the system MUST NOT submit queued URLs to external providers.

### Requirement: Submit each provider independently
The scheduler SHALL process pending or failed URL submissions per site and provider without blocking other providers.

#### Scenario: Provider succeeds
- **WHEN** an enabled provider accepts a batch
- **THEN** the matching queue rows are marked `submitted`.

#### Scenario: Provider fails
- **WHEN** a provider rejects or fails a batch
- **THEN** the matching queue rows are marked `failed`, attempts are incremented, and the error is recorded.

#### Scenario: Another provider can continue
- **WHEN** one provider fails and another provider is configured correctly
- **THEN** the successful provider still submits its own queue rows.
