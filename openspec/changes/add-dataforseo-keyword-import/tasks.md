## 1. DataForSEO Service

- [x] 1.1 Add environment-backed DataForSEO configuration and HTTP client wrapper
- [x] 1.2 Implement connection test through `appendix/user_data`
- [x] 1.3 Implement normalized Google keyword suggestions fetch with conservative caps

## 2. Keyword Storage

- [x] 2.1 Add nullable keyword metric columns through compatibility schema
- [x] 2.2 Add shared import helpers that dedupe and update metrics safely

## 3. Admin UI

- [x] 3.1 Add DataForSEO keyword research page under Materials
- [x] 3.2 Add links from keyword/material pages and active navigation mapping
- [x] 3.3 Show connection status, spend warning, import summary, and per-keyword metrics

## 4. Deployment And Validation

- [x] 4.1 Pass DataForSEO env vars through production containers
- [x] 4.2 Run local syntax/build/OpenSpec validation
- [x] 4.3 Deploy to production, set env vars securely, verify connection and one small import
- [x] 4.4 Update Obsidian with commands, env var names, and validation results without secrets
