<?php

declare(strict_types=1);

namespace Tenant\Data\Runtime;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

#[Spi(kind: SpiKind::Call, since: '1.0')]
final readonly class Diagnostic
{
    public function __construct(public string $reason, public string $observerId, public string $frameCorrelation) {}
}
