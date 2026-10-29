<?php

declare(strict_types=1);

namespace Tenant\Postgres\Contracts;

use Tenant\Contracts\BindingVerifier;
use Tenant\Postgres\Data\ResolvedBinding;

/** Current, verified process-local access; a reference alone grants no host rights. */
interface ResourceBindings extends BindingVerifier
{
    public function resolve(string $resourceRef): ResolvedBinding;
}
