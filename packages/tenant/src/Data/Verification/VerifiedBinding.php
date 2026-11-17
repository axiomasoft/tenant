<?php

declare(strict_types=1);

namespace Tenant\Data\Verification;

use LogicException;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

#[Spi(kind: SpiKind::Call, since: '1.0')]
final readonly class VerifiedBinding
{
    public function __construct(public BindingExpectation $expected, public string $ownerId, public string $recoveryGeneration) {}

    public function __serialize(): array
    {
        throw new LogicException('Binding proof is process local');
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new LogicException('Process-local value cannot be unserialized');
    }
}
