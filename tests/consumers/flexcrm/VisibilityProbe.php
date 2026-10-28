<?php
require __DIR__.'/Harness.php';

$app=flexApp();set_exception_handler(function(Throwable $e):void{fwrite(STDERR,(string)$e);exit(1);});flexReset($app);$s=flexSeed($app);extract($s);
$manager=$app->make(CoreX\Context\Contracts\TenancyManager::class);$runtime=$app->make(Tenant\Contracts\TenantRuntime::class);$ref=flexRef($w);
Illuminate\Support\Facades\Auth::guard('web')->setUser(App\Models\User::query()->findOrFail($actor));
config(['flexcrm-crm.connection'=>'tenant']);
$manifest=json_decode(file_get_contents(getenv('FLEXCRM_BEFORE_SOURCES')),true,flags:JSON_THROW_ON_ERROR);
foreach($manifest as $source){
    $bytes=file_get_contents($source['body']);verify(hash('sha256',$bytes)===$source['sha256'],'source hash '.$source['fixture']);
    if($source['fixture']==='CRM-GUARD')continue;
    $class=['NULL-APP-01'=>'AppScopeBefore','NULL-COREX-01'=>'CorexScopeBefore','NULL-CRM-01'=>'CrmScopeBefore'][$source['fixture']];
    $bytes=preg_replace('/final class (WorkspaceScope|CrmScope) /','final class '.$class.' ',$bytes,1);
    eval(substr($bytes,5));
}
final class NullFixtureRow extends Illuminate\Database\Eloquent\Model {protected $table='p53_effects';protected $keyType='string';public $incrementing=false;public $timestamps=false;protected $guarded=[];}
foreach(['rW'=>$w,'rX'=>$x,'r0'=>null] as $id=>$wid)Illuminate\Support\Facades\DB::table('p53_effects')->insert(['id'=>$id,'workspace_id'=>$wid,'actor_id'=>$actor,'event'=>'visibility']);
function rows($scope):array{$m=new NullFixtureRow;$q=$m->newQuery();$scope->apply($q,$m);return $q->orderBy('id')->pluck('id')->all();}
$appBefore=new App\Tenancy\Scopes\AppScopeBefore;$corexBefore=new CoreX\Tenancy\Laravel\Scopes\CorexScopeBefore;$crmBefore=new Flex\Crm\Context\CrmScopeBefore;
$manager->runFor($accounts['a'],$ref,function()use($app,$appBefore,$corexBefore,$crmBefore):void{
    echo 'OBSERVED app selected '.json_encode(rows($appBefore)).' context='.app(App\Tenancy\WorkspaceContext::class)->currentKey().PHP_EOL;
    verify(rows($appBefore)===['rW'],'NULL-APP-01 before selected {rW}');
    verify(rows($corexBefore)===['r0','rW'],'NULL-COREX-01 before selected {rW,r0}');
    verify(rows($crmBefore)===['rW'],'NULL-CRM-01 before W false {rW}');
    $base=$app->make(FlexCRM\Crm\Context\WebCrmExecutionContext::class)->current();
    $app->instance(Flex\Crm\Context\Contracts\CrmExecutionContext::class,new class($base) implements Flex\Crm\Context\Contracts\CrmExecutionContext{public function __construct(private $base){}public function current():Flex\Crm\Context\Data\ExecutionContext{return new Flex\Crm\Context\Data\ExecutionContext($this->base->tenant,'tenant',$this->base->actor,$this->base->principalId,true);}});
    verify(rows($crmBefore)===['r0','rW'],'NULL-CRM-01 before W true {rW,r0}');
    $app->bind(Flex\Crm\Context\Contracts\CrmExecutionContext::class,FlexCRM\Crm\Context\WebCrmExecutionContext::class);
    verify(rows(new App\Tenancy\Scopes\WorkspaceScope)===['rW'],'NULL-TARGET-01 selected actual app only authorized W');
});
verify(rows($appBefore)===[],'NULL-APP-01 before missing {}');
verify(rows($corexBefore)===['r0','rW','rX'],'NULL-COREX-01 before null {rW,rX,r0}');
verify(rows(new CoreX\Tenancy\Laravel\Scopes\WorkspaceScope)===[],'NULL-TARGET-01 missing CoreX closed {}');
verify(rows(new App\Tenancy\Scopes\WorkspaceScope)===[],'NULL-TARGET-01 missing app closed {}');
denies(fn()=>$app->make(FlexCRM\Crm\Context\WebCrmExecutionContext::class)->current(),'NULL-CRM-01 guard independently denies absent capability');
$wide=Tenant\Data\Selection::tenant(new Tenant\Data\IsolationIdentity('corex',$accounts['a']->id),new Tenant\Data\ProfileRef('laravel-pg-database',1),[CoreX\Tenant\Context\WorkspaceExtension::accountWide()]);
denies(fn()=>$runtime->run($wide,new Tenant\Data\Purpose('flexcrm:fixture'),fn()=>null),'NULL-TARGET-01 AccountWide denied without explicit capability');
Illuminate\Support\Facades\Gate::define('flexcrm.account-wide',fn($user,$accountId)=>$accountId===$accounts['a']->id);
$runtime->run($wide,new Tenant\Data\Purpose('flexcrm:fixture'),function()use($app,$actor,$crmBefore):void{
    verify(rows(new CoreX\Tenancy\Laravel\Scopes\WorkspaceScope)===['r0','rW','rX'],'NULL-TARGET-01 explicitly authorized AccountWide exact set');
    $tenant=$app->make(CoreX\Context\Contracts\TenancyManager::class)->context();
    $ctx=new Flex\Crm\Context\Data\ExecutionContext($tenant,'tenant',new Flex\Crm\Context\Data\Actor('user',$actor,'Actor'),$actor,true);
    $app->instance(Flex\Crm\Context\Contracts\CrmExecutionContext::class,new class($ctx) implements Flex\Crm\Context\Contracts\CrmExecutionContext{public function __construct(private $ctx){}public function current():Flex\Crm\Context\Data\ExecutionContext{return $this->ctx;}});
    verify(rows($crmBefore)===['r0'],'NULL-CRM-01 historical null guarded {r0}');
});
verify(!array_filter(Composer\InstalledVersions::getInstalledPackages(),fn($n)=>str_contains($n,'billing')),'REL-03 no mandatory billing dependency or payer identity');
$manager->end();echo "COMPLETE FlexCRM visibility before and target\n";
