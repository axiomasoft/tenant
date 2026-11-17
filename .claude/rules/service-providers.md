# Service Providers & Registrars

- Keep every `*ServiceProvider.php` thin (`<= 80` lines for `php-library`, `laravel-package`, and `modular-platform` profiles; `<= 120` lines for `product`) using `Packman\Foundation\Concerns\RegistersPackageRegistrars` (`ProviderSizeGate`).
- Delegate container bindings, config merging, event wiring, and console commands into focused `PackageRegistrar` classes (`register(Application $app): void`, `boot(Application $app): void`); use `RegistersNothingOnBoot` when a registrar has no boot hooks.
- Never perform database queries, filesystem scans, or network I/O inside `register()`.
- Guard optional cross-package integrations (e.g., `PanelsBindingsRegistrar` binding `ModulePanelCatalogue` / `EntityViewSchemaProvider` or consuming `PluginInspector` / `ModuleDiscovery`) with `interface_exists(...)` and `$app->bound(...)`.
