<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Connectors\ConnectionFactory;
use Tenant\Postgres\Data\ResourceSnapshot;
use Tenant\Postgres\Data\TemplateTrust;
use Tenant\Postgres\Internal\Database\{SchemaCatalogueSerializer,TemplateClonePostgreSQLManager,TemplateIntegrityLock,TemplateIntegrityVerifier};
require_once __DIR__.'/public-errors-autoload.php';
require getenv('P41_AUTOLOAD');
spl_autoload_register(static function(string $class):void{$prefix='Tenant\\Postgres\\';if(str_starts_with($class,$prefix))require __DIR__.'/../../packages/postgres/src/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';});
$run=getenv('P41_RUN_ID');$base='tenant_p41_'.$run;$gold=$base.'_gold5_test';$clone=$base.'_clone5_test';$runtime=$base.'_runtime';
if(!preg_match('/^[a-f0-9]{12}$/D',$run)||getenv('P41_DB')!==$base.'_central_test'||getenv('P41_HOST')!=='127.0.0.1'||getenv('P41_USER')!==$base.'_admin')throw new RuntimeException('Unsafe lab target');
$app=new Container;$db=new Manager($app);$config=['driver'=>'pgsql','host'=>getenv('P41_HOST'),'port'=>getenv('P41_PORT'),'database'=>getenv('P41_DB'),'username'=>getenv('P41_USER'),'password'=>getenv('P41_PASSWORD'),'charset'=>'utf8','prefix'=>'','schema'=>'public'];$db->addConnection($config,'lab');$central=$db->getConnection('lab');
if($central->selectOne('select current_database() as db')->db!==getenv('P41_DB'))throw new RuntimeException('Wrong DB');
foreach([$gold,$clone] as $name)if($central->selectOne('SELECT datname FROM pg_database WHERE datname=?',[$name])!==null)throw new RuntimeException('Existing database: do not repeat CREATE');
$central->statement('CREATE DATABASE "'.$gold.'"');$factory=new ConnectionFactory($app);$config['database']=$gold;$goldConnection=$factory->make($config,'p41-gold');
$goldConnection->statement('CREATE TABLE tenant_binding (singleton boolean PRIMARY KEY CHECK(singleton), protocol text NOT NULL, owner_id text NOT NULL, resource_ref text NOT NULL, incarnation text NOT NULL, recovery_generation text NOT NULL)');
$goldConnection->statement("INSERT INTO tenant_binding VALUES (true,'tenant.binding/1','template','template','template','template')");
$goldConnection->statement('CREATE TABLE fixture (id integer PRIMARY KEY, note text NOT NULL)');$goldConnection->statement("INSERT INTO fixture VALUES (1,'from-template')");
$serializer=new SchemaCatalogueSerializer;$hash=$serializer->hash($goldConnection);$goldConnection->disconnect();
$id='12121212-1212-4212-8212-121212121212';$cluster='13131313-1313-4313-8313-131313131313';$payload=['template_id'=>$id,'template_version'=>7,'canonical_schema_sha256'=>$hash];$pair=sodium_crypto_sign_keypair();$enc=static fn(string $v):string=>rtrim(strtr(base64_encode($v),'+/','-_'),'=');$signature=sodium_crypto_sign_detached("tenant-template-manifest/v1\0".$serializer->encode($payload),sodium_crypto_sign_secretkey($pair));$trust=new TemplateTrust([$id=>['payload'=>$payload,'signature'=>$enc($signature),'trusted_key_id'=>'lab']],['lab'=>$enc(sodium_crypto_sign_publickey($pair))]);
$central->table('tenant_pg_templates')->insert(['id'=>$id,'version'=>7,'status'=>'ready','db_name'=>$gold,'schema_hash'=>$hash]);$central->table('tenant_pg_clusters')->insert(['id'=>$cluster,'status'=>'active','template_version'=>7]);
$verifier=new TemplateIntegrityVerifier('lab',$serializer,new TemplateIntegrityLock,$trust,$db->getDatabaseManager(),$factory);
$snapshot=new ResourceSnapshot('resource:clone','incarnation:1',7,$cluster,$clone);
$access=static function(ResourceSnapshot $s) use($config,$factory){$c=$config;$c['database']=$s->databaseName;return $factory->make($c,'p41-instance');};
$stamp=static function(ResourceSnapshot $s)use($access,$runtime):void{$c=$access($s);try{$c->table('tenant_binding')->where('singleton',true)->update(['owner_id'=>'tenant-postgres','resource_ref'=>$s->resourceRef,'incarnation'=>$s->incarnation,'recovery_generation'=>'generation:1']);$c->statement('GRANT CONNECT ON DATABASE "'.$s->databaseName.'" TO "'.$runtime.'"');$c->statement('GRANT USAGE ON SCHEMA public TO "'.$runtime.'"');$c->statement('GRANT SELECT ON tenant_binding, fixture TO "'.$runtime.'"');}finally{$c->disconnect();}};
$verify=static function(ResourceSnapshot $s)use($config,$factory,$runtime):void{$restricted=$config;$restricted['database']=$s->databaseName;$restricted['username']=$runtime;$restricted['password']=getenv('P41_RUNTIME_PASSWORD');$c=$factory->make($restricted,'p41-runtime');try{$rows=$c->select('SELECT protocol,owner_id,resource_ref,incarnation,recovery_generation FROM tenant_binding');$expected=['protocol'=>'tenant.binding/1','owner_id'=>'tenant-postgres','resource_ref'=>$s->resourceRef,'incarnation'=>$s->incarnation,'recovery_generation'=>'generation:1'];if(count($rows)!==1||(array)$rows[0]!==$expected)throw new RuntimeException('Marker mismatch');}finally{$c->disconnect();}};
$cloner=new TemplateClonePostgreSQLManager($central,$verifier,$verify,$stamp);
if(!$cloner->createOrVerify($snapshot,$gold)||$cloner->createOrVerify($snapshot,$gold))throw new RuntimeException('Clone/replay incorrect');
$c=$access($snapshot);$value=$c->selectOne('SELECT note FROM fixture WHERE id=1')->note;if($value!=='from-template')throw new RuntimeException('Clone data missing');$c->table('tenant_binding')->where('singleton',true)->update(['incarnation'=>'wrong']);$c->disconnect();
try{$cloner->createOrVerify($snapshot,$gold);throw new RuntimeException('Marker tampering accepted');}catch(RuntimeException $e){if($e->getMessage()!=='Marker mismatch')throw $e;}
$failedSnapshot=new ResourceSnapshot('resource:failed','incarnation:1',7,$cluster,$base.'_fault2_test');
if($central->selectOne('SELECT datname FROM pg_database WHERE datname=?',[$failedSnapshot->databaseName])!==null)throw new RuntimeException('Fault target exists');
$faulting=new TemplateClonePostgreSQLManager($central,$verifier,$verify,static function(ResourceSnapshot $s):void{throw new RuntimeException('injected failure after CREATE');});
try{$faulting->createOrVerify($failedSnapshot,$gold);throw new RuntimeException('Fault was not injected');}catch(RuntimeException $e){if($e->getMessage()!=='injected failure after CREATE')throw $e;}
if($central->selectOne('SELECT datname FROM pg_database WHERE datname=?',[$failedSnapshot->databaseName])===null)throw new RuntimeException('Expected physical effect missing');
$faultDb=$access($failedSnapshot);$marker=$faultDb->selectOne('SELECT owner_id FROM tenant_binding');$faultDb->disconnect();if($marker->owner_id!=='template')throw new RuntimeException('Unexpected fault marker');
try{$cloner->createOrVerify($failedSnapshot,$gold);throw new RuntimeException('Unstamped database accepted');}catch(\Illuminate\Database\QueryException $e){if((string)$e->getPrevious()?->getCode()!=='42501')throw $e;}
echo json_encode(['status'=>'passed','scope'=>'real clone, original replay, marker mismatch and post-create fault refusal','template'=>$gold,'clone'=>$clone,'data'=>$value],JSON_THROW_ON_ERROR),"\n";
