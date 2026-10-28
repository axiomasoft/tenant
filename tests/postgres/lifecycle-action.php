<?php
require __DIR__.'/lifecycle-support.php';
use Tenant\Postgres\{Admission,OperationKey,OperationRef,DeleteIntent,ExportRef};
$scenario=$argv[1];$action=$argv[2];$r=$c->table('tenant_pg_lifecycle_operations')->where('operation_key',$scenario)->first();
check($r!==null,'Original operation missing');$ref=new OperationRef($r->key_namespace,$r->id,$r->resource_ref,$r->incarnation);
$result=match($action) {
    'query'=>$journal->query($ref,$allow),'reconcile'=>$journal->reconcile($ref,$allow),'cancel'=>$journal->cancel($ref,$allow),
    'same'=>$journal->request(new Tenant\Postgres\ExportIntent($r->resource_ref,$r->incarnation,'archive:lab','policy:lab'),new OperationKey('lab',$scenario),$allow),
    'other'=>$journal->request(new Tenant\Postgres\ExportIntent($r->resource_ref,$r->incarnation,'archive:lab','policy:lab'),new OperationKey('lab',$scenario.'-other'),$allow),
    'delete'=>$journal->request(new DeleteIntent($r->resource_ref,$r->incarnation,new ExportRef($r->export_id,$ref),'policy:lab'),new OperationKey('lab',$scenario.'-delete'),$allow),
    default=>throw new RuntimeException('Unknown action'),
};
echo json_encode(get_object_vars($result)),"\n";
