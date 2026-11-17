<?php

declare(strict_types=1);

namespace Tenant\Exceptions;

use Packman\Attributes\Api;
use Tenant\Postgres\Exceptions\Claim\ClaimUnavailable;

/** Frozen optional owner error, shipped only by axiomasoft/tenant-postgres. */
#[Api(since: '1.0')]
final class OriginalOperationMissing extends ClaimUnavailable
{
    protected const string Reason = 'original_operation_missing';
}
