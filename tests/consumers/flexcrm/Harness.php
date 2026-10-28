<?php

declare(strict_types=1);

require_once __DIR__.'/Environment.php';
$checkout = getenv('FLEXCRM_CHECKOUT');
require_once $checkout.'/vendor/autoload.php';

use CoreX\Context\Contracts\{ContextQueueHandler, TenancyManager};
use CoreX\Messaging\Contracts\MessageContractRegistry;
use CoreX\Messaging\Data\MessageContract;
use CoreX\Messaging\Enums\{MessageClass, PiiClass};
use Illuminate\Contracts\Queue\Job;
use Illuminate\Support\Facades\DB;

final class FlexFixtureHandler implements ContextQueueHandler
{
    public function name(): string { return 'flexcrm:fixture'; }
    public function producer(): string { return 'flexcrm:fixture'; }
    public function purposes(): array { return ['flexcrm:fixture']; }
    public function admit(array $data, string $purpose): void
    {
        if (array_keys($data)!==['actor','workspace','id'] || !is_string($data['actor']) || !is_string($data['workspace']) || !is_string($data['id'])) { throw new Tenant\Exceptions\ContextDenied(); }
        $context=app(TenancyManager::class)->context();
        if ($context?->workspace?->id!==$data['workspace']) { throw new Tenant\Exceptions\SelectionLost(); }
        $active=DB::table('users')->where('id',$data['actor'])->where('is_active',true)->whereNull('deleted_at')->exists();
        $member=DB::table('wsp_workspace_members')->where('workspace_id',$data['workspace'])->where('user_id',$data['actor'])->where('status','active')->exists();
        if (!$active || !$member) { throw new Tenant\Exceptions\SelectionLost(); }
    }
    public function handle(array $data, Job $job): void
    {
        app(FlexCRM\Auth\Tenancy\WorkspaceCommand::class)->run($data['actor'],$data['workspace'],function()use($data):void {
            flexEffect($data['id'],$data['actor']);
        });
    }
}

function flexApp(?Closure $compose = null): Illuminate\Foundation\Application
{
    global $checkout;
    $app=require $checkout.'/bootstrap/app.php';
    $app->afterBootstrapping(Illuminate\Foundation\Bootstrap\RegisterProviders::class, function($app) use($compose):void {
        $app->singleton(FlexFixtureHandler::class);
        $app->tag([FlexFixtureHandler::class],'corex.tenant.queue-handlers');
        $app->singleton(MessageContractRegistry::class,fn()=>new class implements MessageContractRegistry {
            public function has(string $name,int $version):bool {return $name==='flexcrm.fixture' && $version===1;}
            public function get(string $name,int $version):MessageContract {
                if(!$this->has($name,$version))throw new CoreX\Messaging\Exceptions\UnknownMessageContract();
                return new MessageContract($name,$version,MessageClass::IntegrationEvent,'flexcrm/auth',PiiClass::Pseudonymous,'fixture');
            }
            public function all():array {return [$this->get('flexcrm.fixture',1)];}
        });
        if($compose!==null){$previous=$app->make('tenant.compose');$app->singleton('tenant.compose',fn()=>function($builder)use($previous,$compose,$app){$previous($builder);$compose($builder,$app);});}
    });
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    return $app;
}

function flexReset(Illuminate\Foundation\Application $app): void
{
    global $checkout;
    require_once $checkout.'/tests/Support/TenantLab.php';
    prepareTenantLabApplication($app);
    $app['db']->connection('central')->table('jobs')->delete();
    $app['db']->connection('central')->table('failed_jobs')->delete();
}

