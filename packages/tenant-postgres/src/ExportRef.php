<?php

declare(strict_types=1);
namespace Tenant\Postgres;
use Tenant\Postgres\Internal\CanonicalIntent;
final readonly class ExportRef
{
    public function __construct(public string $exportId, public OperationRef $operation) { CanonicalIntent::reference($exportId); }
}
