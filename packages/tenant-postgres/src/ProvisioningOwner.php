<?php

declare(strict_types=1);
namespace Tenant\Postgres;
use Tenant\Postgres\Exceptions\ClaimConflict;
use Tenant\Postgres\Internal\{ClaimReceiptStore,ClaimWithPool,ProvisionJournal,LifecycleJournal,ErrorBoundary};
/** One optional physical owner; public errors obey the frozen typed reason contract. */
final readonly class ProvisioningOwner implements PostgresLifecycle
{
    public function __construct(private ClaimWithPool $claims,private ClaimReceiptStore $receipts,private ProvisionJournal $journal,private ?LifecycleJournal $lifecycle=null,private ?RecoveryOperator $recovery=null) {}
    public function claim(ProvisionIntent $intent,OperationKey $key,Admission $admission):OperationReceipt
    {
        return ErrorBoundary::run(fn()=>$this->claims->claim($intent,$key,$admission),'effect');
    }
    public function queryOperation(OperationRef $ref,Admission $admission):OperationReceipt
    {
        return ErrorBoundary::run(function()use($ref,$admission):OperationReceipt {
            $admission->assertAllowed('query',$ref->resourceRef);
            $r=$this->lifecycle?->hasOperation($ref->operationId)?$this->lifecycle->query($ref,$admission):($this->receipts->hasOperation($ref->operationId)?$this->receipts->query($ref->operationId,$admission):$this->journal->query($ref->operationId,$admission));return $this->same($ref,$r);
        },'query');
    }
    public function reconcile(OperationRef $ref,Admission $admission):OperationReceipt
    {
        return ErrorBoundary::run(function()use($ref,$admission):OperationReceipt {
            $admission->assertAllowed('reconcile',$ref->resourceRef);
            if($this->lifecycle?->hasOperation($ref->operationId))return $this->lifecycle->reconcile($ref,$admission);
            $this->journal->assertReference($ref->namespace,$ref->operationId,$ref->resourceRef,$ref->incarnation,$admission,'reconcile');return $this->same($ref,$this->journal->reconcile($ref->operationId,$admission));
        },'effect');
    }
    public function cancelIntent(OperationRef $ref,Admission $admission):OperationReceipt
    {
        return ErrorBoundary::run(function()use($ref,$admission):OperationReceipt {
            $admission->assertAllowed('cancel',$ref->resourceRef);
            if($this->lifecycle?->hasOperation($ref->operationId))return $this->lifecycle->cancel($ref,$admission);
            $this->journal->assertReference($ref->namespace,$ref->operationId,$ref->resourceRef,$ref->incarnation,$admission,'cancel');return $this->same($ref,$this->journal->cancel($ref->operationId,$admission));
        },'effect');
    }
    public function requestExport(ExportIntent $intent,OperationKey $key,Admission $admission):OperationReceipt {return ErrorBoundary::run(fn()=>$this->physical()->request($intent,$key,$admission),'effect');}
    public function queryExport(ExportRef $export,Admission $admission):ExportReceipt {return ErrorBoundary::run(fn()=>$this->physical()->queryExport($export,$admission),'query');}
    public function requestDelete(DeleteIntent $intent,OperationKey $key,Admission $admission):OperationReceipt {return ErrorBoundary::run(fn()=>$this->physical()->request($intent,$key,$admission),'effect');}
    public function admitRecovery(RecoveryIntent $intent,Admission $admission):RecoveryPlan {return ErrorBoundary::run(fn()=>($this->recovery??throw new \Tenant\Exceptions\RecoveryUnsupported())->admit($intent,$admission),'recovery');}
    private function physical():LifecycleJournal {return $this->lifecycle??throw new \Tenant\Exceptions\UnsupportedProfile();}
    private function same(OperationRef $ref,OperationReceipt $receipt):OperationReceipt
    {
        if($receipt->key?->namespace!==$ref->namespace||$receipt->operationId!==$ref->operationId||$receipt->resourceRef!==$ref->resourceRef||$receipt->incarnation!==$ref->incarnation)throw new \Tenant\Exceptions\OriginalOperationMissing('Operation reference does not match the original.');return $receipt;
    }
}
