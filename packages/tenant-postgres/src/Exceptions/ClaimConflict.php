<?php

declare(strict_types=1);
namespace Tenant\Postgres\Exceptions;
use Tenant\Exceptions\TenantFailure;
/** Internal compatibility category. Public ports emit the frozen IdempotencyConflict type. */
class ClaimConflict extends TenantFailure
{
    public function __construct(string|array $causes=[])
    {
        parent::__construct('idempotency_conflict',is_string($causes)?[new \RuntimeException($causes)]:$causes);
    }
}
