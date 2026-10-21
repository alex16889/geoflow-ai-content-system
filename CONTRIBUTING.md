# Contributing

Thanks for helping maintain GEOFlow. This repository is the downstream distribution described in [README.md](README.md). Keep the upstream Apache-2.0 `LICENSE` and `NOTICE` in place when you change code.

## Development setup

1. Install PHP 8.3 with `pdo_pgsql`, `curl`, and `mbstring`, plus PostgreSQL if you need the admin UI.
2. Copy `.env.example` to `.env` and set secrets only in that untracked file.
3. For a full stack, run `docker compose --profile scheduler up -d --build`.
4. The static publisher does not need a database:

```bash
export PUBLISHER_SITES_ROOT="$PWD/data/publisher-sites"
php bin/publisher init --site example --name "Example" --base-url https://example.com
php tests/unit_publisher.php
php tests/unit_error_responses.php
```

`data/publisher-sites/` is gitignored. Do not commit generated output, ledgers, or real site content.

## Tests and CI

GitHub Actions runs syntax checks, the unit scripts under `tests/`, the open-source release audit, and the Tailwind build. Before opening a pull request, run:

```bash
php tests/unit_publisher.php
php tests/unit_error_responses.php
php tests/unit_growth_guardrails.php
php tests/unit_multisite_schema_guards.php
php tests/unit_seo_geo_ops.php
sh bin/git/check-open-source-release.sh
```

Add or extend a `tests/unit_*.php` script when you change publisher behavior, review gates, or error responses. The scripts should exit non-zero on failure and should not need network access or live credentials.

## Pull requests

- Describe the user-visible change and how you tested it.
- Update `CHANGELOG.md`, `docs/CHANGELOG.md`, and `docs/CHANGELOG_en.md` for behavior changes.
- Keep new examples on `example.com` or another reserved documentation name.
- Read secrets from environment variables. Never commit `.env`, API keys, passwords, or private hostnames.
- Do not copy code from other projects into this repository. Write new pipeline code here.
- Error responses shown to browsers must stay free of stack traces, absolute paths, and SQL driver text. Log the detail on the server instead.
- State-changing admin actions, including batch actions, need a CSRF token.

## Security reports

Please follow [SECURITY.md](SECURITY.md). Do not open a public issue for a vulnerability.
