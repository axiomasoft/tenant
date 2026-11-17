# Changelog

## Unreleased

- Detached PHP array references in immutable contexts, durable references, participant declarations and compiled composition so caller mutations cannot change a frozen target or capability graph.
- Closed phase capabilities before compensation and observations; rejected retained clones and cross-Fiber access.
- Added regression coverage for phase lifetime, reference aliasing and recursive metadata.
- Migrated package onto `axiomasoft/packman` (`^0.1`) pure-PHP kernel (`Packman\Attributes`, `Packman\Errors`).
- Added `TenantErrorCode` (`ErrorCode`), `TenantProblemDefinitions` (`ProblemDefinitionProvider`), and `CodedException` / `DescribesError` support on `TenantFailure`.
- Promoted `GenerationGuard` to `Tenant\Runtime\GenerationGuard` as an explicit `#[Spi]` runtime hook.
