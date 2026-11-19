<?php

declare(strict_types=1);

namespace Tenant\Contracts\Runtime;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Tenant\Data\Runtime\RuntimeObservation;

#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface ObservationSink
{
    public function observe(RuntimeObservation $observation): void;
}
