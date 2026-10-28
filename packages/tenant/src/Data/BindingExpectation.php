<?php

declare(strict_types=1);

namespace Tenant\Data;

use Tenant\Attributes\Api;

#[Api]
final readonly class BindingExpectation
{
    public function __construct(public string $resourceRef, public string $incarnation, public string $routeRevision)
    {
        if (str_contains($resourceRef, '://')) throw new \InvalidArgumentException('Resource reference cannot be a DSN');
        foreach ([$resourceRef, $incarnation, $routeRevision] as $value) {
            if (strlen($value) > 128 || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._:\/-]*$/D', $value)) throw new \InvalidArgumentException('Invalid binding');
        }
    }
}
