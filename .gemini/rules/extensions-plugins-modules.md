# Extensions: Stateless Plugins vs DB-Backed Modules

- Use **`axiomasoft/packman-plugins`** (`Packman\Plugins\`) for stateless intra-package code plugins:
  - Implement `ExtensionPlugin` (`manifest(): PluginManifest`, `register(PluginRegistrar $registrar): void`) with zero constructor arguments, zero I/O, and zero database coupling (`packman-plugins` does not depend on `illuminate/database`).
  - Declare `ContributionKind`, `CapabilityDefinition` (`Cardinality`), and `SlotDefinition` (`SlotKind`) via `PluginRegistrar` or composable `With*` traits.
  - `PluginGraphCompiler` resolves Kahn's topological load order, enforces transitive ancestor ownership, and freezes `FrozenContributions` (`Contributions`, `PluginInspector`, `Capabilities`, `ExtensionRegistry`).
  - DB-backed hosts may optionally bind `PluginActivationGate`; standalone Laravel consumers use config/code `$disabled` lists with zero `mod_*` tables.
- Use **`axiomasoft/packman-modules`** (`Packman\Modules\`) for DB-backed Composer-discovered platform modules/plugins:
  - Extend `ModuleDefinition` (`manifest(): ModuleManifest`) and configure Composer `type` / `extra` discovery keys via `ModulesOptions` (`modules.*` / `packman.modules.*`).
  - `RegistryCompiler` executes the closed 8-stage pipeline (`PipelineStages::ORDER`: `Entity -> EntityField -> Permissions -> Api -> Filament -> Menu -> Routes -> Workflow`) and `CompiledModuleRegistry` writes `bootstrap/cache/modules.php` atomically.
  - `DatabaseModuleLifecycle` manages tenant-scoped state transitions across `mod_modules`, `mod_records`, and `mod_lifecycle_log`.
- Prefer composing `Packman\Extensions\Contracts\{Configuration,Persistence,Composition,Runtime}\With*` interfaces and default traits (`Configurable`, `InteractsWithDatabaseSettings`, `RegistersInDatabase`, `OwnsSchema`, `ProvidesCapabilities`, `ContributesToSlots`, `ProvidesPanels`, `HasLifecycleHooks`, `ProvidesDiagnostics`, `DeclaresFeatureFlags`), inspected uniformly by `ExtensionInspector::inspect()`.
