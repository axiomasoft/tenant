<?php

declare(strict_types=1);

namespace Tenant\Data;

use Tenant\Attributes\Api;


#[Api]
final readonly class FrozenDispatch
{
    public function __construct(public ContextReference $reference, public Purpose $purpose) {}
}
