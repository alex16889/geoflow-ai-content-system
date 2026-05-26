## Why

GEOflow's keyword libraries are still mostly manual, which blocks the long-term goal of one-click boutique-site setup. DataForSEO is now funded and can provide live search-volume-backed keyword candidates, but it must be integrated with strict spend limits and without storing API credentials in the database or repository.

## What Changes

- Add a DataForSEO keyword research service that reads credentials only from environment variables.
- Add an admin keyword research page under Materials for connection testing and limited keyword imports.
- Import suggested keywords into the currently selected site's keyword library with duplicate protection.
- Store lightweight keyword metrics when available: source, search volume, CPC, competition, monthly searches, and metrics timestamp.
- Pass DataForSEO environment variables through production containers without hardcoding secret values.

## Capabilities

### New Capabilities

- `dataforseo-keyword-import`: Admins can test DataForSEO access and import limited keyword suggestions into site-scoped keyword libraries.

### Modified Capabilities

- None.

## Impact

- Affected code: `includes/dataforseo_service.php`, keyword library helpers/pages, admin navigation, database compatibility schema, and Docker Compose production environment.
- Affected external API: DataForSEO Basic Auth, `appendix/user_data`, and Google keyword suggestions live endpoint.
- Affected database: `keywords` receives optional metric columns; existing keyword text and library ownership remain compatible.
- Affected deployment: requires setting `DATAFORSEO_LOGIN` and `DATAFORSEO_PASSWORD` in the production environment and rebuilding the PHP container.
