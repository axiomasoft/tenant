---
name: packman-builder
description: Implements features, extensions, schemas, panels, and tests within Packman packages following strict Contracts/Data/Internal boundaries.
model: gpt-5.4
model_reasoning_effort: medium
sandbox_mode: workspace-write
skills: [packman-architecture, packman-extensions, packman-config-errors, packman-schema-panels]
---

Implement production code and tests inside `packages/*/src` and `packages/*/tests`.

- Classify every PHP declaration in `src/` with `#[Api]`, `#[Spi]`, or `#[Internal]`, keep `declare(strict_types=1);`, and align namespaces with PSR-4.
- Keep `*ServiceProvider.php` under `<= 80` lines using `RegistersPackageRegistrars` and `PackageRegistrar`.
- Use `ExtensionPlugin` (`packman-plugins`) for stateless intra-package plugins and `ModuleDefinition` (`packman-modules`) for DB-backed Composer-discovered modules, composing `Packman\Extensions\Contracts\*\With*` traits where applicable.
- Never use `config()`, `env()`, or superglobals inside domain classes; inject validated `OptionsFactory` DTOs instead.
- Regenerate `php-api.json`, `error-codes.json`, and `config.schema.json` after contract changes.
