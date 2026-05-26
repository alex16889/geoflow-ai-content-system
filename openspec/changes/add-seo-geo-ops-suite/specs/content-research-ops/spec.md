## ADDED Requirements

### Requirement: Store competitor content briefs
The system SHALL let admins save site-scoped competitor briefs for SERP/content research.

#### Scenario: Competitor brief saved
- **WHEN** an admin enters a seed keyword, competitor URL/title, and notes
- **THEN** the brief is saved for the current site and appears in the workbench.

### Requirement: Suggest internal links
The system SHALL compute internal-link suggestions from same-site published content.

#### Scenario: Article suggestions requested
- **WHEN** the system evaluates a published article
- **THEN** it suggests related same-site articles using category, tags, and recency signals.

#### Scenario: Cross-site content exists
- **WHEN** another site has a stronger related article
- **THEN** the system MUST NOT suggest it for the current site.
