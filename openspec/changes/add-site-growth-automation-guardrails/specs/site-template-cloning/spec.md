## ADDED Requirements

### Requirement: Clone reusable site setup
The system SHALL allow a super admin to create a new site by cloning reusable configuration from an existing site.

#### Scenario: Clone from source site
- **WHEN** a super admin submits a clone request with a source site and new site name
- **THEN** the system creates a new active site and copies site settings, categories, authors, keyword libraries with keywords, title libraries with titles, and knowledge bases into the new site scope.

#### Scenario: Reject conflicting domain
- **WHEN** the clone request contains a primary or alias domain already assigned to another site
- **THEN** the system rejects the clone without creating partial data.

#### Scenario: Do not clone runtime history
- **WHEN** a site is cloned
- **THEN** the system MUST NOT clone articles, tasks, job queue records, URL import history, spend logs, or IndexNow queue records.
