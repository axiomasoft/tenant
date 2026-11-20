# Tenant

[![CI](https://github.com/axiomasoft/tenant/actions/workflows/ci.yml/badge.svg)](https://github.com/axiomasoft/tenant/actions/workflows/ci.yml)

Tenant provides one isolation runtime shared by application packages. A host selects a logical tenant; the runtime verifies the binding required by the selected profile, activates facilities, runs application code, and restores the previous frame. Authorization, commercial policies and product identities remain with their owners.

## Packages

| Package | Responsibility | Install when |
| --- | --- | --- |
| [`axiomasoft/tenant`](packages/core/README.md) | Portable identities, references, composition, runtime and errors | Every integration; PHP and Packman only |
| [`axiomasoft/tenant-laravel`](packages/laravel/README.md) | Context for Eloquent consumers, cache/session/storage, HTTP and queues; optional guarded database switching | The host uses Laravel |
| [`axiomasoft/tenant-postgres`](packages/postgres/README.md) | Physical PostgreSQL ownership, provisioning, binding verification and recovery | This package owns the physical resource lifecycle |
| [`axiomasoft/tenant-panels`](packages/panels/README.md) | Optional Packman panel entry inside the same runtime | The host connects features through panels |

Install `axiomasoft/tenant-postgres` when the host asks Tenant to clone PostgreSQL templates, allocate pooled databases, verify physical bindings or maintain lifecycle receipts and recovery archives. A host that owns its database connections can use `axiomasoft/tenant-laravel` with the `laravel-scoped` profile.

The adapters depend on the core. They do not require each other. Panels do not require Filament, the Laravel adapter or the PostgreSQL owner. Installing the core does not install a framework or a database owner.

The packages are released under the [MIT license](LICENSE). Report vulnerabilities privately; see [SECURITY.md](SECURITY.md). Contributor setup and the CI contract are in [CONTRIBUTING.md](CONTRIBUTING.md). The documentation map is [docs/README.md](docs/README.md).

PHP 8.4–8.5 is declared by the packages. Laravel adapters declare Illuminate 12–13 support; the full qualification matrix is separate from the local development suite.

## Integration contract

Bind one `Tenant\Contracts\Runtime\TenantRuntime` per isolated execution environment. Laravel's provider constructs that runtime from a host `ContextResolver` and the compiled composition. The database isolation profile additionally requires a `BindingVerifier`. Other packages read the runtime or participate in its composition; they must not keep a second mutable current tenant.

Choose `laravel-scoped` for host-owned Eloquent connections and consumer-owned data isolation. It does not require PostgreSQL, a physical binding verifier or a database provisioner. Choose `laravel-pg-database` when Tenant must switch and guard a PostgreSQL database per resource. The existing database profile remains the default for compatibility; select the scoped profile explicitly. See [Laravel profiles](docs/architecture/laravel-profiles.md).

- `missing` refuses tenant operations. It is never an implicit central context.
- `central` is an explicit selection for named control-plane facilities.
- `active` carries a resolved tenant or single-installation identity, profile and resource binding metadata. Physical binding verification depends on the selected profile.
- Logical tenant identity, authorization scope, product selection and physical resource are separate concepts. Host adapters map them explicitly, preserving namespaces and refusing unknown mappings.
- Durable jobs capture a scalar `ContextReference` with incarnation and route revision. Workers revalidate it; they never redirect a stale job to the current tenant.
- Facilities are compiled before use, with explicit capabilities, ordering and replacements. Cleanup follows the reverse activation journal, including partial failures.
- A failed restoration poisons the runtime. HTTP and queue boundaries stop the worker; application code must not catch the error and resume tenant work.

See the [integration guide](docs/integration.md) for extension points and the [versioned contracts](docs/architecture/contracts.md) for the full protocol.

## Development checks

The development workspace uses a sibling `../packman` checkout through Composer path repositories. Package consumers install the individual packages using their normal Composer repository.

```sh
./bin/php84 --check
./bin/php84 composer install
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

`test` runs core, provider, installation, panel and self-contained Laravel regressions. `test:parallel` runs that suite in parallel processes. `test:pg` uses the disposable `tenant_test` database configured in `phpunit.xml`; its guard requires an `_test` database and destructive-test opt-in. Do not point that connection at application data.

GitHub Actions runs the same static checks, the parallel SQLite suite, the PostgreSQL suite, and the Python contract fixtures. It installs locked dependencies against Packman `v0.1.2`. It does not enable Dependabot and it does not run the qualification lab.

The real worker/HTTP/storage/Redis, lifecycle fault-injection and consumer qualification suites under `tests/laravel`, `tests/postgres` and `tests/consumers` have separate isolated lab prerequisites. Passing the development checks does not imply those matrices were rerun. See the [lab runbook](docs/architecture/qualification-lab.md).
