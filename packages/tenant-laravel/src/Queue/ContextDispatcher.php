<?php

declare(strict_types=1);
namespace Tenant\Laravel\Queue;

use Tenant\Data\{ContextReference, FrozenDispatch, Purpose};

/** Explicit adapter dispatch. Host registers trusted producers/handlers before boot. */
interface ContextDispatcher
{
    public function freezeTenant(ContextReference $reference, Purpose $purpose): FrozenDispatch;
    public function freezeCentral(Purpose $purpose): FrozenDispatch;
    public function enqueue(FrozenDispatch $intent, object $job): void;
}
