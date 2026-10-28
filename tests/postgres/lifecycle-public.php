<?php
require __DIR__.'/lifecycle-support.php';
use Tenant\Postgres\{PostgresFactory,PostgresLifecycle,OperationKey};
use Tenant\Postgres\Data\TemplateTrust;
$r=makeResource('public');
$owner=PostgresFactory::owner($c,$db->getDatabaseManager(),new TemplateTrust([],[]),$runtimeRoute,static fn(string $id):string=>$base.'_'.str_replace('-','',$id).'_test',$m['runtime'],[], $archives,$anchor,$policy);
check($owner instanceof PostgresLifecycle,'Missing public port');
$export=$owner->requestExport(exportIntent($r),new OperationKey('lab','public-export'),$allow);check($owner->queryOperation(operationRef($export),$allow)==$export,'Public original receipt changed');
check($owner->queryExport($export->exportRef,$allow)->state==='ready','Public archive unavailable');
$delete=$owner->requestDelete(deleteIntent($r,$export),new OperationKey('lab','public-delete'),$allow);check($delete->status==='succeeded','Public owner did not perform DROP');
check($owner->queryOperation(operationRef($delete),$allow)==$delete,'Public tombstone unavailable');
// Absent optional capability is explicit; technical IO requires host admission, not a paid grant.
$absent=PostgresFactory::owner($c,$db->getDatabaseManager(),new TemplateTrust([],[]),$runtimeRoute,static fn(string $id):string=>'unused_test',$m['runtime'],[]);
refuse(fn()=>$absent->requestExport(exportIntent($r),new OperationKey('lab','absent'),$allow),'writer/verifier');
echo json_encode(['status'=>'passed','scope'=>'public PostgresLifecycle factory, original query/export/delete, explicit capability/policy refusal; no commercial grant implied','owner'=>'tenant-postgres','tables'=>['tenant_pg_resources','tenant_pg_lifecycle_operations'],'migrationOwner'=>'axiomasoft/tenant-postgres']),"\n";
