<?php

declare(strict_types=1);

namespace Tenant\Data\Context;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class ContextState
{
    private function __construct(public string $kind, public ?IsolationContext $context = null) {}

    public static function missing(): self
    {
        return new self('missing');
    }

    public static function central(): self
    {
        return new self('central');
    }

    public static function active(IsolationContext $context): self
    {
        return new self('active', $context);
    }
}
