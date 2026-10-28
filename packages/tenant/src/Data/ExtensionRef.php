<?php

declare(strict_types=1);

namespace Tenant\Data;

use Tenant\Attributes\Api;
use Tenant\Internal\Validate;

#[Api]
final readonly class ExtensionRef
{
    /** @param array<string, string> $refs */
    public function __construct(public string $type, public int $version, public bool $required, public array $refs)
    {
        new CapabilityId($type);
        if ($version < 1 || $version > 2147483647 || count($refs) < 1 || count($refs) > 16) throw new \InvalidArgumentException('Invalid extension');
        Validate::refs($refs);
    }
}
