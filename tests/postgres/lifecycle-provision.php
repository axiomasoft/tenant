<?php
require __DIR__.'/lifecycle-support.php';
use Tenant\Postgres\{PostgresFactory,OperationKey,ProvisionIntent};
use Tenant\Postgres\Data\TemplateTrust;
$proof=json_decode(file_get_contents($m['root'].'/receipt-race-trust.json'),true,flags:JSON_THROW_ON_ERROR);
$template='12121212-1212-4212-8212-121212121212';$cluster='45454545-4545-4545-8545-454545454545';
$c->table('tenant_pg_clusters')->insert(['id'=>$cluster,'status'=>'active','template_version'=>7,'capacity'=>1,'database_count'=>0]);
$trust=new TemplateTrust([$template=>['payload'=>$proof['payload'],'signature'=>$proof['signature'],'trusted_key_id'=>'lab']],['lab'=>$proof['key']]);
$marker=['protocol'=>'tenant.binding/1','owner_id'=>'template','resource_ref'=>'template','incarnation'=>'template','recovery_generation'=>'template'];
$owner=PostgresFactory::owner($c,$db->getDatabaseManager(),$trust,$runtimeRoute,static fn(string $id):string=>$base.'_'.str_replace('-','',$id).'_test',$m['runtime'],$marker,$archives,$anchor,$policy);
$intent=new ProvisionIntent('lab:provision',$cluster,$template,7,$proof['digest'],['policy'=>'lab-only']);$key=new OperationKey('lab','anchored-provision');
$first=$owner->claim($intent,$key,$allow);check($first->status==='succeeded' && $owner->claim($intent,$key,$allow)==$first,'Anchored public provision failed');
check($anchor->entries()[$key->storageKey()]['operationId']===$first->operationId,'Provision receipt absent from independent history');
$row=$c->table('tenant_pg_resources')->where('resource_ref',$first->resourceRef)->first();check($row->recovery_generation===$anchor->generation(),'Provision did not stamp external generation');
// Lab host separately marks technical readiness; no paid/trial/commercial grant is created.
$c->table('tenant_pg_resources')->where('id',$row->id)->update(['status'=>'ready']);
$export=$owner->requestExport(exportIntent($row),new OperationKey('lab','anchored-export'),new Tenant\Postgres\Admission(static fn()=>true));check($export->status==='succeeded','Export of provisioned resource failed');
$history=$c->table('tenant_pg_provision_attempts')->where('id',$first->operationId)->first();
$c->table('tenant_pg_provision_attempts')->where('id',$first->operationId)->delete();
refuse(fn()=>$owner->claim($intent,$key,$allow),'retention_history_missing');
$c->table('tenant_pg_provision_attempts')->insert((array)$history);
refuse(fn()=>$owner->requestExport(exportIntent($row),$key,new Tenant\Postgres\Admission(static fn()=>true)),'another kind');
echo json_encode(['status'=>'passed','scope'=>'public factory reuses real signed P4.1 empty-pool clone, marker and original receipt; provision history retained externally; lifecycle uses same target; cross-kind replay refused','operationId'=>$first->operationId]),"\n";
