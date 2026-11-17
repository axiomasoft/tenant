# Changelog

## Unreleased

- Migrated package onto `axiomasoft/packman` (`^0.1`) and `axiomasoft/packman-schema` (`^0.1`).
- Added `TenantPostgresSchemaSets` (`SchemaSetProvider`), `TenantPostgresExtension`, `TenantPostgresRegistrar`, and `TenantPostgresServiceProvider`.
- Purged legacy host-product template manifest, advisory lock, and signup lock strings in favor of neutral `tenant-*` / `tenant:` identifiers.
