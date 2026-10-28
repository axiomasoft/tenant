<?php

declare(strict_types=1);

namespace Tenant\Postgres\Data;

use Tenant\Contracts\FacetAccess;

/** Trusted process-local descriptor obtained from the physical owner. */
final readonly class PhysicalAccess implements FacetAccess
{
    /** @param array<string, mixed> $route */
    public function __construct(public array $route) {}
    public function __serialize(): array { throw new \LogicException('Physical access is process local.'); }
    public function __unserialize(array $data): void { throw new \LogicException('Physical access is process local.'); }
}
