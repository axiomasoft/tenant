# Changelog

## Unreleased

- Migrated package onto `axiomasoft/packman` (`^0.1`) pure-PHP kernel (`Packman\Attributes`, `Packman\Errors`).
- Added `TenantErrorCode` (`ErrorCode`), `TenantProblemDefinitions` (`ProblemDefinitionProvider`), and `CodedException` / `DescribesError` support on `TenantFailure`.
- Promoted `GenerationGuard` to `Tenant\Runtime\GenerationGuard` as an explicit `#[Spi]` runtime hook.
