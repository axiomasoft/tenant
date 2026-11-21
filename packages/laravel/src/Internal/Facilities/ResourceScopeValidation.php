<?php

declare(strict_types=1);

namespace Tenant\Laravel\Internal\Facilities;

use InvalidArgumentException;
use Packman\Attributes\Internal;

#[Internal]
final class ResourceScopeValidation
{
    public static function assertValid(string $prefix, string $durableRoot, string $ephemeralRoot, string $sessionRoot): void
    {
        if ($prefix === ':' || ! str_ends_with($prefix, ':') || str_contains($prefix, "\0")) {
            throw new InvalidArgumentException('facility_configuration');
        }
        foreach ([$durableRoot, $ephemeralRoot, $sessionRoot] as $root) {
            if (! str_starts_with($root, '/') || str_contains($root, "\0") || in_array('..', explode('/', $root), true)) {
                throw new InvalidArgumentException('facility_configuration');
            }
        }
    }
}
