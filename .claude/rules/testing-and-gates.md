# Testing & Quality Gates

- Run PHP 8.4+ commands through `./bin/php84` when present in the workspace, or ensure `php` 8.4+ is on `PATH`.
- Keep SQLite (`composer test`) and PostgreSQL (`composer test:pg`) suites passing. `TestDatabaseGuard` rejects any PostgreSQL database name not ending in `_test` and requires `PACKMAN_TEST_DESTRUCTIVE_OPT_IN=1` for destructive resets.
- Use `Packman\Testkit\PackageTestCase` and conformance traits (`PluginContractTests`, `ModuleContractTests`, `AssertsRegisteredErrors`, `IsolatesDatabasePath`, `UsesSuiteDatabase`, `Concurrency`) when writing package tests.
- Never suppress static analysis with inline `@phpstan-ignore` in non-dev `src/`, never leave `dd()`, `dump()`, `var_dump()`, `extract()`, `compact()`, or `eval()` in `src/` (`QualityCeilingGuard`), and verify:
  - `composer lint:check`
  - `composer analyse`
  - `composer refactor:check`
  - `composer gate`
