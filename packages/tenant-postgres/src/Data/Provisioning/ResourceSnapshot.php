<?php

declare(strict_types=1);

namespace Tenant\Postgres\Data\Provisioning;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

/** Captured catalog binding, not an authority or proof of current incarnation. */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final readonly class ResourceSnapshot
{
    public function __construct(
        public string $resourceRef,
        public string $incarnation,
        public int $templateVersion,
        public string $clusterId,
        public string $databaseName,
    ) {}
}
