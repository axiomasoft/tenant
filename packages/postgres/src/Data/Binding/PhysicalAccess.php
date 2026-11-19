<?php

declare(strict_types=1);

namespace Tenant\Postgres\Data\Binding;

use LogicException;
use Packman\Attributes\Api;
use Tenant\Contracts\Context\FacetAccess;

/** Trusted process-local descriptor obtained from the physical owner. */
#[Api(since: '1.0')]
final readonly class PhysicalAccess implements FacetAccess
{
    /** @param array<string, mixed> $route */
    public function __construct(public array $route) {}

    public function __serialize(): array
    {
        throw new LogicException('Physical access is process local.');
    }

    /** @param array<mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new LogicException('Physical access is process local.');
    }
}
