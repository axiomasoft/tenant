<?php

declare(strict_types=1);

namespace Tenant\Exceptions;

use Packman\Attributes\Api;
use Tenant\Postgres\Exceptions\Claim\ClaimConflict;

/** Frozen optional owner error, shipped only by axiomasoft/tenant-postgres. */
#[Api(since: '1.0')]
final class IdempotencyConflict extends ClaimConflict {}
