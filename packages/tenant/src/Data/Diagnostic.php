<?php

declare(strict_types=1);

namespace Tenant\Data;

use Tenant\Attributes\Spi;


#[Spi]
final readonly class Diagnostic
{
    public function __construct(public string $reason, public string $observerId, public string $frameCorrelation) {}
}
