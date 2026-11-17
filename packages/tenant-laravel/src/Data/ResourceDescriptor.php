<?php

declare(strict_types=1);

namespace Tenant\Laravel\Data;

use InvalidArgumentException;
use Packman\Attributes\Api;
use Tenant\Contracts\Context\FacetAccess;

/** Trusted host facet. Never decode this descriptor from a payload. */
#[Api(since: '1.0')]
final readonly class ResourceDescriptor implements FacetAccess
{
    /**
     * @param  array<string, mixed>  $route
     * @param  array<string, mixed>  $options
     */
    public function __construct(public array $route, public string $prefix, public string $durableRoot, public string $ephemeralRoot, public string $sessionRoot, public array $options = [])
    {
        if (($route['driver'] ?? null) !== 'pgsql' || $prefix === '') {
            throw new InvalidArgumentException('facility_configuration');
        }
        foreach (['driver', 'url', 'host', 'port', 'database', 'username', 'read', 'write'] as $key) {
            if (array_key_exists($key, $options)) {
                throw new InvalidArgumentException('routing keys in options');
            }
        }
        foreach ([$durableRoot, $ephemeralRoot, $sessionRoot] as $root) {
            if (! str_starts_with($root, '/') || str_contains($root, "\0") || in_array('..', explode('/', $root), true)) {
                throw new InvalidArgumentException('facility_configuration');
            }
        }
    }
}
