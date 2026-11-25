<?php

declare(strict_types=1);

namespace Tenant\Data\Context;

use InvalidArgumentException;
use Packman\Attributes\Api;

#[Api(since: '1.0')]
final readonly class ProfileRef
{
    public function __construct(public string $id, public int $version)
    {
        if (strlen($id) > 96 || preg_match('/^[a-z][a-z0-9._-]*$/D', $id) !== 1 || $version < 1 || $version > 2147483647) {
            throw new InvalidArgumentException('Invalid profile');
        }
    }
}
