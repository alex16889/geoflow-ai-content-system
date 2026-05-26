## ADDED Requirements

### Requirement: DataForSEO credentials are environment-only
The system SHALL read DataForSEO API credentials from environment variables and MUST NOT require or expose the API password in admin forms, database records, or project files.

#### Scenario: Credentials are missing
- **WHEN** an admin opens the DataForSEO keyword import page without configured credentials
- **THEN** the system shows that DataForSEO is not configured and does not attempt paid keyword requests

#### Scenario: Credentials are configured
- **WHEN** DataForSEO credentials exist in the runtime environment
- **THEN** the admin can run a connection test without the password being displayed in the UI

### Requirement: Admin can import limited keyword suggestions
The system SHALL allow an authenticated admin to fetch Google keyword suggestions from DataForSEO and import them into a keyword library owned by the currently selected site.

#### Scenario: Import suggestions into selected site library
- **WHEN** an admin submits seed keywords, a target keyword library, location, language, and result limit
- **THEN** the system fetches DataForSEO keyword suggestions and inserts non-duplicate keywords into that current-site library

#### Scenario: Import creates a new current-site library
- **WHEN** an admin provides a new keyword library name instead of an existing library
- **THEN** the system creates that keyword library for the currently selected site before importing keywords

### Requirement: Keyword import is spend-safe and duplicate-safe
The system SHALL enforce application-level seed and result limits and SHALL avoid duplicate keyword rows inside a library.

#### Scenario: Request exceeds configured cap
- **WHEN** an admin requests more seeds or results than the configured maximum
- **THEN** the system caps or rejects the request before calling DataForSEO

#### Scenario: Duplicate keyword is returned
- **WHEN** DataForSEO returns a keyword that already exists in the target library
- **THEN** the system does not create a duplicate row and updates metric fields when new metrics are available

### Requirement: Imported keyword metrics are retained
The system SHALL store DataForSEO keyword metrics in nullable keyword columns when the API returns them.

#### Scenario: Metrics are returned
- **WHEN** imported suggestions include search volume, CPC, competition, or monthly search history
- **THEN** the system stores those metrics on the corresponding keyword record
