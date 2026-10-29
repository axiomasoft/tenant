<?php
require __DIR__.'/lifecycle-support.php';
use Tenant\Data\{BindingExpectation,BindingVerificationInput};
use Tenant\Postgres\{OperationKey,ProvisionIntent,PostgresFactory};
use Tenant\Postgres\Data\{ResourceSnapshot,TemplateTrust,PhysicalAccess,ClaimRequest};
use Tenant\Postgres\Internal\{ClaimReceiptStore,ClaimResource,ClaimWithPool,PendingPool,ProvisionJournal,PostgresBindingVerifier};
use Tenant\Postgres\Internal\Database\{TemplateIntegrityVerifier,TemplateIntegrityLock,SchemaCatalogueSerializer,TemplateClonePostgreSQLManager,MarkerWriter};
$proof=json_decode(file_get_contents($m['root'].'/review-trust.json'),true,flags:JSON_THROW_ON_ERROR);
$template='12121212-1212-4212-8212-121212121212';$cluster='13131313-1313-4313-8313-131313131313';
$trust=new TemplateTrust([$template=>['payload'=>$proof['payload'],'signature'=>$proof['signature'],'trusted_key_id'=>'lab']],['lab'=>$proof['key']]);
$templateMarker=['protocol'=>'tenant.binding/1','owner_id'=>'template','resource_ref'=>'template','incarnation'=>'template','recovery_generation'=>'template'];
$integrity=new TemplateIntegrityVerifier('lab',new SchemaCatalogueSerializer,new TemplateIntegrityLock,$trust,$db->getDatabaseManager(),$factory);
$verifySnapshot=static function(ResourceSnapshot $s)use($c,$verify):void{$r=$c->table('tenant_pg_resources')->where('resource_ref',$s->resourceRef)->first();if($r===null||$r->incarnation!==$s->incarnation||$r->database_name!==$s->databaseName)throw new RuntimeException('Snapshot changed');$verify($r);};
$provisionJournal=new ProvisionJournal($c,$integrity,$verifySnapshot,$anchor);
$build=static function(Closure $fence,ResourceSnapshot $s)use($c,$factory,$cfg,$m,$integrity,$templateMarker,$verifySnapshot):TemplateClonePostgreSQLManager{
 $generation=$c->table('tenant_pg_resources')->where('resource_ref',$s->resourceRef)->value('recovery_generation');
 $writer=new MarkerWriter($factory,$cfg,$m['runtime'],$generation,$templateMarker,$fence);return new TemplateClonePostgreSQLManager($c,$integrity,$verifySnapshot,$writer->stamp(...),$fence);
};
$name=static fn(string $id):string=>$base.'_'.str_replace('-','',$id).'_test';
$pool=new PendingPool($c,$integrity,$provisionJournal,$build,$verifySnapshot,$name,$anchor);
$claims=new ClaimReceiptStore($c,new ClaimResource('lab',$integrity,$db->getDatabaseManager()),$verify,$anchor);
$owner=PostgresFactory::owner($c,$db->getDatabaseManager(),$trust,$runtimeRoute,$name,$m['runtime'],$templateMarker,$archives,$anchor,$policy);
$permit=new Tenant\Postgres\Admission(static fn(string $p,string $ref):bool=>str_starts_with($ref,'lab:')||str_starts_with($ref,'pg:'));
function reviewIntent(string $scenario):Tenant\Postgres\ProvisionIntent { global $cluster,$template,$proof;return new Tenant\Postgres\ProvisionIntent('lab:'.$scenario,$cluster,$template,7,$proof['digest'],['scenario'=>$scenario]); }
function barrier(string $hook):void { global $c;echo json_encode(['hook'=>$hook,'pid'=>$c->selectOne('SELECT pg_backend_pid() AS pid')->pid]),"\n";flush();if(trim((string)fgets(STDIN))!=='release')throw new RuntimeException('Barrier aborted'); }
