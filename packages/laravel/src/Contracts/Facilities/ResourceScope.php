<?php

declare(strict_types=1);

namespace Tenant\Laravel\Contracts\Facilities;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Tenant\Contracts\Context\FacetAccess;

/** Trusted resource namespace for Laravel cache, session and storage facilities. */
#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface ResourceScope extends FacetAccess
{
    public string $prefix { get; }

    public string $durableRoot { get; }

    public string $ephemeralRoot { get; }

    public string $sessionRoot { get; }
}
