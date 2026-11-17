<?php

declare(strict_types=1);

namespace Tenant\Internal\Runtime;

use Fiber;
use Packman\Attributes\Internal;
use Tenant\Contracts\Context\FacetAccess;
use Tenant\Contracts\Runtime\PhaseContext;
use Tenant\Contracts\Runtime\RestoreContext;
use Tenant\Data\Composition\CapabilityId;
use Tenant\Data\Context\ContextState;
use Tenant\Data\Context\FacetBindings;
use Tenant\Exceptions\Context\ContextUnavailable;
use Tenant\Exceptions\Runtime\ConcurrentEntry;
use Tenant\Exceptions\Runtime\StaleHandle;

/** @internal A phase view cannot be retained and used after its transition. */
#[Internal]
final class TransitionContext implements PhaseContext, RestoreContext
{
    private function __clone() {}

    private bool $open = true;

    /** @var Fiber<mixed, mixed, mixed, mixed>|null */
    private readonly ?Fiber $lane;

    public function __construct(private readonly ContextState $state, private readonly FacetBindings $facets, private readonly bool $available = true)
    {
        $this->lane = Fiber::getCurrent();
    }

    public function close(): void
    {
        $this->open = false;
    }

    private function check(): void
    {
        if (! $this->open) {
            throw new StaleHandle;
        }
        if (Fiber::getCurrent() !== $this->lane) {
            throw new ConcurrentEntry;
        }
        if (! $this->available) {
            throw new ContextUnavailable;
        }
    }

    public function candidate(): ContextState
    {
        $this->check();

        return $this->state;
    }

    public function previous(): ContextState
    {
        $this->check();

        return $this->state;
    }

    public function require(CapabilityId $id): FacetAccess
    {
        $this->check();

        return $this->facets->require($id);
    }
}
