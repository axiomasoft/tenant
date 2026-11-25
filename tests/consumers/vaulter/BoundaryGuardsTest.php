<?php

declare(strict_types=1);

require __DIR__.'/Host.php';
require_once __DIR__.'/ProducerHelpers.php';

use TenantVaulter\{Host, HostRecord};
use Vaulter\Drive\Facades\Vaulter as Vault;
use Vaulter\Drive\Jobs\Middleware\RestoreTenantContext;
use Vaulter\Drive\Jobs\Uploads\ScanFileRevision;
use Vaulter\Drive\Models\{Drive, FileRevision};
use Vaulter\Drive\Models\Content\ScanObligation;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Actions\Files\IngestFileAction;
use Vaulter\Drive\Data\Files\IngestFileInput;
use Vaulter\Drive\Content\ScanWorkReconciler;
use Vaulter\Contracts\Jobs\JobPublisher;
use Vaulter\Jobs\{JobRequest, PublicationContext};
use Tenant\Data\Purpose;

$m=json_decode(file_get_contents(getenv('TENANT_LAB_MANIFEST')),true,flags:JSON_THROW_ON_ERROR);
$h=new Host($m); $app=$h->app; $central=$app['db']->connection('central');$count=0;
$check=static function(bool $ok,string $message)use(&$count):void{if(!$ok)throw new RuntimeException('Assertion: '.$message);$count++;echo 'PASS '.$message.PHP_EOL;};
$refuse=static function(Closure $call,string $message)use($check):void{try{$call();}catch(Throwable $e){if(!$e instanceof Tenant\Exceptions\TenantFailure && !$e instanceof Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException && !$e instanceof InvalidArgumentException && !$e instanceof Illuminate\Database\QueryException)throw $e;$check(true,$message.' ['.get_class($e).']');return;}throw new RuntimeException('Expected refusal '.$message);};
$publisher=$app->make(JobPublisher::class);
$reactivate=static function()use($h):void{
    Illuminate\Container\Container::setInstance($h->app);
    Illuminate\Support\Facades\Facade::clearResolvedInstances();
    Illuminate\Support\Facades\Facade::setFacadeApplication($h->app);
    Illuminate\Database\Eloquent\Model::setConnectionResolver($h->app['db']);
    Illuminate\Database\Eloquent\Model::setEventDispatcher($h->app['events']);
};
$refuse(fn()=>new Host($m,overrides:['vaulter.ids.host_keys'=>'bigint']),'unsupported host key type boot refuses');
$reactivate();
$refuse(fn()=>new Host($m,overrides:['vaulter-tenant.tenant_columns'=>['v_drives'=>'missing_column']]),'missing actual tenant column boot refuses');
$reactivate();
$refuse(fn()=>new Host($m,overrides:['vaulter.tenancy.enabled'=>false]),'disabled strict tenancy boot refuses');
$reactivate();
$refuse(fn()=>new Host($m,overrides:['vaulter-tenant.namespace'=>str_repeat('a',33)]),'unsupported fixed namespace boot refuses');
$reactivate();
$refuse(fn()=>new Host($m,overrides:['vaulter-tenant.namespace'=>'alpha']),'fixed namespace rejects a second registered namespace at boot');
$reactivate();

