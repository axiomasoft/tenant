<?php

declare(strict_types=1);

namespace Tenant\Postgres;

use Packman\Attributes\Api;
use Tenant\Postgres\Internal\Claim\CanonicalIntent;

#[Api(since: '1.0')]
final readonly class ExportRef
{
    public function __construct(public string $exportId, public OperationRef $operation)
    {
        CanonicalIntent::reference($exportId);
    }
}
