<?php

declare(strict_types=1);

namespace Tenant\Postgres\Contracts;

use Tenant\Postgres\Data\ClaimRequest;
use Tenant\Postgres\Data\QualifiedTemplate;

interface TemplateIntegrityVerifier
{
    public function qualify(ClaimRequest $request): QualifiedTemplate;
}
