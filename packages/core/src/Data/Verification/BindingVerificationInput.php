<?php

declare(strict_types=1);

namespace Tenant\Data\Verification;

use LogicException;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Tenant\Contracts\Context\FacetAccess;

#[Spi(kind: SpiKind::Call, since: '1.0')]
final readonly class BindingVerificationInput
{
    public function __construct(public BindingExpectation $expected, public FacetAccess $access) {}

    public function __serialize(): array
    {
        throw new LogicException('Binding input is process local');
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new LogicException('Process-local value cannot be unserialized');
    }
}
