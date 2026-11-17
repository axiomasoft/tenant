# Schema Governance & Migrations

- Register all package migrations explicitly via `SchemaSet` (`SchemaScope::Central|Tenant|Dedicated`, `SchemaLifecycle::Platform|Module`) and `SchemaSetProvider`; never call `loadMigrationsFrom()` blindly.
- Track logical noun ownership with `SchemaOwnershipRegistry` (longest-matching noun rule) and derive PostgreSQL-safe identifiers (<= 63 bytes) via `ConstraintNames::shorten()`.
- Extend `Packman\Schema\Laravel\PackmanMigration` for normalized `_pk` constraints, `uuidPrimary()`, `identityPrimary()`, `money()`, `ref()`, `timestampsTz()`, `index()`, `unique()`, `foreign()`, `check()`, and `exclude()`.
- Never create colliding `Schema::create()` tables across packages (`TableOwnershipGate`), never mutate `sys_*`, `mod_*`, or `migrations` tables directly outside `axiomasoft/packman-schema` and `axiomasoft/packman-modules` (`TableWriteOwnershipGate`), and never reuse migration timestamp prefixes (`SchemaGate`).
