<?php

declare(strict_types=1);

namespace Tenant\Data\Runtime;

use LogicException;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final class FrameToken
{
    public function __construct() {}

    private function __clone() {}

    public function __serialize(): array
    {
        throw new LogicException('Frame token cannot be serialized');
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new LogicException('Frame token cannot be unserialized');
    }
}
