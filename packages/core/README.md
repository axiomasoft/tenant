# Tenant Core (`axiomasoft/tenant`)

Portable, framework-agnostic isolation context, deterministic composition builder, and scoped execution runtime (`php-library`, PHP `^8.4`, `ext-intl`, `axiomasoft/packman`).

## Features

- Immutable `Selection`, `IsolationIdentity`, `ProfileRef`, `BindingExpectation`, `ExtensionRef`, and `ContextReference` value objects.
- LIFO frame-token runtime (`SafeTenantRuntime`) with per-frame participant state, reverse cleanup, and poison isolation.
- Deterministic topological composition (`CompositionBuilder`) with exclusive slots, capability matching, and strict replacement validation.
- Structured RFC 9457 error taxonomy (`TenantErrorCode`, `TenantFailure` implementing `DescribesError`) backed by `axiomasoft/packman`.

## Composition

The host implements `Contracts\Context\ContextResolver`, supplies immutable profile/capability declarations to `CompositionBuilder`, and constructs `Runtime\SafeTenantRuntime`. Use the `Tenant` instance facade or `Contracts\Runtime\TenantRuntime` for `run`, `enter`, `restore` and `current`. A callback runs only after activation; cleanup restores the previous frame on return or exception.

The core has no Laravel, PostgreSQL or panel dependency. Physical facilities and product adapters participate through public SPI. Contexts are explicitly missing, central or active; missing never grants central access. Durable references preserve resource incarnation and route revision.

Read the [host integration guide](../../docs/integration.md) before implementing a resolver, participant or runtime decorator. The [optional panel adapter](../panels/README.md) uses this same runtime without adding UI dependencies to the core.
