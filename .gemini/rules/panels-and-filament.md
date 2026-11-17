# Panels & Filament Security

- **`axiomasoft/packman-panels` (`Packman\Panels\`):**
  - Register panels during boot through `PanelRegistry` (`register`, `replace`, `configure`, `configureAll`) using `PanelProvider` and `PanelBuilder`; the registry freezes atomically on `$app->booted(...)`.
  - Use `InMemoryPanelCatalogue` when `packman-modules` is absent and `ModulePanelCatalogue` automatically when `ModuleRegistry` & `EntityRegistry` are bound.
  - Project bounded, optimistic-concurrency-versioned forms with `PanelFormProjector` -> `PanelFormData` (`editVersion`, `clamped`, `editable`).
  - Enforce admission checks via `PanelAdmitter` / `PanelAdmissionGate` (`TenantMode`, `AssignmentScopeMode`).
- **`axiomasoft/packman-filament` (`Packman\Filament\`):**
  - Attach `PackmanFilamentPlugin::make()` after configuring `->tenant(...)` on Filament panels; enable `->enforce()` and `->guardPanel(...)` in production surfaces.
  - Ensure enforced Filament resources, pages, relation managers, and widgets use `AuthorizesResource`, `AuthorizesPage`, `AuthorizesRelationManager`, and `AuthorizesWidget` without overriding protected authorization methods (`ClassTraits::keepsMethods`).
  - Keep `FilamentSecurityRegistrar` Livewire hooks (`RefusesSurfaceBoot`, `RefusesSurfaceCalls`, `RefusesEditableColumns`, `RefusesUnverifiableDownload`, `AuthorizesActions`, `AuthorizesExports`) and `BindingEditor` AES-sealed contexts active.
