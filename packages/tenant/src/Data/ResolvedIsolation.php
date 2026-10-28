<?php

declare(strict_types=1);

namespace Tenant\Data;

use Tenant\Attributes\Spi;


#[Spi]
final readonly class ResolvedIsolation
{
    public function __construct(public ContextState $context, public FacetBindings $facets)
    {
        if ($context->kind === 'missing') throw new \InvalidArgumentException('Resolver cannot return missing');
    }
    public function __serialize(): array { throw new \LogicException('Resolved isolation is process local'); }
    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void { throw new \LogicException('Process-local value cannot be unserialized'); }
}

