## ADDED Requirements

### Requirement: Enforce site DataForSEO budget
The system SHALL enforce a site-scoped daily DataForSEO spend limit before paid keyword imports.

#### Scenario: Allow import within budget
- **WHEN** the estimated request cost plus today's recorded spend is within the current site's daily budget
- **THEN** the system allows the DataForSEO request and records the actual cost after completion.

#### Scenario: Block import over budget
- **WHEN** the estimated request cost would exceed the current site's daily budget
- **THEN** the system rejects the import before calling DataForSEO.

#### Scenario: Unlimited budget setting
- **WHEN** the site's daily DataForSEO budget is set to 0
- **THEN** the system treats the budget as unlimited but still records actual spend.
