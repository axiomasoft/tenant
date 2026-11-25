<?php

declare(strict_types=1);

namespace Tenant\Postgres\Contracts\Provisioning;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Tenant\Contracts\Verification\BindingVerifier;
use Tenant\Postgres\Data\Binding\ResolvedBinding;

/** Current, verified process-local access; a reference alone grants no host rights. */
#[Spi(kind: SpiKind::Call, since: '1.0')]
interface ResourceBindings extends BindingVerifier
{
    public function resolve(string $resourceRef): ResolvedBinding;
}
