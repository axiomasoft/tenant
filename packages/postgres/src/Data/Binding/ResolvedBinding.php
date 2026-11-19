<?php

declare(strict_types=1);

namespace Tenant\Postgres\Data\Binding;

use LogicException;
use Packman\Attributes\Api;
use Tenant\Data\Verification\BindingExpectation;

#[Api(since: '1.0')]
final readonly class ResolvedBinding
{
    public function __construct(
        public BindingExpectation $expected,
        public PhysicalAccess $access,
        public string $recoveryGeneration,
    ) {}

    public function __serialize(): array
    {
        throw new LogicException('Resolved binding is process local.');
    }

    /** @param array<mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new LogicException('Resolved binding is process local.');
    }
}
