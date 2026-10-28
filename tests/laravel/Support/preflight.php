<?php

declare(strict_types=1);

require __DIR__.'/bootstrap.php';
$m=json_decode(file_get_contents(getenv('TENANT_LAB_MANIFEST')),true,flags:JSON_THROW_ON_ERROR);
$h=new TenantLab\LabHost($m);
foreach($m['databases'] as $label=>$database){
    if($database!=='tenant_'.$m['runId'].'_'.$label.'_test')throw new RuntimeException('DB isolation allowlist mismatch');
    $rows=$h->sql($label,"select current_database() db,current_user role,current_setting('server_version_num') version,rolsuper,rolcreatedb,rolcreaterole from pg_roles where rolname=current_user");$r=$rows[0];
    if($r->db!==$database||$r->role!==$m['runtime']||!str_starts_with($r->version,'16')||$r->rolsuper||$r->rolcreatedb||$r->rolcreaterole)throw new RuntimeException('Runtime DB isolation/version/privilege mismatch');
    $r=$h->sql($label,"select has_schema_privilege(current_user,'public','CREATE') writable")[0];if($r->writable)throw new RuntimeException('Runtime has DDL privilege');
}
foreach(['a','b'] as $label){$s=$h->selection($label);$resolved=$h->owner->resolve($s,$h->purpose());$h->owner->verify(new Tenant\Data\BindingVerificationInput($resolved->context->context->binding,$resolved->facets->require(new Tenant\Data\CapabilityId('tenant:binding-verification'))));}
$info=$h->app['redis']->connection()->info('server');$version=$info['Server']['redis_version']??$info['redis_version']??'';
if(!str_starts_with($version,'7.'))throw new RuntimeException('Redis must be 7.x');
echo json_encode(['status'=>'passed','php'=>PHP_VERSION,'laravel'=>Illuminate\Foundation\Application::VERSION,'dbs'=>array_values($m['databases']),'runtimeRole'=>$m['runtime'],'redis'=>$version,'markers'=>['a','b'],'pid'=>getmypid()],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),"\n";
