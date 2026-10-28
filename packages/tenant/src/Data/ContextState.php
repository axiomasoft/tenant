<?php

declare(strict_types=1);

namespace Tenant\Data;

use Tenant\Attributes\Api;

#[Api]
final readonly class ContextState
{
    private function __construct(public string $kind, public ?IsolationContext $context = null) {}
    public static function missing(): self { return new self('missing'); }
    public static function central(): self { return new self('central'); }
    public static function active(IsolationContext $context): self { return new self('active', $context); }
}
