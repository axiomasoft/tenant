# Tenant Panels (`axiomasoft/tenant-panels`)

Optional integration with `axiomasoft/packman-panels`. Panel entry runs inside the host's existing Tenant runtime. This package adds no physical resource owner, current-tenant store, Filament dependency or implicit authorization policy.

## Install and configure

```sh
composer require axiomasoft/tenant-panels:^1.0
```

The service provider is discovered by Laravel and registers `PanelTenant` lazily. The host supplies `TenantRuntime`, the Packman `PanelRegistry` and `PanelAdmitter` (normally backed by a host `PanelAdmissionGate`), plus `PanelScopeResolver`. Constructor composition works with the same contracts outside a Laravel application.

Register panels using Packman's ordinary `PanelProvider` and `PanelBuilder`. Set `->tenantMode(TenantMode::Required)` for panels that require an active tenant. `TenantMode::None` does not select a central context automatically: the caller still supplies an explicit selection.

Bind a host projection in `register()`:

```php
use Packman\Context\Data\ScopeRef;
use Packman\Panels\Builder\Panel;
use Tenant\Data\Context\ContextState;
use Tenant\Exceptions\Context\ScopeRequired;
use Tenant\Panels\Contracts\PanelScopeResolver;

$app->singleton(PanelScopeResolver::class, static fn () => new class implements PanelScopeResolver {
    public function resolve(Panel $panel, ContextState $context): ScopeRef
    {
        if ($context->kind === 'central') {
            return new ScopeRef('global');
        }

        $identity = ($context->context ?? throw new ScopeRequired)->identity;

        // Use this encoding only if the host's authorization policy uses the same keys.
        return new ScopeRef($identity->namespace.':'.$identity->id);
    }
});
```

The resolver maps the context already activated by the selected runtime profile; it must not switch tenancy or derive a different tenant from request input. Missing mappings fail. `global` and `system` are reserved for explicit central contexts. No default resolver is installed because each host owns its authorization identity model.

## Execute through a panel

```php
use Packman\Panels\Data\Admission\AdmissionResult;
use Tenant\Data\Context\ContextState;
use Tenant\Data\Context\Purpose;
use Tenant\Panels\PanelTenant;

$result = $app->make(PanelTenant::class)->run(
    panelId: 'workspace',
    selection: $trustedSelection,
    subject: $authenticatedSubject,
    purpose: new Purpose('host:panel'),
    callback: function (ContextState $context, AdmissionResult $admission) {
        // Facilities are active and panel entry has been authorized here.
        return $context->context?->identity->id;
    },
);
```

An unknown panel, central selection for a required-tenant panel, incomplete child scope or undeclared child scope type is rejected before activation. Membership/role/entry checks occur after activation, so tenant-local policy queries use the correct resource. Denial or an exception restores the outer frame through the same runtime. Existing runtime decorators remain in use.

For a child scope, pass both `scopeType` and `scopeId`, and declare the scope type on the panel. Packman's admission policy decides membership, roles, entry permission and graceful child-scope witnesses. Successful entry never replaces authorization of individual operations.

## Existing HTTP boundary

Inside a request already wrapped by `ContextRequestBoundary::send()`, use:

```php
$admission = $app->make(PanelTenant::class)->admit('workspace', $authenticatedSubject);
```

This reads the existing active frame and performs fresh panel admission. Missing context fails with `ScopeRequired`; denied or inconsistent scope mappings fail with `ContextDenied`. Route middleware should translate denial to the host's HTTP response policy. Keep response streaming and kernel termination inside the outer HTTP boundary.

Panel selection does not resolve a tenant from untrusted headers, provision databases, start sessions, install Filament middleware or restore queued jobs. Those responsibilities remain with the host and the corresponding Tenant adapters. The panel callback API is for work completed before it returns; use the HTTP boundary for deferred response delivery.
