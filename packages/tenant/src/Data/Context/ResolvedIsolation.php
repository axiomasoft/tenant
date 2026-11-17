<?php

declare(strict_types=1);

namespace Tenant\Data\Context;

use InvalidArgumentException;
use LogicException;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

#[Spi(kind: SpiKind::Call, since: '1.0')]
final readonly class ResolvedIsolation
{
    public function __construct(public ContextState $context, public FacetBindings $facets)
    {
        if ($context->kind === 'missing') {
            throw new InvalidArgumentException('Resolver cannot return missing');
        }
    }

    public function __serialize(): array
    {
        throw new LogicException('Resolved isolation is process local');
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new LogicException('Process-local value cannot be unserialized');
    }
}
