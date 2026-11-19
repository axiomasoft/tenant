# Changelog

## Unreleased

- Restrict runtime binding verification to ready/active resources; provisioning and recovery require the explicit maintenance path.
- Reject marker column-write grants and marker/schema/database ownership even when direct table grants have been revoked.
- Check both session and current role identities, including reachable `NOINHERIT` roles, so `SET ROLE` cannot restore forbidden privileges.
- Reject Laravel connector database/port overrides throughout physical routing so they cannot bypass the frozen target.
- Preserve configured TLS options when invoking native `pg_dump`.
- Bind recovered archive manifests to the requested original export ID and refuse symlink manifests.
- Added real PostgreSQL isolation regressions and corrected maintenance-verifier usage in physical-owner lab fixtures.
- Migrated package onto `axiomasoft/packman` (`^0.1`) and `axiomasoft/packman-schema` (`^0.1`).
- Added `TenantPostgresSchemaSets` (`SchemaSetProvider`), `TenantPostgresExtension`, `TenantPostgresRegistrar`, and `TenantPostgresServiceProvider`.
- Purged legacy host-product template manifest, advisory lock, and signup lock strings in favor of neutral `tenant-*` / `tenant:` identifiers.
