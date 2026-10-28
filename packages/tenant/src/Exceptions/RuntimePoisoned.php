<?php

declare(strict_types=1);

namespace Tenant\Exceptions;

use Tenant\Attributes\Api;
use Throwable;

#[Api]
final class RuntimePoisoned extends TenantFailure
{
    /** @param list<Throwable> $causes */
    public function __construct(array $causes = []) { parent::__construct('runtime_poisoned', $causes); }
}
