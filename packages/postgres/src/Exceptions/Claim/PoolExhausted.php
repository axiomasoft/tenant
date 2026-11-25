<?php

declare(strict_types=1);

namespace Tenant\Postgres\Exceptions\Claim;

use Packman\Attributes\Api;

#[Api(since: '1.0')]
final class PoolExhausted extends ClaimUnavailable {}
