<?php
require __DIR__.'/Harness.php';
$app=flexApp();set_exception_handler(function(Throwable $e):void{fwrite(STDERR,(string)$e);exit(1);});flexReset($app);$s=flexSeed($app);extract($s);
$manager=$app->make(CoreX\Context\Contracts\TenancyManager::class);$runtime=$app->make(Tenant\Contracts\TenantRuntime::class);$ref=flexRef($w);
$manager->runFor($accounts['a'],$ref,function()use($app,$actor):void{
    $context=$app->make(CoreX\Context\Contracts\TenantContextResolver::class)->current();
    $scope=CoreX\Data\Data\DataScope::fromTenantContext($context,'pgsql:'.Illuminate\Support\Facades\DB::selectOne('select current_database() as name')->name,$actor,str_repeat('a',64));
    verify($app->make(CoreX\Context\Contracts\TenantContextRestorer::class)->restore($scope)->account->id===$context->account->id,'CODEC-01 known public durable reference freshly admitted');
});
$central=$app['db']->connection('central');$registry=$app->make(Tenant\Laravel\Queue\QueueRegistry::class);
foreach(['optional','required','retired','malformed','producer'] as $n=>$case){
    $id='00000000-0000-4000-8000-00000000008'.($n+1);
    $manager->runFor($accounts['a'],$ref,fn()=>$app->make(CoreX\Context\Contracts\ContextJobPublisher::class)->publish('flexcrm:fixture','flexcrm:fixture','flexcrm:fixture',['actor'=>$actor,'workspace'=>$w,'id'=>$id]));
    $row=$central->table('jobs')->orderByDesc('id')->first();$p=json_decode($row->payload,true,flags:JSON_THROW_ON_ERROR);$wire=json_decode($p['data']['wire'],true,flags:JSON_THROW_ON_ERROR);
    if(in_array($case,['optional','required'],true))$wire['extensions'][]=['type'=>'fixture:unknown','version'=>1,'required'=>$case==='required','refs'=>['hint'=>'bounded']];
    if($case==='retired')$wire['extensions'][0]['version']=99;
    if($case==='malformed')$wire['extensions'][0]['refs']['id']=true;
    if($case==='producer')$p['data']['producer']='fixture:untrusted';
    $p['data']['wire']=json_encode($wire,JSON_THROW_ON_ERROR);unset($p['data']['signature']);
    $p['data']['signature']=$registry->sign(json_encode($p['data'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
    $central->table('jobs')->where('id',$row->id)->update(['payload'=>json_encode($p,JSON_THROW_ON_ERROR)]);
}
$manager->initialize($accounts['b']);
$process=new Symfony\Component\Process\Process([PHP_BINARY,__DIR__.'/Worker.php'],env:['TENANT_LAB_MANIFEST'=>getenv('TENANT_LAB_MANIFEST'),'FLEXCRM_CHECKOUT'=>getenv('FLEXCRM_CHECKOUT')]);$process->setTimeout(90);$process->mustRun();echo $process->getOutput();
verify($app['db']->table('crm_projects')->count()===0,'CODEC-01 malformed or unknown references produce zero B effects');
$manager->initialize($accounts['a']);
verify($app['db']->table('crm_projects')->pluck('id')->all()===['00000000-0000-4000-8000-000000000081'],'CODEC-01 unknown optional ignored while required retired malformed untrusted refused before effect');
verify($central->table('failed_jobs')->count()===4 && $central->table('jobs')->count()===0,'CODEC-01 actual worker four refusals no required skips');
$central->table('failed_jobs')->delete();
$id='00000000-0000-4000-8000-000000000090';
$manager->runFor($accounts['a'],$ref,fn()=>$app->make(CoreX\Context\Contracts\ContextJobPublisher::class)->publish('flexcrm:fixture','flexcrm:fixture','flexcrm:fixture',['actor'=>$actor,'workspace'=>$w,'id'=>$id]));
$app['db']->table('wsp_workspace_members')->where('workspace_id',$w)->where('user_id',$actor)->update(['status'=>'suspended']);
$manager->end();$process->mustRun();echo $process->getOutput();
$manager->initialize($accounts['a']);
verify(!$app['db']->table('crm_projects')->where('id',$id)->exists() && !$app['db']->table('sys_outbox_messages')->where('id',$id)->exists(),'REL-01 queued revoked membership refused before product hydration or IO');
$manager->end();
// Ordinary and maintenance purposes have independent explicit host admission.
$secret=json_decode(file_get_contents($m['credentials']),true,flags:JSON_THROW_ON_ERROR);
$admin=new PDO('pgsql:host=127.0.0.1;port='.$m['ports']['pg'].';dbname='.$m['databases']['central'],$m['admin'],$secret['adminPassword'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);unset($secret);
if((string)$admin->query('select system_identifier from pg_control_system()')->fetchColumn()!==$m['systemIdentifier'])throw new RuntimeException('Unknown admin target');
$q=$admin->prepare("update root_accounts set status=? where id=?");$q->execute(['suspended',$accounts['a']->id]);
$selection=Tenant\Data\Selection::tenant(new Tenant\Data\IsolationIdentity('corex',$accounts['a']->id),new Tenant\Data\ProfileRef('laravel-pg-database',1),[CoreX\Tenant\Context\WorkspaceExtension::missing()]);
try{
    denies(fn()=>$runtime->run($selection,new Tenant\Data\Purpose('flexcrm:ordinary'),fn()=>throw new RuntimeException('must not run')),'AUTH-02 ordinary revoked account denied');
    denies(fn()=>$runtime->run($selection,new Tenant\Data\Purpose('flexcrm:maintenance'),fn()=>null),'AUTH-02 purpose string alone is no maintenance authority');
    config(['tenant.maintenance_accounts'=>[$accounts['a']->id]]);
    $runtime->run($selection,new Tenant\Data\Purpose('flexcrm:maintenance'),fn()=>verify($app['db']->selectOne('select current_database() as name')->name===$m['databases']['a'],'AUTH-02 separately authorized maintenance without paid status'));
}finally{$q->execute(['active',$accounts['a']->id]);}
verify($runtime->current()->kind==='missing','AUTH-02 maintenance leaves no runtime state');
echo "COMPLETE FlexCRM fresh guards and codecs\n";
