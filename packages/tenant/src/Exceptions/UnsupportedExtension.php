<?php

declare(strict_types=1);

namespace Tenant\Exceptions;

use Tenant\Attributes\Api;
use Throwable;

#[Api]
final class UnsupportedExtension extends TenantFailure
{
    /** @param list<Throwable> $causes */
    public function __construct(array $causes = []) { parent::__construct('unsupported_extension', $causes); }
}
