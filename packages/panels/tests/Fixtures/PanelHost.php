<?php

declare(strict_types=1);

namespace Tenant\Panels\Tests\Fixtures;

use Closure;
use Packman\Context\Contracts\ContextScopeInterface;
use Packman\Context\Data\ScopeRef;
use Packman\Panels\Builder\Panel;
use Packman\Panels\Contracts\Admission\PanelAdmissionGate;
use Tenant\Data\Context\ContextState;
use Tenant\Panels\Contracts\PanelScopeResolver;
use TenantTests\RuntimeRig;
use UnitEnum;

final class PanelHost implements PanelAdmissionGate, PanelScopeResolver
{
    public RuntimeRig $rig;

    public bool $allowed = true;

    public ?Closure $projection = null;

    public ?Closure $membership = null;

    public array $seen = [];

    public function __construct()
    {
        $this->rig = new RuntimeRig;
    }

    public function resolve(Panel $panel, ContextState $context): ScopeRef
    {
        if ($this->projection !== null) {
            return ($this->projection)($context);
        }

        return new ScopeRef($context->context === null ? 'global' : $context->context->identity->namespace.':'.$context->context->identity->id);
    }

    public function isMember(Panel $panel, object $subject, ContextScopeInterface $tenant, ?string $scopeType = null, ?string $scopeId = null): bool
    {
        $this->seen[] = [$tenant->id(), $this->rig->runtime->current(), $this->rig->cells, $scopeType, $scopeId];

        return $this->membership === null ? $this->allowed : ($this->membership)();
    }

    public function hasRole(Panel $panel, object $subject, ContextScopeInterface $tenant, ?string $scopeType = null, ?string $scopeId = null): bool
    {
        return $this->allowed;
    }

    public function childScopesWithRole(Panel $panel, object $subject, ContextScopeInterface $tenant, string $scopeType): array
    {
        return [];
    }

    public function allowsEntry(Panel $panel, object $subject, ContextScopeInterface $tenant, string|UnitEnum $permission, ?string $scopeType = null, ?string $scopeId = null): bool
    {
        return $this->allowed;
    }
}
