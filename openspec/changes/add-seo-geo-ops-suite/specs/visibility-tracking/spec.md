## ADDED Requirements

### Requirement: Record search performance snapshots
The system SHALL store site-scoped search performance snapshots from Google Search Console, Bing Webmaster Tools, or manual imports.

#### Scenario: Manual snapshot saved
- **WHEN** an admin records a query/page performance row
- **THEN** the system saves source, date, query, URL, clicks, impressions, CTR, and average position for the current site.

#### Scenario: Site isolation
- **WHEN** two sites record the same query
- **THEN** each site's snapshots remain separated by `site_id`.

### Requirement: Track AI answer visibility
The system SHALL store AI-answer visibility checks for answer engines such as ChatGPT, Perplexity, Gemini, Claude, Grok, and AI Overviews.

#### Scenario: Visibility check saved
- **WHEN** an admin records an AI answer check
- **THEN** the system stores provider, query, brand mention, cited URL, answer excerpt, score, notes, and checked time for the current site.

#### Scenario: Mention not found
- **WHEN** the answer does not mention or cite the site
- **THEN** the system still records the check as negative evidence.