function flexSeed(Illuminate\Foundation\Application $app): array
{
    $manager=$app->make(TenancyManager::class);$accounts=[];
    $actor='00000000-0000-4000-8000-000000000030';$w='00000000-0000-4000-8000-000000000010';$x='00000000-0000-4000-8000-000000000020';
    foreach(['a','b'] as $n=>$label){
        $account=$app->make(CoreX\Context\Contracts\AccountProjection::class)->find('00000000-0000-4000-8000-00000000000'.($n+1),'central');$accounts[$label]=$account;
        $manager->runFor($account,null,function()use($actor,$w,$x,$label):void{
            App\Models\User::query()->forceCreate(['id'=>$actor,'email'=>'actor-'.$label.'@example.test','name'=>'Actor '.$label,'is_active'=>true,'email_verified_at'=>now(),'password_hash'=>password_hash('fixture-secret',PASSWORD_BCRYPT)]);
            foreach([$w=>$label.'-w',$x=>$label.'-x'] as $id=>$slug){
                $workspace=App\Models\Workspaces\Workspace::query()->forceCreate(['id'=>$id,'slug'=>$slug,'name'=>$slug,'type'=>'company','path'=>str_replace('-','',$id),'is_default'=>false]);
                DB::table('wsp_workspace_members')->insert(['id'=>(string)Illuminate\Support\Str::uuid7(),'workspace_id'=>$id,'user_id'=>$actor,'status'=>'active','is_default'=>$id===$w,'joined_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
            }
        });
    }
    $manager->initialize($accounts['a']);
    return ['accounts'=>$accounts,'actor'=>$actor,'w'=>$w,'x'=>$x];
}

function flexRef(string $workspace): CoreX\Context\Data\WorkspaceRef
{
    $w=App\Models\Workspaces\Workspace::query()->findOrFail($workspace);
    return new CoreX\Context\Data\WorkspaceRef($w->id,$w->slug,$w->type,$w->path,$w->parent_id);
}

function flexEffect(string $id,string $actor): void
{
    $context=app(TenancyManager::class)->context();
    Crm\Models\Project::query()->forceCreate(['id'=>$id,'name'=>'Qualified command','code'=>str_replace('-','',$id),'status'=>'active']);
    app(CoreX\Audit\Contracts\AuditLogger::class)->record(new CoreX\Audit\Data\AuditEntry('flexcrm.fixture',CoreX\Audit\Enums\ActorType::User,$actor,'project',$id));
    $scope=CoreX\Data\Data\DataScope::fromTenantContext($context,'pgsql:'.DB::selectOne('select current_database() as name')->name,$actor,str_repeat('a',64));
    app(CoreX\Messaging\Contracts\Outbox::class)->append(new CoreX\Messaging\Data\MessageEnvelope($id,'urn:flexcrm:fixture','flexcrm.fixture',1,new DateTimeImmutable(),app(CoreX\Data\Contracts\DataScopeCodec::class)->encode($scope),new CoreX\Messaging\Data\MessagePayload(['id'=>$id]),PiiClass::Pseudonymous));
}

function verify(bool $condition,string $label):void
{
    if(!$condition)throw new RuntimeException('ASSERTION FAILED '.$label);
    echo 'PASS '.$label.PHP_EOL;
}

function denies(Closure $callback,string $label):Throwable
{
    try{$callback();}catch(Throwable $failure){
        if(!$failure instanceof Tenant\Exceptions\TenantFailure && !$failure instanceof Illuminate\Auth\Access\AuthorizationException && !$failure instanceof Illuminate\Database\Eloquent\ModelNotFoundException){throw $failure;}
        echo 'PASS '.$label.' ['.$failure::class.']'.PHP_EOL;return $failure;
    }
    throw new RuntimeException('Expected denial '.$label);
}

function labAdmin(string $label): PDO
{
    global $m;
    if(!isset($m['databases'][$label]) || $m['databases'][$label]!=='tenant_'.$m['runId'].'_'.$label.'_test')throw new RuntimeException('Not an allowed lab DB');
    $secret=json_decode(file_get_contents($m['credentials']),true,flags:JSON_THROW_ON_ERROR);
    $pdo=new PDO('pgsql:host=127.0.0.1;port='.$m['ports']['pg'].';dbname='.$m['databases'][$label],$m['admin'],$secret['adminPassword'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    if((string)$pdo->query('select system_identifier from pg_control_system()')->fetchColumn()!==$m['systemIdentifier'])throw new RuntimeException('Not the owned lab server');
    return $pdo;
}
