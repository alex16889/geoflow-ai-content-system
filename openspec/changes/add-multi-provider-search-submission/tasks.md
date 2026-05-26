## 1. Tests And Specs

- [x] 1.1 Add tests for provider selection, queue fan-out, and public-host protection
- [x] 1.2 Validate OpenSpec artifacts

## 2. Services

- [x] 2.1 Add SearchSubmissionService provider orchestration
- [x] 2.2 Add Bing URL Submission and Baidu endpoint submission helpers
- [x] 2.3 Keep IndexNow key serving and submission compatible

## 3. Workflow Wiring

- [x] 3.1 Replace direct IndexNow queue calls on article publish paths
- [x] 3.2 Extend scheduler processing to all supported providers
- [x] 3.3 Add website settings UI for sitemap/robots and provider tokens

## 4. Validation And Deployment

- [x] 4.1 Run local tests, PHP syntax checks, Tailwind build, npm audit, and OpenSpec validation
- [x] 4.2 Deploy to production with backup and verify health/endpoints/queue behavior
- [x] 4.3 Update Obsidian project record without secrets
