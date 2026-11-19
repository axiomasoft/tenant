<?php

declare(strict_types=1);

namespace Tenant\Attributes;

use Attribute;
use Packman\Attributes\Api as PackmanApi;

/**
 * Legacy SPI surface marker retained for backwards compatibility.
 *
 * @deprecated Use {@see \Packman\Attributes\Spi} instead.
 */
#[PackmanApi(since: '1.0')]
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Spi
{
    public function __construct(public ?string $since = null) {}
}
