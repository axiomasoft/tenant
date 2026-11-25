<?php

declare(strict_types=1);

namespace Tenant\Contracts\Runtime;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Tenant\Contracts\Context\FacetAccess;
use Tenant\Data\Composition\CapabilityId;
use Tenant\Data\Context\ContextState;

#[Spi(kind: SpiKind::Call, since: '1.0')]
interface RestoreContext
{
    public function previous(): ContextState;

    public function require(CapabilityId $id): FacetAccess;
}
