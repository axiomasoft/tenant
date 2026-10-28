<?php

declare(strict_types=1);
namespace Tenant\Postgres;
use Tenant\Postgres\Exceptions\ClaimConflict;
use Tenant\Postgres\Internal\{ClaimReceiptStore,ClaimWithPool,ProvisionJournal};
/** Public provisioning subset; archive/delete/recovery policy remains for P4.2. */
final readonly class ProvisioningOwner implements PostgresLifecycle
{
    public function __construct(private ClaimWithPool $claims,private ClaimReceiptStore $receipts,private ProvisionJournal $journal) {}
    public function claim(ProvisionIntent $intent,OperationKey $key,Admission $admission):OperationReceipt{return $this->claims->claim($intent,$key,$admission);}
    public function queryOperation(OperationRef $ref,Admission $admission):OperationReceipt
    {
        $r=$this->receipts->hasOperation($ref->operationId)?$this->receipts->query($ref->operationId,$admission):$this->journal->query($ref->operationId,$admission);return $this->same($ref,$r);
    }
    public function reconcile(OperationRef $ref,Admission $admission):OperationReceipt{$this->journal->assertReference($ref->namespace,$ref->operationId,$ref->resourceRef,$ref->incarnation,$admission,'reconcile');return $this->same($ref,$this->journal->reconcile($ref->operationId,$admission));}
    public function cancelIntent(OperationRef $ref,Admission $admission):OperationReceipt{$this->journal->assertReference($ref->namespace,$ref->operationId,$ref->resourceRef,$ref->incarnation,$admission,'cancel');return $this->same($ref,$this->journal->cancel($ref->operationId,$admission));}
    public function requestExport(ExportIntent $intent,OperationKey $key,Admission $admission): OperationReceipt { throw new \Tenant\Exceptions\UnsupportedProfile(); }
    public function queryExport(ExportRef $export,Admission $admission): ExportReceipt { throw new \Tenant\Exceptions\UnsupportedProfile(); }
    public function requestDelete(DeleteIntent $intent,OperationKey $key,Admission $admission): OperationReceipt { throw new \Tenant\Exceptions\UnsupportedProfile(); }
    public function admitRecovery(RecoveryIntent $intent,Admission $admission): RecoveryPlan { throw new \Tenant\Exceptions\UnsupportedProfile(); }
    private function same(OperationRef $ref,OperationReceipt $receipt):OperationReceipt
    {
        if($receipt->key?->namespace!==$ref->namespace||$receipt->operationId!==$ref->operationId||$receipt->resourceRef!==$ref->resourceRef||$receipt->incarnation!==$ref->incarnation)throw new ClaimConflict('Operation reference does not match the original.');return $receipt;
    }
}
