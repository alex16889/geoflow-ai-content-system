## ADDED Requirements

### Requirement: Configure site discovery providers
The admin UI SHALL allow each site to configure search discovery providers independently.

#### Scenario: View sitemap endpoints
- **WHEN** an admin opens website settings
- **THEN** the UI shows the current site's sitemap and robots URLs.

#### Scenario: Configure official providers
- **WHEN** an admin enables IndexNow, Bing URL Submission, or Baidu URL submit
- **THEN** the site saves only the settings required for that provider.

#### Scenario: Preserve hidden tokens
- **WHEN** a provider token field is left blank during settings save
- **THEN** the existing token is preserved unless the admin explicitly clears it.

#### Scenario: Google generic push is unavailable
- **WHEN** the admin reviews Google discovery options
- **THEN** the UI explains that generic article URL push is not enabled and that sitemap/Search Console should be used.
