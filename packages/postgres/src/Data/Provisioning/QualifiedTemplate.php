<?php

declare(strict_types=1);

namespace Tenant\Postgres\Data\Provisioning;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;

#[Spi(kind: SpiKind::Call, since: '1.0')]
final readonly class QualifiedTemplate
{
    public function __construct(
        public string $clusterId,
        public string $templateId,
        public int $templateVersion,
        public string $artifactDigest,
    ) {}
}
