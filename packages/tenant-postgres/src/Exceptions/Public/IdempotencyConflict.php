<?php

declare(strict_types=1);
namespace Tenant\Exceptions;
/** Frozen optional owner error, shipped only by axiomasoft/tenant-postgres. */
final class IdempotencyConflict extends \Tenant\Postgres\Exceptions\ClaimConflict
{
}
