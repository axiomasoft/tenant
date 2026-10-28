<?php

declare(strict_types=1);

namespace Tenant\Internal\Runtime;

use Tenant\Contracts\{FacetAccess, PhaseContext, RestoreContext};
use Tenant\Data\{CapabilityId, ContextState, FacetBindings};
use Tenant\Exceptions\TenantFailure;

/** @internal A phase view cannot be retained and used after its transition. */
final class TransitionContext implements PhaseContext, RestoreContext
{
    private bool $open = true;

    public function __construct(private readonly ContextState $state, private readonly FacetBindings $facets, private readonly bool $available = true) {}
    public function close(): void { $this->open = false; }
    private function check(): void
    {
        if (!$this->open) throw new \Tenant\Exceptions\StaleHandle();
        if (!$this->available) throw new \Tenant\Exceptions\ContextUnavailable();
    }
    public function candidate(): ContextState { $this->check(); return $this->state; }
    public function previous(): ContextState { $this->check(); return $this->state; }
    public function require(CapabilityId $id): FacetAccess { $this->check(); return $this->facets->require($id); }
}
