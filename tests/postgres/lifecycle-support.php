<?php

declare(strict_types=1);
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Support\Facades\Facade;
use Tenant\Data\{BindingExpectation,BindingVerificationInput};
use Tenant\Postgres\{Admission,FileRecoveryAnchor,PgDumpArchiveStore,OperationKey,OperationRef,OperationReceipt};
use Tenant\Postgres\Data\PhysicalAccess;
use Tenant\Postgres\Internal\{LifecycleJournal,PostgresBindingVerifier};
require_once __DIR__.'/public-errors-autoload.php';
require getenv('P41_AUTOLOAD');
// Installed mode must execute installed package bodies, not the working tree.
if (!getenv('P42_INSTALLED')) spl_autoload_register(static function(string $class):void{foreach(['Tenant\\Postgres\\'=>__DIR__.'/../../packages/postgres/src/','Tenant\\'=>__DIR__.'/../../packages/core/src/'] as $prefix=>$root)if(str_starts_with($class,$prefix)){require $root.str_replace('\\','/',substr($class,strlen($prefix))).'.php';return;}});
$m=json_decode(file_get_contents(getenv('P41_MANIFEST')),true,flags:JSON_THROW_ON_ERROR);$base=$m['project'];
if (getenv('P54_LIFECYCLE_REUSE') === '1') {
    $original=json_decode(file_get_contents(getenv('TENANT_LAB_MANIFEST')),true,flags:JSON_THROW_ON_ERROR);
    foreach ($original as $key=>$value) if (!array_key_exists($key,$m) || $m[$key]!==$value) throw new RuntimeException('Changed P5.4 lab alias.');
    if ($base!=='tenant_lab_'.$m['runId'] || $m['central']!=='tenant_'.$m['runId'].'_catalog_test' || $m['admin']!=='tenant_'.$m['runId'].'_admin' || $m['host']!=='127.0.0.1' || getenv('P41_DB')!==$m['central'] || getenv('P41_USER')!==$m['admin']) throw new RuntimeException('Unsafe P5.4 lifecycle reuse.');
} elseif (!preg_match('/^tenant_p4[12]_[a-f0-9]{12}$/D',$base) || $m['central']!==$base.'_central_test' || $m['host']!=='127.0.0.1' || getenv('P41_DB')!==$m['central'] || getenv('P41_USER')!==$m['admin']) throw new RuntimeException('Unsafe lifecycle lab');
$app=new Container;$db=new Manager($app);$cfg=['driver'=>'pgsql','host'=>$m['host'],'port'=>$m['port'],'database'=>$m['central'],'username'=>$m['admin'],'password'=>getenv('P41_PASSWORD'),'charset'=>'utf8','prefix'=>'','schema'=>'public'];
$db->addConnection($cfg,'lab');$db->getDatabaseManager()->setDefaultConnection('lab');$c=$db->getConnection('lab');$app->instance('db',$db->getDatabaseManager());$app->instance('db.schema',$c->getSchemaBuilder());Facade::setFacadeApplication($app);$factory=new ConnectionFactory($app);
$anchorDir=$m['root'].'/anchor';$archiveDir=$m['root'].'/archives';
$anchor=new FileRecoveryAnchor($anchorDir.'/anchor.json');
$route=static function(object $r)use($cfg):array{return [...$cfg,'database'=>$r->database_name];};
$runtimeRoute=static function(object $r)use($cfg,$m):array{return [...$cfg,'database'=>$r->database_name,'username'=>$m['runtime'],'password'=>getenv('P41_RUNTIME_PASSWORD')];};
$binding=new PostgresBindingVerifier($c,$runtimeRoute,$anchor,maintenance:true);
$verify=static function(object $row)use($binding,$runtimeRoute):void{$binding->verify(new BindingVerificationInput(new BindingExpectation($row->resource_ref,$row->incarnation,$row->route_revision),new PhysicalAccess($runtimeRoute($row))));};
$archives=new PgDumpArchiveStore($archiveDir,'archive:lab',$route,'/usr/lib/postgresql/16/bin/pg_dump');
$allow=new Admission(static fn(string $purpose,string $ref):bool=>str_starts_with($ref,'lab:'));
$policy=static fn(string $kind,object $intent,object $resource):bool=>in_array($kind,['export','delete'],true) && (($intent->retentionProfileRef??$intent->policyIntentRef)==='policy:lab');
$journal=new LifecycleJournal($c,$verify,$archives,$anchor,$policy);
function check(bool $ok,string $message):void { if (!$ok) throw new RuntimeException($message); }
function refuse(Closure $work,?string $contains=null):string
{
    try { $work(); } catch (Throwable $e) { if ($contains!==null && ($e instanceof \Tenant\Exceptions\TenantFailure ? $e->reason()!==$contains : !str_contains($e->getMessage(),$contains))) throw $e;return get_class($e).': '.$e->getMessage(); }
    throw new RuntimeException('Expected refusal did not occur.');
}
function operationRef(OperationReceipt $r):OperationRef { return new OperationRef($r->key->namespace,$r->operationId,$r->resourceRef,$r->incarnation); }
function makeResource(string $suffix):object
{
    global $c,$base,$factory,$cfg,$m,$anchor;
    check(preg_match('/^[a-z0-9_]+$/D',$suffix)===1,'Invalid resource suffix');$name=$base.'_'.$suffix.'_test';check(strlen($name)<=63,'Long test name');
    check($c->selectOne('SELECT datname FROM pg_database WHERE datname=?',[$name])===null,'Refuse to reuse physical target');
    $c->getPdo()->exec('CREATE DATABASE "'.$name.'"');$probe=$factory->make([...$cfg,'database'=>$name],'create-'.$suffix);
    $ref='lab:'.$suffix;$inc='inc:'.$suffix;$generation=$anchor->generation();
    $probe->statement('CREATE TABLE records (id integer PRIMARY KEY, content text NOT NULL)');$probe->table('records')->insert(['id'=>1,'content'=>'sentinel:'.$suffix]);
    $probe->statement('CREATE TABLE tenant_binding (singleton boolean PRIMARY KEY DEFAULT true CHECK(singleton), protocol text NOT NULL,owner_id text NOT NULL,resource_ref text NOT NULL,incarnation text NOT NULL,recovery_generation text NOT NULL)');
    $probe->table('tenant_binding')->insert(['protocol'=>'tenant.binding/1','owner_id'=>'tenant-postgres','resource_ref'=>$ref,'incarnation'=>$inc,'recovery_generation'=>$generation]);
    $probe->statement('REVOKE CREATE ON SCHEMA public FROM PUBLIC');$probe->statement('REVOKE CREATE ON DATABASE "'.$name.'" FROM PUBLIC');$probe->statement('GRANT SELECT ON ALL TABLES IN SCHEMA public TO "'.$m['runtime'].'"');$probe->disconnect();
    $c->table('tenant_pg_resources')->insert(['id'=>(string)Illuminate\Support\Str::uuid7(),'cluster_id'=>'13131313-1313-4313-8313-131313131313','template_version'=>7,'status'=>'ready','resource_ref'=>$ref,'incarnation'=>$inc,'database_name'=>$name,'route_revision'=>'route:1','recovery_generation'=>$generation]);
    return $c->table('tenant_pg_resources')->where('resource_ref',$ref)->first();
}
function exportIntent(object $r):Tenant\Postgres\ExportIntent { return new Tenant\Postgres\ExportIntent($r->resource_ref,$r->incarnation,'archive:lab','policy:lab'); }
function deleteIntent(object $r,OperationReceipt $export):Tenant\Postgres\DeleteIntent { return new Tenant\Postgres\DeleteIntent($r->resource_ref,$r->incarnation,$export->exportRef,'policy:lab'); }
