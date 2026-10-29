<?php

declare(strict_types=1);
namespace Tenant\Exceptions;
/** Frozen optional owner error, shipped only by axiomasoft/tenant-postgres. */
final class OperationUnknown extends \Tenant\Postgres\Exceptions\ClaimUnavailable
{
    protected const string Reason='operation_unknown';
}
