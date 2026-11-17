<?php

declare(strict_types=1);

namespace Tenant\Data\Context;

use InvalidArgumentException;
use Packman\Attributes\Api;
use Tenant\Data\Composition\CapabilityId;
use Tenant\Internal\Validation\Validate;

#[Api(since: '1.0')]
final readonly class ExtensionRef
{
    /** @param array<string, string> $refs */
    public function __construct(public string $type, public int $version, public bool $required, public array $refs)
    {
        new CapabilityId($type);
        if ($version < 1 || $version > 2147483647 || count($refs) < 1 || count($refs) > 16) {
            throw new InvalidArgumentException('Invalid extension');
        }
        Validate::refs($refs);
    }
}
