# Architecture Boundaries & Layer DAG

- `axiomasoft/packman-monorepo` consists of 8 packages arranged in a strictly acyclic 4-layer DAG (`L1 -> L2 -> L3 -> L4`) declared in `packman.json`:
  - `L1` (`kernel`, profile `php-library`): `axiomasoft/packman` (`packages/core`, `Packman\`)
  - `L2` (`addon`, profile `laravel-package` / `modular-platform`): `axiomasoft/packman-schema` (`packages/schema`), `axiomasoft/packman-plugins` (`packages/plugins`), `axiomasoft/packman-modules` (`packages/modules`), `axiomasoft/packman-panels` (`packages/panels`)
  - `L3` (`integration`, profile `laravel-package`): `axiomasoft/packman-filament` (`packages/filament`)
  - `L4` (`dev`, `dev_only: true`): `axiomasoft/packman-testkit` (`packages/testkit`), `axiomasoft/packman-devtools` (`packages/devtools`)
- Never import upward across layers, never depend on `dev_only` packages from runtime `require`, and never import another package's `\Internal\` namespace (`PackageGraphGate`, `OwnershipGate`).
- `packages/core` (`php-library`) must never require `laravel/framework` or `illuminate/*` in `composer.json` `require` (`ModuleLayoutGate`). Pure PHP surfaces (`Attributes`, `Context`, `Errors`, `Events`, `Features`, `Settings`, `Extensions`, `Config/{Contracts,Data,Exceptions,Internal}`, `Doctor/{Contracts,Data,Enums,Internal}`) must execute with zero Laravel runtime dependencies.
- Keep `packages/`, `schemas/`, `ai/`, `bin/`, `tests/`, `fixtures/`, `docs/architecture/`, and `docs/reference/` completely agnostic of any downstream product or platform name.
