<?php

declare(strict_types=1);

namespace Tenant\Data;

use Tenant\Attributes\Api;

#[Api]
final readonly class Purpose
{
    public function __construct(public string $id)
    {
        if (strlen($id) > 128 || !preg_match('/^[a-z][a-z0-9._-]*:[a-z][a-z0-9._\/-]*$/D', $id)) throw new \InvalidArgumentException('Invalid purpose');
    }
}
