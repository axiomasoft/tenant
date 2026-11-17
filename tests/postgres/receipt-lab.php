<?php

declare(strict_types=1);
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Support\Facades\Facade;
use Tenant\Data\{BindingExpectation,BindingVerificationInput};
use Tenant\Postgres\{Admission,OperationKey,ProvisionIntent};use Tenant\Postgres\Data\{PhysicalAccess,TemplateTrust};
use Tenant\Postgres\Internal\{ClaimReceiptStore,ClaimResource,PostgresBindingVerifier};
use Tenant\Postgres\Internal\Database\{SchemaCatalogueSerializer,TemplateIntegrityLock,TemplateIntegrityVerifier};
require_once __DIR__.'/public-errors-autoload.php';
require getenv('P41_AUTOLOAD');
spl_autoload_register(static function(string $class):void{foreach(['Tenant\\Postgres\\'=>__DIR__.'/../../packages/tenant-postgres/src/','Tenant\\'=>__DIR__.'/../../packages/tenant/src/'] as $prefix=>$root)if(str_starts_with($class,$prefix)){require $root.str_replace('\\','/',substr($class,strlen($prefix))).'.php';return;}});
$run=getenv('P41_RUN_ID');$base='tenant_p41_'.$run;$name=$base.'_receipt_test';$gold=$base.'_gold5_test';$runtime=$base.'_runtime';
if(!preg_match('/^[a-f0-9]{12}$/D',$run)||getenv('P41_DB')!==$base.'_central_test'||getenv('P41_HOST')!=='127.0.0.1'||getenv('P41_USER')!==$base.'_admin')throw new RuntimeException('Unsafe target');
$app=new Container;$db=new Manager($app);$admin=['driver'=>'pgsql','host'=>getenv('P41_HOST'),'port'=>getenv('P41_PORT'),'database'=>getenv('P41_DB'),'username'=>getenv('P41_USER'),'password'=>getenv('P41_PASSWORD'),'charset'=>'utf8','prefix'=>'','schema'=>'public'];$db->addConnection($admin,'lab');$db->getDatabaseManager()->setDefaultConnection('lab');$app->instance('db',$db->getDatabaseManager());$central=$db->getConnection('lab');$app->instance('db.schema',$central->getSchemaBuilder());Facade::setFacadeApplication($app);
if($central->selectOne('select current_database() db')->db!==getenv('P41_DB'))throw new RuntimeException('Wrong database');
$factory=new ConnectionFactory($app);$resourceConfig=$admin;$resourceConfig['database']=$name;
if (!$central->getSchemaBuilder()->hasTable('tenant_pg_operations')) {
    if($central->selectOne('SELECT datname FROM pg_database WHERE datname=?',[$name]))throw new RuntimeException('Existing target; refuse CREATE');
    $migration=require __DIR__.'/../../packages/tenant-postgres/database/migrations/2026_10_29_000002_create_tenant_pg_operations.php';$central->transaction(fn()=>$migration->up());
    foreach(['2026_10_29_000003_create_tenant_pg_provision_attempts.php','2026_10_29_000005_add_cancellation_intent.php'] as $file){$migration=require __DIR__.'/../../packages/tenant-postgres/database/migrations/'.$file;$central->transaction(fn()=>$migration->up());}
    $central->statement('CREATE DATABASE "'.$name.'"');$physical=$factory->make($resourceConfig,'p41-receipt');
    $physical->statement('CREATE TABLE tenant_binding (singleton boolean PRIMARY KEY CHECK(singleton), protocol text NOT NULL, owner_id text NOT NULL, resource_ref text NOT NULL, incarnation text NOT NULL, recovery_generation text NOT NULL)');
    $physical->statement("INSERT INTO tenant_binding VALUES (true,'tenant.binding/1','tenant-postgres','resource:receipt','incarnation:1','generation:1')");
    $physical->statement('GRANT CONNECT ON DATABASE "'.$name.'" TO "'.$runtime.'"');$physical->statement('GRANT USAGE ON SCHEMA public TO "'.$runtime.'"');$physical->statement('GRANT SELECT ON tenant_binding TO "'.$runtime.'"');$physical->disconnect();
} elseif (getenv('P41_RESUME_FAILED_RECEIPT')!=='1') throw new RuntimeException('Existing setup: explicit inspected resume required');
if($central->table('tenant_pg_operations')->count()!==0 || $central->table('tenant_pg_resources')->where('id','16161616-1616-4616-8616-161616161616')->exists())throw new RuntimeException('Unexpected receipt lab state');
$physical=$factory->make($resourceConfig,'p41-receipt');$existing=$physical->select('SELECT protocol,owner_id,resource_ref,incarnation,recovery_generation FROM tenant_binding');
if(count($existing)!==1 || (array)$existing[0]!==['protocol'=>'tenant.binding/1','owner_id'=>'tenant-postgres','resource_ref'=>'resource:receipt','incarnation'=>'incarnation:1','recovery_generation'=>'generation:1'])throw new RuntimeException('Unexpected initial marker');$physical->disconnect();
$config=$admin;$config['database']=$gold;$goldConn=$factory->make($config,'p41-gold');$serializer=new SchemaCatalogueSerializer;$hash=$serializer->hash($goldConn);$goldConn->disconnect();
$id='12121212-1212-4212-8212-121212121212';$cluster='13131313-1313-4313-8313-131313131313';$resource='16161616-1616-4616-8616-161616161616';$payload=['template_id'=>$id,'template_version'=>7,'canonical_schema_sha256'=>$hash];$pair=sodium_crypto_sign_keypair();$enc=static fn(string $v):string=>rtrim(strtr(base64_encode($v),'+/','-_'),'=');$signature=sodium_crypto_sign_detached("tenant-template-manifest/v1\0".$serializer->encode($payload),sodium_crypto_sign_secretkey($pair));$trust=new TemplateTrust([$id=>['payload'=>$payload,'signature'=>$enc($signature),'trusted_key_id'=>'lab']],['lab'=>$enc(sodium_crypto_sign_publickey($pair))]);
$central->table('tenant_pg_resources')->insert(['id'=>$resource,'cluster_id'=>$cluster,'template_version'=>7,'status'=>'pending','resource_ref'=>'resource:receipt','incarnation'=>'incarnation:1','database_name'=>$name,'route_revision'=>'route:1','recovery_generation'=>'generation:1']);
$route=$admin;$route['database']=$name;$route['username']=$runtime;$route['password']=getenv('P41_RUNTIME_PASSWORD');
$binding=new PostgresBindingVerifier($central,static fn(object $row):array=>$route,maintenance:true);$verify=static function(object $row)use($binding,$route):void{$binding->verify(new BindingVerificationInput(new BindingExpectation($row->resource_ref,$row->incarnation,$row->route_revision),new PhysicalAccess($route)));};
$integrity=new TemplateIntegrityVerifier('lab',$serializer,new TemplateIntegrityLock,$trust,$db->getDatabaseManager(),$factory);$claims=new ClaimResource('lab',$integrity,$db->getDatabaseManager());$store=new ClaimReceiptStore($central,$claims,$verify);$digest=hash('sha256',$serializer->encode($payload));$intent=new ProvisionIntent('host:intent',$cluster,$id,7,$digest,['a'=>1]);$key=new OperationKey('lab','claim-one');$allow=new Admission(static fn(string $purpose,string $target):bool=>$target==='host:intent');
$first=$store->claim($intent,$key,$allow);$again=$store->claim($intent,$key,$allow);$queried=$store->query($first->operationId,$allow);
if($first!=$again || $first!=$queried || $first->resourceRef!=='resource:receipt')throw new RuntimeException('Receipt replay mismatch');
try{$store->claim(new ProvisionIntent('host:intent',$cluster,$id,7,$digest,['a'=>2]),$key,$allow);throw new RuntimeException('Changed digest accepted');}catch(\Tenant\Postgres\Exceptions\ClaimConflict){}
try{$store->query($first->operationId,new Admission(static fn()=>false));throw new RuntimeException('Missing admission accepted');}catch(\Tenant\Exceptions\ContextDenied $e){if($e->reason()!=='denied')throw $e;}
$physical=$factory->make($resourceConfig,'p41-tamper');$physical->table('tenant_binding')->where('singleton',true)->update(['incarnation'=>'changed']);$physical->disconnect();
try{$store->claim($intent,$key,$allow);throw new RuntimeException('Stale marker accepted');}catch(\Tenant\Exceptions\BindingMismatch){}
if($central->table('tenant_pg_operations')->count()!==1)throw new RuntimeException('Receipt duplicated');
echo json_encode(['status'=>'passed','scope'=>'durable claim, admission, intent conflict and physical verifier','operation'=>$first->operationId,'resource'=>$first->resourceRef],JSON_THROW_ON_ERROR),"\n";
