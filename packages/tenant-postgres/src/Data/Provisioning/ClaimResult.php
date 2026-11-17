<?php

declare(strict_types=1);

namespace Tenant\Postgres\Data\Provisioning;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

#[Spi(kind: SpiKind::Call, since: '1.0')]
final readonly class ClaimResult
{
    public function __construct(
        public string $resourceId,
        public bool $replayed,
    ) {}
}
