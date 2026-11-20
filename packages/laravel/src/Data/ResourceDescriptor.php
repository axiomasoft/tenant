<?php

declare(strict_types=1);

namespace Tenant\Laravel\Data;

use InvalidArgumentException;
use Packman\Attributes\Api;
use Tenant\Laravel\Contracts\Facilities\ResourceScope;
use Tenant\Laravel\Internal\Facilities\ResourceScopeValidation;

/** Trusted host facet. Never decode this descriptor from a payload. */
#[Api(since: '1.0')]
final readonly class ResourceDescriptor implements ResourceScope
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
        foreach (['driver', 'url', 'host', 'port', 'database', 'username', 'password', 'read', 'write', 'connect_via_database', 'connect_via_port', 'search_path', 'schema', 'server_options', 'prefix'] as $key) {
            if (array_key_exists($key, $options)) {
                throw new InvalidArgumentException('routing keys in options');
            }
        }
        ResourceScopeValidation::assertValid($prefix, $durableRoot, $ephemeralRoot, $sessionRoot);
    }
}
