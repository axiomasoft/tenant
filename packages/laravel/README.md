# Tenant Laravel (`axiomasoft/tenant-laravel`)

Laravel context, Eloquent integration, guarded cache/session/local storage, HTTP and queue integration for `axiomasoft/tenant`. Database switching is a separate profile.

## Features

- Typed `TenantOptions` and `TenantFacilitiesOptions` configuration DTOs implementing `Packman\Config\Contracts\OptionsFactory`.
- Focused `PackageRegistrar` composition (`TenantRuntimeRegistrar`, `TenantFacilitiesRegistrar`, `TenantQueueRegistrar`) wired via `TenantServiceProvider`.
- Read-only `TenantDoctorCheck` diagnostics verifying required extensions, central connection, queue `after_commit` safety, and tenant cache stores.
- Generation-guarded database, cache, session, storage, HTTP boundary, and signed scalar queue adapters.

## Host setup

Install `axiomasoft/tenant-laravel` and bind `Tenant\Contracts\Context\ContextResolver` in the host provider's `register()` method. The provider constructs one `SafeTenantRuntime` and exposes it through `TenantRuntime` and the `Tenant` facade.

Choose the profile before boot:

- **`laravel-scoped`** supplies a context for Eloquent consumers while leaving their connections and data-isolation rules intact. No PostgreSQL owner, physical verifier or connection named `central` is required unless another enabled integration needs one.
- **`laravel-pg-database`** switches a guarded PostgreSQL connection per resource and additionally requires `Tenant\Contracts\Verification\BindingVerifier`. This remains the default for compatibility. It can use an existing physical owner; `tenant-postgres` is optional.

For an application that already owns all its database, cache, session and storage behavior, the minimal context-only `config/tenant.php` is:

```php
return [
    'runtime_profile' => 'laravel-scoped',
    'facilities' => [
        'database' => false,
        'cache' => false,
        'session' => false,
        'storage' => false,
    ],
    'shared' => [
        'database' => ['owner' => 'host:eloquent', 'purpose' => 'host:data-isolation'],
        'cache' => ['owner' => 'host:cache', 'purpose' => 'host:cache-isolation'],
        'session' => ['owner' => 'host:session', 'purpose' => 'host:authentication'],
        'storage' => ['owner' => 'host:storage', 'purpose' => 'host:storage-isolation'],
    ],
];
```

These declarations record existing host responsibilities; they do not implement row scoping or authorize sharing across tenants. Consumers still need their own Eloquent scopes, ownership checks or explicit connection/space routing. Missing context is not central access.

To let Tenant manage cache/session/storage in scoped mode, enable the chosen facilities, remove their `shared` declarations and return a trusted `Tenant\Laravel\Data\ScopeDescriptor` in the resolver's `FacetBindings` under `tenant:resource`. It contains a prefix and absolute durable, ephemeral and session roots, with no database route. The database facility stays disabled. Use `ProfileRef('laravel-scoped', 1)` for tenant/central selections, or an explicit `Selection::single(...)` for a host-enforced single installation.

Disabled cache/session facilities are validated as host-owned sharing declarations; their external drivers are not constrained to Tenant's supported isolated drivers. Enabled facilities still enforce those constraints. See the [integration guide](../../docs/integration.md) for composition hooks and ownership rules.

## HTTP response lifetime

```php
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Tenant\Contracts\Runtime\TenantRuntime;
use Tenant\Laravel\Http\ContextRequestBoundary;

$boundary = new ContextRequestBoundary(
    $app->make(TenantRuntime::class),
    $app,
    $trustedHostSelection, // Closure(Request): Selection; host routing and admission
);
$boundary->send(Request::capture(), $app->make(Kernel::class));
```

`send()` includes streaming callbacks, download delivery and kernel termination inside the active frame. Do not send or terminate again outside this boundary. `handle()` returns an already materialized response and refuses `StreamedResponse`/`BinaryFileResponse` with `unsupported_capability`; migrate streaming front controllers to `send()`.

Resident authentication/session state is cleared before host selection and after success or failure, including failures before frame activation. For resource-scoped sessions, place `ScopeSessionToResource` after `StartSession` and before authentication/admission.

The optional [panel adapter](../panels/README.md) can admit panel entry inside this boundary with either profile. It does not replace it. See [profile guarantees and migration](../../docs/architecture/laravel-profiles.md) before changing an existing host's profile or queued references.
