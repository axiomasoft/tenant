# Laravel runtime profiles

The host selects `tenant.runtime_profile` once, before boot. Each profile compiles a different facility composition. A request or panel cannot weaken the selected profile, and a durable reference must match its registered profile ID and version.

| Responsibility | `laravel-scoped` | `laravel-pg-database` |
| --- | --- | --- |
| Active tenant context and nested restoration | Tenant runtime | Tenant runtime |
| Eloquent connections and SQL data isolation | Host and consuming packages | Guarded PostgreSQL database per resource |
| Physical binding verifier | Not required | Required, supplied by the physical owner |
| Cache/session/local storage | Individually enabled guarded facilities or explicit host ownership | Individually enabled guarded facilities or explicit host ownership |
| Tenant facility metadata | `tenant:resource` → `ResourceScope`, normally `ScopeDescriptor` | `tenant:database` → `ResourceDescriptor` |
| Database creation, clone, export and deletion | Outside this profile | Separate optional physical owner |
| Single installation | Explicit `single/1` selection | Not an implicit fallback |

## Scoped context

`laravel-scoped/1` supports tenant and central selections. Its composition also registers `single/1`; a host resolver must enforce the single installation's fixed identity. Every active context still has a logical resource reference, incarnation and route revision, so queued references and session namespaces remain distinguishable. A synthetic host binding is permitted; it does not prove physical database isolation.

The database facility must be disabled with an explicit `shared.database` owner and purpose. The provider leaves the host's default and named Eloquent connections intact and does not register the guarded PostgreSQL driver. It does not require a connection named `central` when no selected integration needs it.

Enabled cache/session/storage facilities obtain trusted metadata through `tenant:resource`. `ScopeDescriptor` contains only the prefix and absolute durable, ephemeral and session roots. No database route or credentials are needed. If all those facilities are host-owned, the resolver may return empty facets.

Consumers remain responsible for SQL scoping, ownership checks, model stamping and restrictions on retained models/builders. A runtime context alone does not add `where tenant_id = ...`, constrain raw SQL or isolate arbitrary queries. A host adapter must establish the consumer's required connection/space before its operations. Applications with a retained central authority connection should keep it explicitly named and stable.

## Database isolation

`laravel-pg-database/1` retains its existing admission and lifetime guarantees. It requires the database facility, binding-verification service and scope generation. Database activation refuses scoped descriptors and incompatible profiles. Ordinary SQL uses the guarded PostgreSQL connection, and held connections, transactions and cursors follow the generation/lease protocol.

This profile does not require `tenant-postgres`. An existing physical owner can supply the public verifier and trusted routing. Install `tenant-postgres` only when it is selected as the sole provisioning/recovery owner; Eloquent itself does not provide that physical lifecycle.

## Compatibility and evidence

The default remains `laravel-pg-database` so existing hosts and durable references keep their semantics. Switching an application's profile is a deliberate host configuration change, not an automatic queue conversion. Do not relabel existing `laravel-pg-database` envelopes as scoped references.

The original `contracts/profiles.v1.json` and its corpus remain the frozen original qualification inputs. The additive scoped implementation is specified here and covered by the package's scoped runtime/Eloquent tests; it does not change `tenant.context/1` or claim that the historical matrix was rerun for the new profile.
