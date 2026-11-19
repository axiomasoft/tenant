<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Connectors\ConnectionFactory;
use Tenant\Postgres\Data\ClaimRequest;
use Tenant\Postgres\Data\TemplateTrust;
use Tenant\Postgres\Exceptions\TemplateIntegrityException;
use Tenant\Postgres\Internal\Database\SchemaCatalogueSerializer;
use Tenant\Postgres\Internal\Database\TemplateIntegrityLock;
use Tenant\Postgres\Internal\Database\TemplateIntegrityVerifier;

require_once __DIR__.'/public-errors-autoload.php';
require getenv('P41_AUTOLOAD');
spl_autoload_register(static function(string $class): void { $prefix='Tenant\\Postgres\\'; if(str_starts_with($class,$prefix))require __DIR__.'/../../packages/postgres/src/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php'; });
$run=getenv('P41_RUN_ID');$name='tenant_p41_'.$run.'_template_test';
if (!preg_match('/^[a-f0-9]{12}$/D',$run) || getenv('P41_DB')!=='tenant_p41_'.$run.'_central_test' || getenv('P41_HOST')!=='127.0.0.1' || getenv('P41_USER')!=='tenant_p41_'.$run.'_admin') throw new RuntimeException('Unsafe lab target');
$app=new Container;$db=new Manager($app);$config=['driver'=>'pgsql','host'=>getenv('P41_HOST'),'port'=>getenv('P41_PORT'),'database'=>getenv('P41_DB'),'username'=>getenv('P41_USER'),'password'=>getenv('P41_PASSWORD'),'charset'=>'utf8','prefix'=>'','schema'=>'public'];$db->addConnection($config,'lab');$central=$db->getConnection('lab');
if($central->selectOne('select current_database() as db')->db!==getenv('P41_DB'))throw new RuntimeException('Wrong DB');
if ($central->selectOne('SELECT datname FROM pg_database WHERE datname=?',[$name])!==null)throw new RuntimeException('Template exists; refuse blind repeat');
$central->statement('CREATE DATABASE "'.$name.'"');
$factory=new ConnectionFactory($app);$config['database']=$name;$template=$factory->make($config,'p41-template');
$template->statement('CREATE TABLE fixture (id integer PRIMARY KEY, note text NOT NULL)');$template->statement("INSERT INTO fixture VALUES (1,'original')");
$serializer=new SchemaCatalogueSerializer;$hash=$serializer->hash($template);$template->disconnect();
$id='77777777-7777-4777-8777-777777777777';$cluster='88888888-8888-4888-8888-888888888888';
$payload=['template_id'=>$id,'template_version'=>2,'canonical_schema_sha256'=>$hash];$pair=sodium_crypto_sign_keypair();$enc=static fn(string $v):string=>rtrim(strtr(base64_encode($v),'+/','-_'),'=');$signature=sodium_crypto_sign_detached("tenant-template-manifest/v1\0".$serializer->encode($payload),sodium_crypto_sign_secretkey($pair));
$trust=new TemplateTrust([$id=>['payload'=>$payload,'signature'=>$enc($signature),'trusted_key_id'=>'lab']],['lab'=>$enc(sodium_crypto_sign_publickey($pair))]);
$central->table('tenant_pg_templates')->insert(['id'=>$id,'version'=>2,'status'=>'ready','db_name'=>$name,'schema_hash'=>$hash]);
$central->table('tenant_pg_clusters')->insert(['id'=>$cluster,'status'=>'active','template_version'=>2]);
$verifier=new TemplateIntegrityVerifier('lab',$serializer,new TemplateIntegrityLock,$trust,$db->getDatabaseManager(),$factory);
$qualified=$verifier->qualify(new ClaimRequest('integrity-test',$cluster,$id,str_repeat('a',64)));if($qualified->templateVersion!==2)throw new RuntimeException('Wrong qualified version');
$template=$factory->make($config,'p41-template-drift');$template->statement('ALTER TABLE fixture ADD COLUMN drifted boolean');$template->disconnect();
try {$verifier->qualify(new ClaimRequest('integrity-test',$cluster,$id,str_repeat('a',64)));throw new RuntimeException('Drift accepted');} catch (TemplateIntegrityException) {}
echo json_encode(['status'=>'passed','scope'=>'signed template, actual PG schema and drift','schemaHash'=>$hash,'template'=>$name],JSON_THROW_ON_ERROR),"\n";
