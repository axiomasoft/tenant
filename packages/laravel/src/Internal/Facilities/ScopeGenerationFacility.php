<?php

declare(strict_types=1);

namespace Tenant\Laravel\Internal\Facilities;

use Illuminate\Container\Container;
use Packman\Attributes\Internal;
use Tenant\Contracts\Runtime\Facility;
use Tenant\Contracts\Runtime\FrameState;
use Tenant\Contracts\Runtime\PhaseContext;
use Tenant\Contracts\Runtime\RestoreContext;

/** @internal Scoped container invalidation. Runtime alone owns the generation. */
#[Internal]
final readonly class ScopeGenerationFacility implements Facility
{
    public function __construct(private Container $container) {}

    public function prepare(PhaseContext $context, FrameState $state): void {}

    public function activate(PhaseContext $context, FrameState $state): void
    {
        $this->container->forgetScopedInstances();
    }

    public function restore(RestoreContext $context, FrameState $state): void
    {
        $this->container->forgetScopedInstances();
    }

    public function verify(RestoreContext $context, FrameState $state): void {}
}
