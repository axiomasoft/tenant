<?php

declare(strict_types=1);
namespace Tenant\Postgres;
use Tenant\Postgres\Internal\CanonicalIntent;
final readonly class ExportIntent
{
    public function __construct(public string $resourceRef, public string $incarnation, public string $destinationRef, public string $retentionProfileRef)
    {
        foreach (get_object_vars($this) as $ref) CanonicalIntent::reference($ref);
    }
    public function digest(): string { return CanonicalIntent::digest(['schemaVersion'=>1,'operationKind'=>'export',...get_object_vars($this)]); }
}
