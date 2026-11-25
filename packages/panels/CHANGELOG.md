# Changelog

## Unreleased

- Declared the MIT license in package metadata.
- Added optional panel admission through the existing `TenantRuntime`, with nested restoration and explicit central/tenant separation.
- Added the host-owned `PanelScopeResolver` SPI and lazy Laravel service registration without default authorization or a second executor.
- Added real Packman registry/admission integration tests for denial, stale bindings, exceptions, scopes and runtime decoration.
