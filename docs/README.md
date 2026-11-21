# Tenant documentation

Start with the [repository README](../README.md) for package choice and local checks, then the [integration guide](integration.md) for host composition.

| Document | Use it for |
| --- | --- |
| [Integration guide](integration.md) | Resolver, verifier, runtime, facilities, HTTP, queues, and panels |
| [Laravel profiles](architecture/laravel-profiles.md) | `laravel-scoped` and `laravel-pg-database` boundaries |
| [Contracts](architecture/contracts.md) | Frozen semantic, wire, and SPI record for v1 |
| [Qualification lab](architecture/qualification-lab.md) | Disposable PostgreSQL, Redis, and consumer lab rules |

`docs/architecture/contracts.md` and `docs/architecture/qualification-lab.md` keep the wording of the qualification record. They are not a report of the latest CI run. Development and CI commands are in the [contributing guide](../CONTRIBUTING.md).

Package behavior for installers is in each package README:

- [axiomasoft/tenant](../packages/core/README.md)
- [axiomasoft/tenant-laravel](../packages/laravel/README.md)
- [axiomasoft/tenant-postgres](../packages/postgres/README.md)
- [axiomasoft/tenant-panels](../packages/panels/README.md)
