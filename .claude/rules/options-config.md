# Options & Configuration Governance

- Compile package configuration once at boot into an immutable `OptionsFactory` DTO (`fromArray(array $config, string $path): self`), validated via `ConfigurationValidator` or `JournalingConfigReader` + `SectionCompiler`.
- Never call ambient `config()`, `env()`, `Config::get()`, or read PHP superglobals (`$_GET`, `$_POST`, `$_SERVER`, `$_ENV`, `global`) inside domain logic outside `Config/`, `Laravel/`, `Console/`, `Commands/`, `Testing/`, or `*ServiceProvider.php` (`ConfigCompositionGate`, `RuntimeGlobalsGate`).
- Own root configuration sections via `ConfigSections::own()` during `register()`. Use `SectionCompiler` for closed-schema checks (with Levenshtein suggestions on unknown keys) and `unsafeBool()` checks that require explicit production opt-in (`PACKMAN_ALLOW_UNSAFE=true` / `packman.unsafe.allow_in_production`).
- Wrap sensitive strings in `Packman\Config\Data\SecretRef` (never plain strings in dumps, JSON, or logs) and validate table prefixes with `Packman\Config\Data\TablePrefix`.
- Keep `resources/schemas/config.schema.json` synchronized:
  - `./bin/php84 bin/packman config-schema --write`
  - `./bin/php84 composer config-schema`
