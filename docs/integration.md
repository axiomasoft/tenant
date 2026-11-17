# Integrating a host and its packages

## Choose the owner once

The host composition root owns tenant selection, trusted producer admission and the mapping from logical identity to a resource. `ContextResolver::resolve(Selection, Purpose)` returns a fresh `ResolvedIsolation`; it must refuse deleted, unavailable or unauthorized targets. A reference from a URL or a queue is a request to resolve, not authority to access data.

For the database isolation profile, the physical owner supplies `BindingVerifier`. Verification checks the expected resource, incarnation and route against the actual connection and owner-controlled marker. Use `tenant-postgres` if it is the physical owner, or a qualified host implementation if an existing owner remains responsible. Never run two provisioners or marker writers for the same resource. The `laravel-scoped` profile uses host-owned Eloquent connections and does not require a physical owner or verifier.

## Stable extension points

| Concern | Public contract | Ownership |
| --- | --- | --- |
| Select and resolve an isolation target | `Tenant\Contracts\Context\ContextResolver` | Host |
| Verify a physical binding | `Tenant\Contracts\Verification\BindingVerifier` | Physical resource owner |
| Run or restore a context | `Tenant\Contracts\Runtime\TenantRuntime` | One runtime; decorators delegate to it |
| Activate and restore package state | `ParticipantFactory`, `Facility`, `FrameState` | Contributing package |
| Validate product-specific scalar references | `Tenant\Contracts\Context\ExtensionDecoder` | Product adapter |
| Observe completed transitions | `ObservationSink`, `DiagnosticSink` | Optional telemetry adapters |
| Project tenant context into panel authorization | `Tenant\Panels\Contracts\PanelScopeResolver` | Host authorization adapter |

Import public `Contracts`, `Data` and attributed API classes. `Internal` implementations are not integration contracts. Existing flat class aliases remain compatibility shims; new integrations use canonical namespaces.

## Read-only product projections

A package that needs only the current tenant should project `TenantRuntime::current()` into its own public identity type. Preserve both identity namespace and ID, and distinguish missing from explicit central. Do not initialize tenancy inside a getter, read request headers again, or maintain a separate static tenant cache. A projection may be recomputed cheaply on every call, so nested frames and reused workers observe the current context.

Some consumers support fewer identity bytes or fewer isolation modes. Refuse incompatible values explicitly; truncation, implicit casts and silent global fallback can merge tenants. Cache/lock keys and durable work also need the resource incarnation where their lifetime can outlive resource recreation.

Product state that must change during activation belongs in a `product`-stage participant. Its factory creates frame-local state. `prepare` captures state, `activate` applies it, `restore` compensates and `verify` confirms the restored baseline. Phase contexts expire at the end of their phase and are confined to the owning execution lane. Observers cannot replace required participants.

## Laravel composition

Bind the host resolver in a service provider's `register()` method, before application boot. Select `runtime_profile: laravel-scoped` for consumer-owned Eloquent data isolation, or bind a physical verifier and retain `laravel-pg-database` for guarded database switching. The core also supports portable `php-scoped` and `single` compositions when the host configures them explicitly. See [Laravel profiles](architecture/laravel-profiles.md) for their precise boundaries.

Use the existing composition hooks only in the host's registration phase:

| Binding | Value |
| --- | --- |
| `tenant.compose` | `Closure(CompositionBuilder, Container): void`, registering explicit participants/replacements |
| `tenant.services` | List of named service/slot/SPI/capability declarations |
| `tenant.decorate` | `Closure(SafeTenantRuntime): TenantRuntime`, delegating to the supplied owner |
| `tenant.observers` | List of `ObservationSink` instances |
| `tenant.diagnostics` | `DiagnosticSink` |

Do not replace runtime container bindings after boot. Installing a panel adapter does not register another executor. An arbitrary replacement runtime must be composed independently and satisfy the same frame/token/restore/poison contracts; the default Laravel provider is designed around its guarded `SafeTenantRuntime` owner.

Configuration is compiled into `TenantOptions`. Null values, unknown keys, duplicate store names and misspelled shared facility names are rejected. A disabled facility requires an explicit `shared` owner and purpose. That declaration means another owner is responsible for its isolation; it does not make arbitrary sharing safe. Generated JSON Schema describes configuration shape; the compiled options and facility validator perform the authoritative semantic checks.

## Existing package integration seams

The neighboring package implementations use different identity and data boundaries. The following describes inspected source contracts, not a completed migration or a passing consumer qualification matrix.

| Consumer | Existing boundary | Host integration |
| --- | --- | --- |
| AzGuard | `TenantRef` type/ID and separate assignment scope; authority storage retains a concrete connection | Project the active identity into its `TenantResolver`/explicit tenant access. Keep authority storage on an explicit stable connection, or supply a separately qualified lifecycle adapter. |
| Vaulter | Drive `TenancyBoundary` and model stamping already enforce `tenant_id` | Implement `TenantResolver` and `ResolvesOwnerTenant` using the host's existing key mapping. Let Drive retain its row/owner checks. |
| Chatom | `TenantContext` is a read-only key; compiled spaces own their connection; shared-table `tenant_id` isolation is not supplied | Use an explicit tenant-to-space mapping or a qualified connection adapter. Restore the host context before its captured-key checks run. |
| CoreX / FlexCRM | Account database routing plus workspace selection inside that database | Integrate public context/restoration/connection contracts and select one physical owner. Workspace selection is not a replacement for account database isolation. |

Do not concatenate every namespace and ID into a universal consumer key. AzGuard's ID accepts at most 64 printable ASCII bytes, Chatom's tenant key at most 128 bytes, and Vaulter's configured host keys may be UUIDs or integers. Tenant identities can exceed some of these limits. Use explicit existing mappings and refuse unsupported values.

Queue capture is also distinct from restoration: the inspected consumer contracts validate captured identities but do not automatically restore a Tenant runtime frame. Their workers require an explicit host boundary before consumer validation. The current neighboring sources do not provide a native standalone Tenant bridge for all these contracts.

## HTTP, queues and panels

Wrap the complete request lifecycle with `ContextRequestBoundary::send()`: selection, activation, kernel handling, response delivery, kernel termination and restoration. This keeps stream callbacks and file downloads inside the tenant frame. `handle()` remains available for already materialized responses and rejects deferred stream/file responses. The boundary clears resident session and authentication state before selection and after every outcome. Do not call `Kernel::terminate()` a second time outside the boundary.

Use `ScopeSessionToResource` after `StartSession` and before authentication/admission when sessions belong to tenant resources. Queue producers use `ContextDispatcher`, an explicit frozen target and the registered scalar job contract; raw application objects and live connections do not belong in the wire payload.

For panels, install `tenant-panels` and implement its host scope projection. `PanelTenant::run()` activates the explicit selection, checks panel admission and runs the callback; normal runtime restoration covers denials and exceptions. `PanelTenant::admit()` integrates with an existing HTTP frame. Admission is checked on every entry and is not an authorization grant for individual record actions. See the [panel adapter examples](../packages/tenant-panels/README.md).

## Qualification boundaries

Core contract tests establish runtime semantics. Provider and panel tests establish composition and admission behavior. PostgreSQL tests establish the checks they execute against a real test database. A host migration also requires its own job, HTTP, policy, schema and restoration scenarios against an isolated installation. Do not infer full application compatibility from a matching Composer constraint or a passing provider boot test.
