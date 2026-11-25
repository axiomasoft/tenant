<?php

declare(strict_types=1);

namespace Tenant\Postgres;

use Packman\Attributes\Api;
use Tenant\Postgres\Internal\Claim\CanonicalIntent;

#[Api(since: '1.0')]
final readonly class ExportIntent
{
    public function __construct(public string $resourceRef, public string $incarnation, public string $destinationRef, public string $retentionProfileRef)
    {
        foreach ([$resourceRef, $incarnation, $destinationRef, $retentionProfileRef] as $ref) {
            CanonicalIntent::reference($ref);
        }
    }

    public function digest(): string
    {
        return CanonicalIntent::digest([
            'schemaVersion' => 1,
            'operationKind' => 'export',
            'resourceRef' => $this->resourceRef,
            'incarnation' => $this->incarnation,
            'destinationRef' => $this->destinationRef,
            'retentionProfileRef' => $this->retentionProfileRef,
        ]);
    }
}
