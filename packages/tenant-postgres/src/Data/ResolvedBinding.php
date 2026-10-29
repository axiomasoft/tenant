<?php

declare(strict_types=1);

namespace Tenant\Postgres\Data;

use Tenant\Data\BindingExpectation;

final readonly class ResolvedBinding
{
    public function __construct(
        public BindingExpectation $expected,
        public PhysicalAccess $access,
        public string $recoveryGeneration,
    ) {}

    public function __serialize(): array
    {
        throw new \LogicException('Resolved binding is process local.');
    }

    public function __unserialize(array $data): void
    {
        throw new \LogicException('Resolved binding is process local.');
    }
}
