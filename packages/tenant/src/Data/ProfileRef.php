<?php

declare(strict_types=1);

namespace Tenant\Data;

use Tenant\Attributes\Api;

#[Api]
final readonly class ProfileRef
{
    public function __construct(public string $id, public int $version)
    {
        if (strlen($id) > 96 || !preg_match('/^[a-z][a-z0-9._-]*$/D', $id) || $version < 1) throw new \InvalidArgumentException('Invalid profile');
    }
}
