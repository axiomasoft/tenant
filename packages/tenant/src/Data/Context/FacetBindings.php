<?php

declare(strict_types=1);

namespace Tenant\Data\Context;

use LogicException;
use OutOfBoundsException;
use Packman\Attributes\Api;
use Tenant\Contracts\Context\FacetAccess;
use Tenant\Data\Composition\CapabilityId;

#[Api(since: '1.0')]
final readonly class FacetBindings
{
    /** @param array<string, FacetAccess> $facets */
    public function __construct(private array $facets) {}

    public function require(CapabilityId $id): FacetAccess
    {
        return $this->facets[$id->id] ?? throw new OutOfBoundsException('Unsupported capability');
    }

    public function __serialize(): array
    {
        throw new LogicException('Facet bindings are process local');
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new LogicException('Process-local value cannot be unserialized');
    }
}
