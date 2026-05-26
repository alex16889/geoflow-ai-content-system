## 1. Tests And Schema

- [x] 1.1 Add failing tests for quality scoring, spend guardrails, and IndexNow URL eligibility
- [x] 1.2 Add schema guards for article quality, URL indexing queue, and site API spend ledger

## 2. Core Services

- [x] 2.1 Implement article quality gate service
- [x] 2.2 Implement site spend guardrail service
- [x] 2.3 Implement IndexNow changed-URL queue service
- [x] 2.4 Implement site template clone service

## 3. Admin And Workflow Wiring

- [x] 3.1 Add clone action to site management
- [x] 3.2 Add quality, IndexNow, and budget settings to website settings
- [x] 3.3 Enforce DataForSEO budget in keyword import
- [x] 3.4 Enforce quality gates and queue changed URLs on publish paths
- [x] 3.5 Add scheduler processing for pending IndexNow URLs

## 4. Validation And Deployment

- [x] 4.1 Run local tests, PHP syntax checks, Tailwind build, npm audit, and OpenSpec validation
- [x] 4.2 Deploy to production with backup and verify health/endpoints/schema
- [x] 4.3 Update Obsidian project record without secrets
