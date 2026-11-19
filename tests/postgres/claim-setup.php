<?php

declare(strict_types=1);

require getenv('P41_AUTOLOAD');
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Support\Facades\Facade;
$run=getenv('P41_RUN_ID');
if (! preg_match('/^[a-f0-9]{12}$/D',$run) || getenv('P41_DB')!=='tenant_p41_'.$run.'_central_test' || getenv('P41_HOST')!=='127.0.0.1' || getenv('P41_USER')!=='tenant_p41_'.$run.'_admin' || getenv('P41_RUNTIME')!=='tenant_p41_'.$run.'_runtime') throw new RuntimeException('Unsafe setup target');
$app=new Container; $db=new Manager($app);
$db->addConnection(['driver'=>'pgsql','host'=>getenv('P41_HOST'),'port'=>getenv('P41_PORT'),'database'=>getenv('P41_DB'),'username'=>getenv('P41_USER'),'password'=>getenv('P41_PASSWORD'),'charset'=>'utf8','prefix'=>'','schema'=>'public'],'lab');
$db->getDatabaseManager()->setDefaultConnection('lab'); $app->instance('db',$db->getDatabaseManager()); Facade::setFacadeApplication($app);
$c=$db->getConnection('lab');$app->instance('db.schema',$c->getSchemaBuilder());if($c->selectOne('select current_database() as db')->db!==getenv('P41_DB'))throw new RuntimeException('Wrong setup database');
$migration=require __DIR__.'/../../packages/postgres/database/migrations/2026_10_29_000001_create_tenant_pg_catalog.php'; $migration->up();
$c->table('tenant_pg_clusters')->insert(['id'=>'11111111-1111-4111-8111-111111111111','status'=>'active','template_version'=>1]);
$c->table('tenant_pg_templates')->insert(['id'=>'22222222-2222-4222-8222-222222222222','status'=>'ready','version'=>1,'db_name'=>'fixture_test']);
foreach (['33333333-3333-4333-8333-333333333333','44444444-4444-4444-8444-444444444444'] as $id) $c->table('tenant_pg_resources')->insert(['id'=>$id,'cluster_id'=>'11111111-1111-4111-8111-111111111111','template_version'=>1,'status'=>'pending']);
$role=getenv('P41_RUNTIME');$c->statement('GRANT USAGE ON SCHEMA public TO "'.$role.'"');$c->statement('GRANT SELECT, UPDATE ON tenant_pg_clusters,tenant_pg_templates,tenant_pg_resources TO "'.$role.'"');
