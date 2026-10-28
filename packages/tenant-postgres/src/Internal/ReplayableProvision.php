<?php

declare(strict_types=1);
namespace Tenant\Postgres\Internal;
use Closure;use Tenant\Postgres\{Admission,OperationReceipt};use Tenant\Postgres\Data\{ResourceSnapshot};use Tenant\Postgres\Exceptions\ClaimConflict;
/** Replay-before-clone pipeline: capture current resource and retain the original operation. */
final readonly class ReplayableProvision
{
    public function __construct(private ResourceSnapshotReader $snapshots,private ProvisionJournal $journal,private Closure $clonerFactory) {}
    public function run(string $operationId,ResourceSnapshot $expected,Admission $admission):OperationReceipt
    {
        $receipt=$this->journal->query($operationId,$admission);
        if($receipt->resourceRef!==$expected->resourceRef||$receipt->incarnation!==$expected->incarnation)throw new ClaimConflict('Operation references another resource.');
        $current=$this->snapshots->capture($expected->resourceRef,$expected->incarnation);
        if($current!=$expected)throw new ClaimConflict('Resource snapshot changed.');
        if($receipt->status==='accepted')return $this->journal->execute($operationId,$admission,$this->clonerFactory);
        if(in_array($receipt->status,['running','unknown'],true))return $this->journal->reconcile($operationId,$admission);
        return $receipt;
    }
}
