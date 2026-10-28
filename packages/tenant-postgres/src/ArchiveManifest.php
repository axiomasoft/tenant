<?php

declare(strict_types=1);
namespace Tenant\Postgres;
use Tenant\Postgres\Internal\CanonicalIntent;
/** Safe immutable archive identity; storage credentials/paths are private to the adapter. */
final readonly class ArchiveManifest
{
    public function __construct(public string $exportId, public string $operationId, public string $resourceRef, public string $incarnation, public string $routeRevision, public string $recoveryGeneration, public string $destinationRef, public string $sha256, public int $sizeBytes)
    {
        foreach ([$exportId,$operationId,$resourceRef,$incarnation,$routeRevision,$recoveryGeneration,$destinationRef] as $ref) CanonicalIntent::reference($ref);
        if (!preg_match('/^[a-f0-9]{64}$/D',$sha256) || $sizeBytes < 1) throw new \InvalidArgumentException('Invalid archive integrity.');
    }
}
