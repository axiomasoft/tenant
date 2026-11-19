<?php

declare(strict_types=1);

namespace Tenant\Contracts\Context;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Tenant\Data\Context\ContextReference;

#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface ContextCodec
{
    public function encode(ContextReference $reference): string;

    public function decode(string $json): ContextReference;
}
