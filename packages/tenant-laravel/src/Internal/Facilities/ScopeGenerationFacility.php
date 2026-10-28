<?php

declare(strict_types=1);

namespace Tenant\Laravel\Internal\Facilities;

use Illuminate\Container\Container;
use Tenant\Contracts\{Facility, FrameState, PhaseContext, RestoreContext};

/** @internal CoreX scoped container invalidation. Runtime alone owns the generation. */
final readonly class ScopeGenerationFacility implements Facility
{
    public function __construct(private Container $container) {}
    public function prepare(PhaseContext $context, FrameState $state): void {}
    public function activate(PhaseContext $context, FrameState $state): void { $this->container->forgetScopedInstances(); }
    public function restore(RestoreContext $context, FrameState $state): void { $this->container->forgetScopedInstances(); }
    public function verify(RestoreContext $context, FrameState $state): void {}
}
