<?php

declare(strict_types=1);

namespace Tenant\Attributes;

use Attribute;
use Packman\Attributes\Api as PackmanApi;

/**
 * Legacy public API surface marker retained for backwards compatibility.
 *
 * @deprecated Use {@see PackmanApi} instead.
 */
#[PackmanApi(since: '1.0')]
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Api
{
    public function __construct(public ?string $since = null) {}
}
