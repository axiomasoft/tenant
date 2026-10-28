<?php

declare(strict_types=1);
namespace Tenant\Postgres;
/** Frozen optional physical owner SPI. */
interface PostgresLifecycle
{
    public function claim(ProvisionIntent $intent,OperationKey $key,Admission $admission):OperationReceipt;
    public function queryOperation(OperationRef $operation,Admission $admission):OperationReceipt;
    public function reconcile(OperationRef $operation,Admission $admission):OperationReceipt;
    public function requestExport(ExportIntent $intent,OperationKey $key,Admission $admission):OperationReceipt;
    public function queryExport(ExportRef $export,Admission $admission):ExportReceipt;
    public function requestDelete(DeleteIntent $intent,OperationKey $key,Admission $admission):OperationReceipt;
    public function cancelIntent(OperationRef $operation,Admission $admission):OperationReceipt;
    public function admitRecovery(RecoveryIntent $intent,Admission $admission):RecoveryPlan;
}
