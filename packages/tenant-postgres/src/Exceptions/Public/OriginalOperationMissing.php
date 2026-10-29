<?php

declare(strict_types=1);
namespace Tenant\Exceptions;
/** Frozen optional owner error, shipped only by axiomasoft/tenant-postgres. */
final class OriginalOperationMissing extends \Tenant\Postgres\Exceptions\ClaimUnavailable
{
    protected const string Reason='original_operation_missing';
}
