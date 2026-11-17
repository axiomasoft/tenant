<?php

declare(strict_types=1);

namespace Tenant\Laravel\Queue;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Tenant\Data\Context\ContextReference;
use Tenant\Data\Context\FrozenDispatch;
use Tenant\Data\Context\Purpose;

/** Explicit adapter dispatch. Host registers trusted producers/handlers before boot. */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface ContextDispatcher
{
    public function freezeTenant(ContextReference $reference, Purpose $purpose): FrozenDispatch;

    public function freezeCentral(Purpose $purpose): FrozenDispatch;

    public function enqueue(FrozenDispatch $intent, object $job): void;
}
