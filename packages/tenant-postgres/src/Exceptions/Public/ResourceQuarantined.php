<?php

declare(strict_types=1);
namespace Tenant\Exceptions;
/** Frozen optional owner error, shipped only by axiomasoft/tenant-postgres. */
final class ResourceQuarantined extends \Tenant\Postgres\Exceptions\ClaimUnavailable
{
    protected const string Reason='resource_quarantined';
}
