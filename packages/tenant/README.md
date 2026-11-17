# Tenant Core (`axiomasoft/tenant`)

Portable, framework-agnostic isolation context, deterministic composition builder, and scoped execution runtime (`php-library`, PHP `^8.4`, `ext-intl`, `axiomasoft/packman`).

## Features

- Immutable `Selection`, `IsolationIdentity`, `ProfileRef`, `BindingExpectation`, `ExtensionRef`, and `ContextReference` value objects.
- LIFO frame-token runtime (`SafeTenantRuntime`) with per-frame participant state, reverse cleanup, and poison isolation.
- Deterministic topological composition (`CompositionBuilder`) with exclusive slots, capability matching, and strict replacement validation.
- Structured RFC 9457 error taxonomy (`TenantErrorCode`, `TenantFailure` implementing `DescribesError`) backed by `axiomasoft/packman`.
