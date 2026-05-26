## ADDED Requirements

### Requirement: Admin workflow resources are scoped to the selected site
The system SHALL only list, validate, create, update, start, stop, and inspect workflow resources that belong to the currently selected admin site, except for explicitly global configuration such as prompts and AI models.

#### Scenario: Cross-site task reference is rejected
- **WHEN** an admin creates or edits a task for Site A using a title library, image library, author, knowledge base, or fixed category owned by Site B
- **THEN** the system rejects the request and does not save the cross-site reference

#### Scenario: Cross-site task operation is hidden
- **WHEN** an admin selected Site A attempts to start, stop, view status for, or execute a task owned by Site B
- **THEN** the system does not operate the Site B task

### Requirement: Generation uses the task site context
The system SHALL execute AI generation under the site context stored on the task and SHALL write generated articles to that same site.

#### Scenario: Generated article inherits task site
- **WHEN** a worker executes a task owned by Site A
- **THEN** generated articles are inserted with Site A as their `site_id`

#### Scenario: Cross-site generation dependency fails closed
- **WHEN** a task owned by Site A references a category, author, title library, image library, or knowledge base owned by Site B
- **THEN** generation fails before publishing or saving an article with mixed-site data

### Requirement: Article and material workflows enforce site ownership
The system SHALL scope article create, edit, review, trash, material detail, title generation, and URL import workflows to the current site and SHALL write new records with the current site when the table supports `site_id`.

#### Scenario: Article create rejects another site's category
- **WHEN** an admin selected Site A creates or updates an article with a category owned by Site B
- **THEN** the system rejects the request and leaves the article unchanged

#### Scenario: URL import stores job site
- **WHEN** an admin selected Site A starts a URL import job
- **THEN** the created import job and any auto-created libraries or knowledge records are associated with Site A
