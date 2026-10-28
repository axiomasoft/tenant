<?php
require __DIR__.'/lifecycle-support.php';
$packageRoot=getenv('P42_INSTALLED')?dirname((new ReflectionClass(Tenant\Postgres\PostgresFactory::class))->getFileName(),2):__DIR__.'/../../packages/tenant-postgres';
foreach (glob($packageRoot.'/database/migrations/*.php') as $path) {
    $table=match(true) {
        str_contains($path,'000001')=>'tenant_pg_resources',str_contains($path,'000002')=>'tenant_pg_operations',str_contains($path,'000003')=>'tenant_pg_provision_attempts',str_contains($path,'000006')=>'tenant_pg_control',default=>null,
    };
    if ($table!==null && $c->getSchemaBuilder()->hasTable($table)) continue;
    if (str_contains($path,'000004') && $c->getSchemaBuilder()->hasColumn('tenant_pg_clusters','capacity')) continue;
    if (str_contains($path,'000005') && $c->getSchemaBuilder()->hasColumn('tenant_pg_provision_attempts','cancel_requested')) continue;
    $migration=require $path;$c->transaction(fn()=>$migration->up());
}
if(!is_dir($anchorDir))mkdir($anchorDir,0700);if(!is_dir($archiveDir))mkdir($archiveDir,0700);$anchor=Tenant\Postgres\FileRecoveryAnchor::create($anchorDir.'/anchor.json');
$c->table('tenant_pg_control')->insert(['id'=>1,'recovery_generation'=>$anchor->generation()]);
echo json_encode(['status'=>'passed','migrations'=>6,'generation'=>$anchor->generation(),'central'=>$m['central']]),"\n";
