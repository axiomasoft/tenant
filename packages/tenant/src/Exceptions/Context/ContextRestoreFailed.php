<?php

declare(strict_types=1);

namespace Tenant\Exceptions\Context;

use Packman\Attributes\Api;
use Tenant\Exceptions\TenantFailure;
use Throwable;

#[Api(since: '1.0')]
final class ContextRestoreFailed extends TenantFailure
{
    /** @param list<Throwable> $causes */
    public function __construct(array $causes = [])
    {
        parent::__construct('restore_failed', $causes);
    }
}
