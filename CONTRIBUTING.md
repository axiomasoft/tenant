# Contributing

Tenant is a public monorepo. Installable packages are `packages/core` (`axiomasoft/tenant`), `packages/laravel`, `packages/postgres`, and `packages/panels`. The root Composer project is the development workspace.

## Setup

PHP 8.4 is required, with `intl`, `pdo_pgsql`, `posix`, and `sodium`. When the default `php` is older, run commands through `bin/php84`.

```sh
./bin/php84 --check
./bin/php84 composer install
```

`composer.json` loads the sibling checkout `../packman` as path repositories pinned to the 0.1 line. CI clones `axiomasoft/packman` tag `v0.1.2` into that location and then runs `composer install` from `composer.lock`.

## Checks

```sh
./bin/php84 composer test
./bin/php84 composer test:parallel
./bin/php84 composer test:pg
./bin/php84 composer lint:check
./bin/php84 composer analyse
./bin/php84 composer refactor:check
./bin/php84 composer gate
./bin/php84 composer php-api
./bin/php84 composer error-codes
./bin/php84 composer config-schema
./bin/php84 composer security:audit
./bin/php84 composer boundaries
```

`test` and `test:parallel` skip the `pg` group. `test:pg` expects PostgreSQL on `127.0.0.1:54329`, database `tenant_test`, user `skm`, password `skm_dev`, and `PACKMAN_TEST_DESTRUCTIVE_OPT_IN=1`. Those values match `phpunit.xml` and the CI service. The database name must end in `_test`.

Python contract checks use CPython 3.12:

```sh
python3 -I -m venv .venv-contracts
.venv-contracts/bin/python -I -m pip install --require-hashes -r tools/contract-requirements.lock
.venv-contracts/bin/python -I scripts/check-contract-fixtures.py --all
.venv-contracts/bin/python -I -m unittest discover -s tests -p 'test_*.py'
```

The qualification lab under `tests/laravel`, `tests/postgres`, and `tests/consumers` is not part of CI. Follow [the lab runbook](docs/architecture/qualification-lab.md) before running it.

## Public package rules

- Keep `#[Api]`, `#[Spi]`, and `#[Internal]` surfaces accurate. Regenerate `resources/contracts/php-api.json` when a public or SPI type changes.
- Do not import another package's `Internal` namespace or add a Laravel dependency to `packages/core`.
- Dependency updates are reviewed by hand. This repository does not enable Dependabot. CI reports Composer advisories.
- Do not commit `.claude/`, `.codex/`, `.gemini/`, `AGENTS.md`, `build/`, or `review/`. This package has no canonical `ai/` pack. `vendor/bin/packman ai:sync` would copy Packman's agent pack because `packman.json` is present; those files are gitignored.

## License

The packages are MIT. See [LICENSE](LICENSE). Report vulnerabilities privately as described in [SECURITY.md](SECURITY.md).
