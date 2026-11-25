<?php

declare(strict_types=1);

namespace Tenant\Postgres\Exceptions\Claim;

use Packman\Attributes\Api;
use RuntimeException;
use Tenant\Exceptions\TenantFailure;

/** Internal compatibility category; details stay only in the trusted causes channel. */
#[Api(since: '1.0')]
class ClaimUnavailable extends TenantFailure
{
    protected const string Reason = 'unavailable';

    /** @param string|array<\Throwable> $causes */
    public function __construct(string|array $causes = [])
    {
        parent::__construct(static::Reason, is_string($causes) ? [new RuntimeException($causes)] : array_values($causes));
    }
}
