# Errors & Doctor Diagnostics

- Declare package errors as backed string enums implementing `Packman\Errors\Contracts\ErrorCode` using `<prefix>.<snake_case>` wire codes and register every case via `ProblemDefinitionProvider` with URN prefix `urn:packman:problem:` (`ErrorRegistryGate`).
- Construct `ErrorDescriptor` with valid RFC 6901 JSON Pointer `path` values and bounded scalar/flat JSON details (`<= 4096` bytes); extend `CodedException` for structured domain failures.
- Keep `resources/contracts/error-codes.json` synchronized:
  - `./bin/php84 bin/packman error-codes --write`
  - `./bin/php84 composer error-codes`
- Register operational checks via `DoctorCheck` / `HealthCheck` (`DoctorRegistry::TAG` / `DoctorRegistry::HEALTH_CHECK_TAG`) and inspect hosts read-only using `php artisan packman:doctor [--json] [--prefix=...]` and `php artisan packman:config:validate`.
