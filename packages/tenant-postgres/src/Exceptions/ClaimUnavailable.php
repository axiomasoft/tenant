<?php

declare(strict_types=1);
namespace Tenant\Postgres\Exceptions;
use Tenant\Exceptions\TenantFailure;
/** Internal compatibility category; details stay only in the trusted causes channel. */
class ClaimUnavailable extends TenantFailure
{
    protected const string Reason='unavailable';
    public function __construct(string|array $causes=[])
    {
        parent::__construct(static::Reason,is_string($causes)?[new \RuntimeException($causes)]:$causes);
    }
}
