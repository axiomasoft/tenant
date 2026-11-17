<?php

declare(strict_types=1);

namespace Tenant\Contracts\Context;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Tenant\Data\Context\Purpose;
use Tenant\Data\Context\ResolvedIsolation;
use Tenant\Data\Context\Selection;

#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface ContextResolver
{
    public function resolve(Selection $selection, Purpose $purpose): ResolvedIsolation;
}
