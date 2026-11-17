# Public API & SemVer Discipline

- Every class, interface, trait, and enum in `packages/*/src/` must declare exactly one surface attribute from `Packman\Attributes`:
  - `#[Api(since: '1.0')]` — stable caller/DTO surface covered by SemVer guarantees.
  - `#[Spi(kind: SpiKind::Implement|SpiKind::Call, since: '1.0')]` — extension contract for downstream implementers or framework callers.
  - `#[Internal]` — internal implementation; excluded from SemVer and blocked from cross-package imports by `OwnershipGate`.
- Never expose `#[Internal]` types in parameter, return, or property types of `#[Api]` or `#[Spi]` declarations (`AttributesArchitectureGate`).
- Regenerate and verify `resources/contracts/php-api.json` whenever public/SPI types change:
  - `./bin/php84 bin/packman php-api --write`
  - `./bin/php84 composer php-api`
- Every source file must start with `declare(strict_types=1);` and match PSR-4 directory and symbol naming (`NamingGate`).
