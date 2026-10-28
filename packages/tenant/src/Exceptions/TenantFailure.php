<?php

declare(strict_types=1);

namespace Tenant\Exceptions;

use Tenant\Attributes\Api;
use Throwable;

#[Api]
class TenantFailure extends \RuntimeException
{
    /** @param list<Throwable> $failures */
    public function __construct(private readonly string $failureReason, private readonly array $failures = [])
    {
        parent::__construct($failureReason, 0, $failures[0] ?? null);
    }

    public function reason(): string { return $this->failureReason; }

    /** @return list<Throwable> */
    public function causes(): array { return $this->failures; }
}
