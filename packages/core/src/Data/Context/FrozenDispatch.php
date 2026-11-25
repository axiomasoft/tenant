<?php

declare(strict_types=1);

namespace Tenant\Data\Context;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class FrozenDispatch
{
    public function __construct(public ContextReference $reference, public Purpose $purpose) {}
}
