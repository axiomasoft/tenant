<?php

declare(strict_types=1);

namespace Tenant\Laravel\Data;

use Packman\Attributes\Api;
use Tenant\Laravel\Contracts\Facilities\ResourceScope;
use Tenant\Laravel\Internal\Facilities\ResourceScopeValidation;

/** Trusted host facet for scoped Laravel facilities; it selects no database route. */
#[Api(since: '1.0')]
final readonly class ScopeDescriptor implements ResourceScope
{
    public function __construct(public string $prefix, public string $durableRoot, public string $ephemeralRoot, public string $sessionRoot)
    {
        ResourceScopeValidation::assertValid($prefix, $durableRoot, $ephemeralRoot, $sessionRoot);
    }
}
