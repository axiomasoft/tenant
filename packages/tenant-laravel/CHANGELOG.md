# Changelog

## Unreleased

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
