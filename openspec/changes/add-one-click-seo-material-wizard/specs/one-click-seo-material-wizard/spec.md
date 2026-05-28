## ADDED Requirements

### Requirement: Admin can generate a current-site SEO material pack
The system SHALL let an authenticated admin generate title, image, and knowledge-base materials for the currently selected site from one beginner-friendly page.

#### Scenario: Generate material pack
- **WHEN** an admin opens the SEO material wizard and submits the default form
- **THEN** the system creates one current-site title library, one current-site image library, and one current-site AI knowledge base

#### Scenario: Existing keyword context is available
- **WHEN** the selected site has keyword libraries, categories, site settings, or existing articles
- **THEN** the generated materials use that context instead of generic placeholder copy

### Requirement: Material pack generation is safe by default
The system SHALL avoid DataForSEO requests during material pack generation and SHALL still complete when no chat AI model is configured.

#### Scenario: No AI model exists
- **WHEN** the admin generates a material pack without an active chat AI model
- **THEN** the system uses local deterministic SEO templates and reports that no AI call was made

#### Scenario: AI model fails
- **WHEN** an active chat AI model returns invalid JSON or fails
- **THEN** the system falls back to local deterministic SEO templates and still creates the pack

### Requirement: Generated images are usable assets
The system SHALL create image records with real local files and SEO metadata rather than only storing text prompts.

#### Scenario: Image pack is created
- **WHEN** the wizard generates image materials
- **THEN** each image row has a file path, alt text, caption, and SEO filename where the database supports those fields

### Requirement: Generated knowledge is retrievable
The system SHALL sync generated knowledge-base content into existing retrieval chunks.

#### Scenario: Knowledge base is created
- **WHEN** the wizard creates a knowledge base
- **THEN** the system runs the existing knowledge chunk sync for that knowledge base
