<?php

declare(strict_types=1);

namespace Tenant\Postgres\Contracts;

use Tenant\Postgres\Data\ClaimRequest;
use Tenant\Postgres\Data\ClaimResult;

interface ProvisioningClaim
{
    public function claim(ClaimRequest $request): ClaimResult;
}
