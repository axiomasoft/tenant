# Tenant Laravel (`axiomasoft/tenant-laravel`)

Guarded Laravel database, cache, session, local storage, HTTP request, and queue integration for `axiomasoft/tenant`.

## Features

- Typed `TenantOptions` and `TenantFacilitiesOptions` configuration DTOs implementing `Packman\Config\Contracts\OptionsFactory`.
- Focused `PackageRegistrar` composition (`TenantRuntimeRegistrar`, `TenantFacilitiesRegistrar`, `TenantQueueRegistrar`) wired via `TenantServiceProvider`.
- Read-only `TenantDoctorCheck` diagnostics verifying required extensions, central connection, queue `after_commit` safety, and tenant cache stores.
- Generation-guarded database, cache, session, storage, HTTP boundary, and signed scalar queue adapters.
