<?php

declare(strict_types=1);

namespace Tenant\Postgres\Exceptions\Claim;

use Packman\Attributes\Api;
use RuntimeException;
use Tenant\Exceptions\TenantFailure;

/** Internal compatibility category. Public ports emit the frozen IdempotencyConflict type. */
#[Api(since: '1.0')]
class ClaimConflict extends TenantFailure
{
    /** @param string|array<\Throwable> $causes */
    public function __construct(string|array $causes = [])
    {
        parent::__construct('idempotency_conflict', is_string($causes) ? [new RuntimeException($causes)] : array_values($causes));
    }
}
