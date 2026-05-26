## ADDED Requirements

### Requirement: Score articles before publishing
The system SHALL calculate an article quality score and issue list before an article is published.

#### Scenario: Publish eligible article
- **WHEN** an article meets the current site's minimum quality threshold
- **THEN** the system allows the article to be published and stores its score and checked time.

#### Scenario: Block low-quality automatic publish
- **WHEN** automatic publishing attempts to publish an article below the current site's quality threshold
- **THEN** the system keeps the article in draft or review state and stores the blocking issues.

#### Scenario: Manual review visibility
- **WHEN** an article has quality issues
- **THEN** the admin UI can show the score and issues so the article can be improved before publishing.
