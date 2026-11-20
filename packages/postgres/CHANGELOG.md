# Changelog

## Unreleased

- Pin `search_path` to `public` and read `public.tenant_binding` during binding verification, recovery completion, and marker updates.
- Release one `database_count` slot in the same transaction as a verified DROP publication, including a cancelled receipt that still observed the drop. A missing cluster or a count of zero rolls that publication back.
- Report a failed archive self-check as `ArchiveUnverified` so lifecycle callers receive `tenant.archive_unverified` instead of `tenant.operation_unknown`.
- Quote allowlisted PostgreSQL identifiers at clone, marker, and `DROP DATABASE` call sites, and reject every other physical name before DDL.
- Require an absolute `pg_dump` path and pass the child process only the archive route plus `PATH`.
- Declared the MIT license in package metadata.
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
