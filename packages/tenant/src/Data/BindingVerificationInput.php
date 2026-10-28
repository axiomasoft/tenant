<?php

declare(strict_types=1);

namespace Tenant\Data;

use Tenant\Attributes\Spi;

use Tenant\Contracts\FacetAccess;
#[Spi]
final readonly class BindingVerificationInput
{
    public function __construct(public BindingExpectation $expected, public FacetAccess $access) {}
    public function __serialize(): array { throw new \LogicException('Binding input is process local'); }
    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void { throw new \LogicException('Process-local value cannot be unserialized'); }
}

