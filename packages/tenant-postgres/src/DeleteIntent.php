<?php

declare(strict_types=1);

namespace Tenant\Postgres;

use InvalidArgumentException;
use Packman\Attributes\Api;
use Tenant\Postgres\Internal\Claim\CanonicalIntent;

#[Api(since: '1.0')]
final readonly class DeleteIntent
{
    public function __construct(public string $resourceRef, public string $incarnation, public ?ExportRef $exportRef, public string $policyIntentRef)
    {
        foreach ([$resourceRef, $incarnation, $policyIntentRef] as $ref) {
            CanonicalIntent::reference($ref);
        }
        if ($exportRef !== null && ($exportRef->operation->resourceRef !== $resourceRef || $exportRef->operation->incarnation !== $incarnation)) {
            throw new InvalidArgumentException('Foreign original export reference.');
        }
    }

    public function digest(): string
    {
        return CanonicalIntent::digest(['schemaVersion' => 1, 'operationKind' => 'delete', 'resourceRef' => $this->resourceRef, 'incarnation' => $this->incarnation, 'policyIntentRef' => $this->policyIntentRef, 'exportRef' => $this->exportRef === null ? null : ['exportId' => $this->exportRef->exportId, 'operation' => get_object_vars($this->exportRef->operation)]]);
    }
}
