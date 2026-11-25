<?php

declare(strict_types=1);

namespace Tenant\Exceptions;

use Override;
use Packman\Attributes\Api;
use Packman\Errors\Data\ErrorDescriptor;
use Packman\Errors\Exceptions\CodedException;
use Tenant\Errors\Enums\TenantErrorCode;
use Throwable;

#[Api(since: '1.0')]
class TenantFailure extends CodedException
{
    /** @param list<Throwable> $failures */
    public function __construct(private readonly string $failureReason, private readonly array $failures = [])
    {
        parent::__construct($failureReason, 0, $failures[0] ?? null);
    }

    public function reason(): string
    {
        return $this->failureReason;
    }

    /** @return list<Throwable> */
    public function causes(): array
    {
        return $this->failures;
    }

    #[Override]
    public function errorDescriptor(): ErrorDescriptor
    {
        $code = TenantErrorCode::fromReason($this->failureReason);

        return new ErrorDescriptor(
            code: $code,
            category: $code->category(),
            retryable: $code->isRetryable(),
        );
    }
}
