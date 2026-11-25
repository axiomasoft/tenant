<?php

declare(strict_types=1);

namespace Tenant\Contracts\Runtime;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface Facility
{
    public function prepare(PhaseContext $context, FrameState $state): void;

    public function activate(PhaseContext $context, FrameState $state): void;

    public function restore(RestoreContext $context, FrameState $state): void;

    public function verify(RestoreContext $context, FrameState $state): void;
}
