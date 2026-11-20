# Changelog

## Unreleased

- Declared the MIT license in package metadata.
- Wire queue pause and restart to the single configured central cache store. Any other list length fails closed before a store is resolved.
- Reuse the session handler captured during prepare when the driver, store, prefix, and session root are unchanged, and chmod the session directory only when its mode is not already 0700.
- Walk each local storage root once per operation. A symlink in the root, an ancestor, or a relative segment is still refused.
- Refuse `connect_via_database`, `connect_via_port`, `search_path`, `schema`, and `server_options` on the guarded PostgreSQL connection, and require the facet route to name host, database, username, and password instead of inheriting the central login.
- Keep a database transaction lease until the PDO transaction is actually gone, including when begin or rollback throws after the transaction level changes.
- Delete an expired file session when the guarded handler destroys it.
- Added explicit `laravel-scoped` composition for host-owned Eloquent connections without PostgreSQL or a physical binding verifier. The existing `laravel-pg-database` default and its stronger database guarantees remain unchanged.
- Added route-free `ScopeDescriptor` and the common `ResourceScope` facility SPI; cache/session/storage no longer need a database route in scoped mode.
- Added SQLite/Eloquent consumer-pattern tests for host-owned row scopes, nested context restoration, stable named connections and explicit single-installation mode.
- Added `ContextRequestBoundary::send()` to deliver streamed responses and downloads before tenant restoration. Existing `handle()` now refuses deferred responses; front controllers serving them must use `send()`.
- Clear resident authentication/session state on selection and activation failures as well as completed requests.
- Respect explicitly shared cache/session ownership while refusing Redis session stores that overlap host-owned cache stores.
- Reject explicit null configuration, duplicate store names and unknown shared facility keys.
- Store queue signing keys in redacted `SecretRef` objects without changing HMAC signatures.
- Detach queue registry allowlists and handler registrations from mutable PHP references.
- Stop the request boundary when a host selector leaks a different context, including selector failures.
- Migrated package onto `axiomasoft/packman` (`^0.1`) configuration, registrar, and doctor contracts.
- Added `TenantOptions`, `TenantFacilitiesOptions`, `TenantDoctorCheck`, and `TenantRuntimeRegistrar`, `TenantFacilitiesRegistrar`, `TenantQueueRegistrar`.
- Refactored `TenantServiceProvider` to use `RegistersPackageRegistrars`.
