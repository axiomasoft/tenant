<?php

declare(strict_types=1);

namespace Tenant\Exceptions;

use Tenant\Attributes\Api;
use Throwable;

#[Api]
final class SelectionLost extends TenantFailure
{
    /** @param list<Throwable> $causes */
    public function __construct(array $causes = []) { parent::__construct('selection_lost', $causes); }
}
