<?php

declare(strict_types=1);

namespace Tenant\Exceptions\Runtime;

use Packman\Attributes\Api;
use Tenant\Exceptions\TenantFailure;
use Throwable;

#[Api(since: '1.0')]
final class FrameLeak extends TenantFailure
{
    /** @param list<Throwable> $causes */
    public function __construct(array $causes = [])
    {
        parent::__construct('frame_leak', $causes);
    }
}
