<?php

declare(strict_types=1);

namespace Tenant\Postgres\Contracts\Provisioning;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Tenant\Postgres\Data\Provisioning\ClaimRequest;
use Tenant\Postgres\Data\Provisioning\QualifiedTemplate;

#[Spi(kind: SpiKind::Implement, since: '1.0')]
interface TemplateIntegrityVerifier
{
    public function qualify(ClaimRequest $request): QualifiedTemplate;
}
