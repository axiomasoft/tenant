<?php

declare(strict_types=1);

namespace Tenant\Contracts\Context;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface FacetAccess {}
