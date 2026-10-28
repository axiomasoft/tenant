<?php

declare(strict_types=1);

namespace Tenant\Data;

use Tenant\Attributes\Spi;


#[Spi]
final readonly class VerifiedBinding
{
    public function __construct(public BindingExpectation $expected, public string $ownerId, public string $recoveryGeneration) {}
    public function __serialize(): array { throw new \LogicException('Binding proof is process local'); }
    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void { throw new \LogicException('Process-local value cannot be unserialized'); }
}

