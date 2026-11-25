<?php

declare(strict_types=1);

namespace Tenant\Postgres\Internal\Claim;

use Closure;
use Packman\Attributes\Internal;
use Tenant\Postgres\Admission;
use Tenant\Postgres\Data\Provisioning\ResourceSnapshot;
use Tenant\Postgres\Exceptions\Claim\ClaimConflict;
use Tenant\Postgres\Internal\Database\TemplateClonePostgreSQLManager;
use Tenant\Postgres\Internal\Pool\ResourceSnapshotReader;
use Tenant\Postgres\OperationReceipt;

/** Replay-before-clone pipeline: capture current resource and retain the original operation. */
#[Internal]
final readonly class ReplayableProvision
{
    /** @param Closure(Closure(): void): TemplateClonePostgreSQLManager $clonerFactory */
    public function __construct(private ResourceSnapshotReader $snapshots, private ProvisionJournal $journal, private Closure $clonerFactory) {}

    public function run(string $operationId, ResourceSnapshot $expected, Admission $admission): OperationReceipt
    {
        $receipt = $this->journal->query($operationId, $admission);
        if ($receipt->resourceRef !== $expected->resourceRef || $receipt->incarnation !== $expected->incarnation) {
            throw new ClaimConflict('Operation references another resource.');
        }
        $current = $this->snapshots->capture($expected->resourceRef, $expected->incarnation);
        if (get_object_vars($current) !== get_object_vars($expected)) {
            throw new ClaimConflict('Resource snapshot changed.');
        }
        if ($receipt->status === 'accepted') {
            return $this->journal->execute($operationId, $admission, $this->clonerFactory);
        }
        if (in_array($receipt->status, ['running', 'unknown'], true)) {
            return $this->journal->reconcile($operationId, $admission);
        }

        return $receipt;
    }
}
