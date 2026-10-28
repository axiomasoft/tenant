<?php

declare(strict_types=1);

namespace Tenant\Attributes;

#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class Api
{
    public function __construct(public string $since = '1.0.0') {}
}