$prepared=$h->run('alpha',fn()=>$publisher->prepare(new JobRequest('vaulter.drive:purge',['driveId'=>'999999'])));
$before=$central->table('jobs')->count();
$h->owner->available=false;$refuse(fn()=>$publisher->publish($prepared),'unavailable frozen resource refuses publication');$h->owner->available=true;
$h->owner->routeRevision='route:2';$refuse(fn()=>$publisher->publish($prepared),'stale durable route refuses before enqueue');$h->owner->routeRevision='route:1';
$credentials=json_decode(file_get_contents($m['credentials']),true,flags:JSON_THROW_ON_ERROR);
$admin=new PDO('pgsql:host=127.0.0.1;port='.$m['ports']['pg'].';dbname='.$m['databases']['a'],$m['admin'],$credentials['adminPassword'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$admin->exec('ALTER TABLE v_drives ALTER COLUMN tenant_id TYPE varchar(32)');
try { $refuse(fn()=>new Host($m),'narrow actual tenant column boot refuses'); }
finally { $admin->exec('ALTER TABLE v_drives ALTER COLUMN tenant_id TYPE varchar(64)'); $reactivate(); }
$admin->exec("UPDATE tenant_binding SET incarnation='foreign-incarnation'");
try{$refuse(fn()=>$publisher->publish($prepared),'actual wrong physical marker refuses before enqueue');}
finally{$admin->exec("UPDATE tenant_binding SET incarnation='incarnation:a'");}
$check($central->table('jobs')->count()===$before,'all stale and unavailable captures produce zero queue writes');
$refuse(fn()=>$central->statement('CREATE TABLE forbidden_runtime_ddl (id int)'),'restricted runtime role cannot issue DDL');
$refuse(fn()=>$publisher->prepare(new JobRequest('vaulter.drive:purge',['driveId'=>'1']),new PublicationContext('tenant:context',1,['envelope'=>$prepared->context->refs['envelope'],'purpose'=>'forged:purpose'])),'foreign publication purpose denied');
$refuse(fn()=>$app->make(Tenant\Contracts\ContextCodec::class)->decode('{"schema":"tenant.context/1","schema":"tenant.context/1"}'),'wire duplicate keys refused');
$codec=$app->make(Tenant\Contracts\ContextCodec::class);
$centralWire=['schema'=>'tenant.context/1','mode'=>'central','profileId'=>'laravel-pg-database','profileVersion'=>1,'extensions'=>[]];
$refuse(fn()=>$codec->decode(json_encode($centralWire+['isolationId'=>null],JSON_THROW_ON_ERROR)),'wire central null identity refused');
$envelope=json_decode($prepared->context->refs['envelope'],true);
$badProfile=$envelope;$badProfile['profileVersion']=2;
$refuse(fn()=>$publisher->publish(new Vaulter\Jobs\PreparedJob($prepared->request,new PublicationContext('tenant:context',1,['envelope'=>json_encode($badProfile,JSON_THROW_ON_ERROR),'purpose'=>'vaulter:job']))),'unregistered exact profile version refused');
$envelope['extensions']=[['type'=>'unknown:extension','version'=>1,'required'=>true,'refs'=>['id'=>'x']]];
$refuse(fn()=>$publisher->publish(new Vaulter\Jobs\PreparedJob($prepared->request,new PublicationContext('tenant:context',1,['envelope'=>json_encode($envelope,JSON_THROW_ON_ERROR),'purpose'=>'vaulter:job']))),'unknown required extension denied before effect');
$envelope['extensions'][0]['required']=false;
$check($codec->decode(json_encode($envelope,JSON_THROW_ON_ERROR))->envelope['extensions'][0]['required']===false,'bounded optional unknown wire decoded without effects');
$readsBeforeOptional=$central->table('scan_reads')->count();
$publisher->publish(new Vaulter\Jobs\PreparedJob($prepared->request,new PublicationContext('tenant:context',1,['envelope'=>json_encode($envelope,JSON_THROW_ON_ERROR),'purpose'=>'vaulter:job'])));
TenantVaulter\runWorker('bounded optional unknown ignored');
$check($central->table('scan_reads')->count()===$readsBeforeOptional && $central->table('failed_jobs')->count()===0,'unknown optional extension ignored by actual signed worker without additional byte effects');

// Named central work remains explicit even while a tenant frame is active.
$h->run('alpha',function()use($app):void{
    $dispatch=$app->make(Tenant\Laravel\Queue\ContextDispatcher::class);
    $dispatch->enqueue($dispatch->freezeCentral(new Purpose('host:central')),
        new Tenant\Laravel\Queue\ScalarJob('host:producer','host:central',[]));
});
$raw=json_decode($central->table('jobs')->orderByDesc('id')->first()->payload,true);
$check(!str_contains($raw['data']['wire'],'isolationId') && str_contains($raw['data']['wire'],'central'),'explicit central work has no inherited tenant stamp');
require_once __DIR__.'/ProducerHelpers.php';
TenantVaulter\runWorker('explicit central');
$check($central->table('probes')->where('name','central-job')->value('value')==='central','explicit central worker writes named control DB');

$originalScan=[];
$h->run('alpha',function()use($app,$check,&$originalScan):void{
    $drive=Vault::drive(HostRecord::query()->findOrFail('owner-a'))->asSystem('guard fixture')->model();
    $stream=fopen('data://text/plain,deferred-alpha','rb');
    $db=app(Vaulter\Database\VaulterDatabase::class);
    $connection=$db->connection();$connection->beginTransaction();
    try{
        $node=app(IngestFileAction::class)->execute(DriveContext::system($drive,'guard fixture'),new IngestFileInput($drive,$stream,'deferred.txt','text/plain'));
        $work=$node->file()->firstOrFail()->currentRevision()->firstOrFail()->scanObligations()->firstOrFail();
        $originalScan=['drive'=>(string)$drive->id,'work'=>(string)$work->id,'generation'=>$work->generation,'tenant'=>$work->tenant_id];
        $tx=app('db.transactions')->getPendingTransactions()->last();
        $originalScan['callbacks']=$tx->getCallbacks();
        (new ReflectionProperty($tx,'callbacks'))->setValue($tx,[]);
        $connection->commit();
    }finally{fclose($stream);}
    $check($originalScan['callbacks']!==[],'actual ScanWorkReconciler captures before deferred afterCommit');
});
$h->run('beta',function()use(&$originalScan,$central,$check):void{
    foreach($originalScan['callbacks'] as $callback)$callback();
    $row=$central->table('jobs')->orderByDesc('id')->first();$payload=json_decode($row->payload,true);
    $check(str_contains($payload['data']['wire'],'alpha'),'actual deferred scan target stays A while B active');
});
TenantVaulter\runWorker('deferred scan');
$check($central->table('scan_reads')->where('namespace','alpha')->where('bytes','deferred-alpha')->count()===1,'deferred actual scan reads A bytes after restoration before validator');
$h->run('beta',function()use($originalScan,$check,$refuse,$central):void{
    $reads=$central->table('scan_reads')->count();
    $refuse(fn()=>app()->call([new ScanFileRevision($originalScan['work'],$originalScan['generation'],$originalScan['drive'],$originalScan['tenant']),'handle']),'foreign scanner scope fails before bytes');
    $check($central->table('scan_reads')->count()===$reads,'rejected scanner performed zero byte reads');
});
// Current owner is re-read; caller-modified/supplied identity is not authority.
$h->run('beta',function()use($app,$check,$refuse):void{
    $owner=HostRecord::query()->findOrFail('owner-a');$resolver=app(Vaulter\Tenant\TenantResolver::class);
    $before=app(Vaulter\Tenant\Contracts\OwnerMapping::class)->reads;
    $first=$resolver->tenantOf($owner);
    app('db')->connection('central')->table('host_owners')->where('id','owner-a')->update(['namespace'=>'beta']);
    try{$check($resolver->tenantOf($owner)!==$first,'fresh host owner mapping ignores stale supplied Model attributes');}
    finally{app('db')->connection('central')->table('host_owners')->where('id','owner-a')->update(['namespace'=>'alpha']);}
    $owner->id='spoofed';$refuse(fn()=>Vault::drive($owner),'edited owner identity rejected');
    $check(app(Vaulter\Tenant\Contracts\OwnerMapping::class)->reads>=$before+2,'owner mapping freshly re-read every projection');
});
$h->run('alpha',function()use($app,$check,$refuse):void{
    $drive=Drive::query()->firstOrFail();$tenant=app(Vaulter\Tenant\TenantResolver::class)->current();
    $user=new Vaulter\Testing\Fixtures\TestUser; $user->setConnection('central');$user->setTable('host_users');$user->forceFill(['id'=>31]);$user->exists=true;
    app('db')->connection('central')->table('host_users')->updateOrInsert(['id'=>'31'],['name'=>'actor']);
    config()->set('auth.defaults.guard','web');config()->set('auth.guards.web',['driver'=>'session','provider'=>'users']);
    config()->set('auth.providers.users',['driver'=>'eloquent','model'=>TenantVaulter\HostUser::class]);
    $actor=TenantVaulter\HostUser::query()->findOrFail('31');
    $root=$drive->rootNode()->firstOrFail();
    $grant=Vaulter\Drive\Testing\Access\AccessTestHelpers::grant($root,Vaulter\Drive\Access\Roles\RoleRegistry::OWNER,subjectId:'31',subjectType:$actor->getMorphClass());
    $middleware=new RestoreTenantContext($drive->id,$tenant,Vaulter\ValueObjects\Access\Actor::user($actor)->ref());
    $work=function()use($middleware,$drive):void{Vault::drive($drive)->actingAs($middleware->actor()->authorizable())->root();};
    $middleware->handle(new stdClass,$work);
    $check($middleware->actor()===null,'fresh actor read and cleanup after successful operation');
    app(Vaulter\Database\VaulterDatabase::class)->write(fn()=>$grant->delete());
    $refuse(fn()=>$middleware->handle(new stdClass,$work),'permissions reread at execution after grant removal');
    $check($middleware->actor()===null,'actor cleared after handler exception');
    app('db')->connection('central')->table('host_users')->where('id','31')->delete();
    $refuse(fn()=>$middleware->handle(new stdClass,$work),'deleted actor refused without system promotion');
    $check($middleware->actor()===null,'deleted actor leaves no ambient actor');
});
$check($central->table('failed_jobs')->count()===0,'guard probes leave no unobserved worker failures');
$registry=$app->make(Vaulter\Jobs\JobRegistry::class);
$refuse(fn()=>$app->instance(Vaulter\Jobs\JobRegistry::class,new Vaulter\Jobs\JobRegistry([])),'late task registry rebinding denied');
$check($app->make(Vaulter\Jobs\JobRegistry::class)===$registry,'immutable task registry restored after denied mutation');
$h->run('alpha', function () use ($publisher): void {
    $publisher->publish($publisher->prepare(new JobRequest('vaulter.drive:purge', ['driveId'=>'999999'])));
});
$row=$central->table('jobs')->orderByDesc('id')->first();
$payload=json_decode($row->payload,true);$payload['data']['signature']=str_repeat('0',64);
$central->table('jobs')->where('id',$row->id)->update(['payload'=>json_encode($payload,JSON_THROW_ON_ERROR)]);
$before=$central->table('scan_reads')->count();
TenantVaulter\runWorker('untrusted producer signature');
$check($central->table('failed_jobs')->count()===1 && $central->table('scan_reads')->count()===$before,'untrusted transport fails in real worker before hydration or byte IO');
echo 'COMPLETE Vaulter guards '.$count.' assertions'.PHP_EOL;
