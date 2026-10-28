<?php

declare(strict_types=1);

require __DIR__.'/Harness.php';

$app=flexApp();set_exception_handler(function(Throwable $e):void{fwrite(STDERR,(string)$e);exit(1);});flexReset($app);$seed=flexSeed($app);extract($seed);
$manager=$app->make(CoreX\Context\Contracts\TenancyManager::class);
$runtime=$app->make(Tenant\Contracts\TenantRuntime::class);
$command=$app->make(FlexCRM\Auth\Tenancy\WorkspaceCommand::class);
$ref=flexRef($w);$refX=flexRef($x);
$before=Illuminate\Support\Facades\DB::table('crm_projects')->count();
foreach([null,'forged','00000000-0000-4000-8000-000000000099'] as $bad){denies(fn()=>$app->make(FlexCRM\Auth\Tenancy\SessionWorkspaceResolver::class)->resolve($actor,$bad),'AUTH-01 selector denied before effect '.json_encode($bad));}
verify(Illuminate\Support\Facades\DB::table('crm_projects')->count()===$before,'AUTH-01 no rejected selector effects');
// Actual web adapter and mandatory package guards use the installed, matching connection.
Illuminate\Support\Facades\Auth::guard('web')->setUser(App\Models\User::query()->findOrFail($actor));
config(['flexcrm-crm.connection'=>'tenant']);
$manager->runFor($accounts['a'],$ref,function()use($app,$actor,$w):void{
    $ctx=$app->make(FlexCRM\Crm\Context\WebCrmExecutionContext::class)->current();
    verify($ctx->principalId===$actor && $ctx->tenant->workspace->id===$w,'AUTH-01 authenticated actual CRM context');
    verify($app->make(FlexCRM\Crm\Support\CrmAuthorization::class)->requireContext()->tenant->workspace->id===$w,'REL-01 actual CRM membership fresh admission');
});
// Change the actual relation after selector lookup but before the command's transactional lock.
$admin=labAdmin('a');$inject=(object)['enabled'=>true,'observed'=>false];
$app['db']->listen(function(Illuminate\Database\Events\QueryExecuted $query)use($admin,$w,$actor,$inject):void{
    if($inject->enabled && str_contains($query->sql,'select "slug" from "wsp_workspaces"')){
        $inject->enabled=false;$q=$admin->prepare('UPDATE wsp_workspace_members SET updated_at=? WHERE workspace_id=? AND user_id=?');$q->execute(['2031-01-01 00:00:00',$w,$actor]);$inject->observed=true;
    }
});
denies(fn()=>$command->run($actor,$w,fn()=>throw new RuntimeException('stale grant effect executed')),'REL-01 revision between lookup and transactional lock denied');
verify($inject->observed,'REL-01 actual selector race was injected');
// A changed relation revision invalidates the transaction, including its SQL/outbox/queued audit.
$id='00000000-0000-4000-8000-000000000040';
denies(fn()=>$command->run($actor,$w,function()use($id,$actor):void{
    flexEffect($id,$actor);
    Illuminate\Support\Facades\DB::table('wsp_workspace_members')->where('workspace_id',app(CoreX\Context\Contracts\TenancyManager::class)->context()->workspace->id)->where('user_id',$actor)->update(['updated_at'=>'2030-01-01 00:00:00']);
}),'REL-01 membership revision changed before commit rolls back');
verify(!Illuminate\Support\Facades\DB::table('crm_projects')->where('id',$id)->exists() && !Illuminate\Support\Facades\DB::table('sys_outbox_messages')->where('id',$id)->exists() && Illuminate\Support\Facades\DB::connection('central')->table('jobs')->count()===0,'REL-01 effect outbox audit are atomically absent');
// Explicit membership loss never chooses the available X default.
Illuminate\Support\Facades\DB::table('wsp_workspace_members')->where('workspace_id',$w)->where('user_id',$actor)->update(['status'=>'suspended']);
denies(fn()=>$command->run($actor,$w,fn()=>throw new RuntimeException('must not execute')),'SELECT-01 selection_lost no default workspace');
verify(Illuminate\Support\Facades\DB::table('crm_projects')->count()===0,'SELECT-01 no effect in W2');
Illuminate\Support\Facades\DB::table('wsp_workspace_members')->where('workspace_id',$w)->where('user_id',$actor)->update(['status'=>'active']);
// The ordinary command uses one fresh, admitted W all the way through the product mutation.
$command->run($actor,$w,fn()=>flexEffect($id,$actor));
$row=Illuminate\Support\Facades\DB::table('crm_projects')->where('id',$id)->first();
$event=Illuminate\Support\Facades\DB::table('sys_outbox_messages')->where('id',$id)->first();
$scope=json_decode($event->scope,true,flags:JSON_THROW_ON_ERROR);
verify($row->workspace_id===$w && $scope['workspace']===$w && $scope['account']===$accounts['a']->id,'REL-01 real effect and public outbox retain original A/W');
verify($app->make(App\Tenancy\WorkspaceContext::class)->currentKey()===null && $manager->context()->workspace===null,'EXT03-SNAPSHOTS command restores product and public contexts');
// Queue only the signed scalar job while in A; execute it after switching to B in another process.
$jobId='00000000-0000-4000-8000-000000000041';
$manager->runFor($accounts['a'],$ref,fn()=>$app->make(CoreX\Context\Contracts\ContextJobPublisher::class)->publish('flexcrm:fixture','flexcrm:fixture','flexcrm:fixture',['actor'=>$actor,'workspace'=>$w,'id'=>$jobId]));
$manager->initialize($accounts['b']);
verify(Illuminate\Support\Facades\DB::table('crm_projects')->count()===0,'FLEX-COHORT-01 B has no A effects before worker');
$process=new Symfony\Component\Process\Process([PHP_BINARY,__DIR__.'/Worker.php'],env:['TENANT_LAB_MANIFEST'=>getenv('TENANT_LAB_MANIFEST'),'FLEXCRM_CHECKOUT'=>getenv('FLEXCRM_CHECKOUT')]);
$process->setTimeout(90);$process->mustRun();echo $process->getOutput();
verify(Illuminate\Support\Facades\DB::table('crm_projects')->count()===0 && Illuminate\Support\Facades\DB::table('sys_outbox_messages')->count()===0,'FLEX-COHORT-01 real worker cannot write A work in B');
$manager->initialize($accounts['a']);
verify(Illuminate\Support\Facades\DB::table('crm_projects')->where('id',$jobId)->where('workspace_id',$w)->exists(),'REL-01 worker effect uses frozen A/W');
verify(Illuminate\Support\Facades\DB::table('sys_audit_log')->where('entity_id',$jobId)->where('workspace_id',$w)->where('actor_id',$actor)->exists(),'REL-01 actual audit worker preserves original actor W');
verify(Illuminate\Support\Facades\DB::connection('central')->table('jobs')->count()===0 && Illuminate\Support\Facades\DB::connection('central')->table('failed_jobs')->count()===0,'FLEX-COHORT-01 named central queue completion no failures');
$manager->end();
echo "COMPLETE FlexCRM actual consumer\n";
