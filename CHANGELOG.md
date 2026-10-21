# Changelog

User-facing changes are recorded here and in [docs/CHANGELOG.md](docs/CHANGELOG.md) / [docs/CHANGELOG_en.md](docs/CHANGELOG_en.md).

## 2026-10-21

### Static publishing pipeline

- Added a file publisher beside the database article flow: `draft → reviewed → scheduled → published`. Unreviewed items cannot be published.
- Added a cron- and systemd-friendly `php bin/publisher run --scheduled`, plus `--dry-run` / `--preview` which write nothing.
- Each site keeps its own `site.json`, templates, and `published.json` ledger. A second publish of the same item on the same local day is skipped.
- After writing, the publisher reads the output back and checks its SHA-256. On failure it restores the previous bytes and verifies that hash.
- Batch draft import supports preview, partial-failure cleanup, and idempotent retries.
- Error responses no longer include stack traces, absolute paths, or database driver details. Keyword batch delete now submits the selected ids with the CSRF token.
