<?php

declare(strict_types=1);
namespace TenantLab;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\{Worker, WorkerOptions};
use Tenant\Data\{ContextReference, Purpose, ProfileRef};
use Tenant\Laravel\Queue\{ContextDispatcher, QueueRegistry, ScalarJob};
use Tenant\Laravel\Codec\JsonContextCodec;
use Tenant\Laravel\Internal\Queue\{FrozenIntent, ScalarHandler, QueueContextBoundary};

require_once __DIR__.'/CorpusRunner.php';
require_once __DIR__.'/HttpScenarios.php';

final class WorkerErrors extends \Illuminate\Foundation\Exceptions\Handler
{
    public function report(\Throwable $e) { file_put_contents('php://stderr',get_class($e).': '.$e->getMessage().\PHP_EOL); }
}
final class HydratedProbe extends \Illuminate\Database\Eloquent\Model
{
    protected $table = 'probes';
    protected $primaryKey = 'name';
    protected $keyType = 'string';
    protected $connection = 'tenant';
    public $incrementing = false;
    public $timestamps = false;
    protected $guarded = [];
}
final class WorkerProbe
{
    public array $trace = [];
    public int $assertions = 0;
    public LabHost $h;
    public string $queue;
    public bool $revoked = false;
    private array $control;
    private object $fault;
    public function __construct(public array $manifest, public bool $decorated = false, public string $scenario = '', string $session = 'file')
    {
        $this->queue = 'p32_'.bin2hex(random_bytes(6));
        $this->fault = (object)['activate'=>null,'restore'=>null,'trace'=>[]];
        $this->control = [];
        $observers = [];
        $diagnostics = null;
        if (in_array($scenario, ['EXT04-EVENTS','EVENT-TRACE-01'], true)) {
            $probe = $this;
            $observers = [new class($probe) implements \Tenant\Contracts\ObservationSink {
                public function __construct(private WorkerProbe $p) {}
                public function observe(\Tenant\Data\RuntimeObservation $o): void {
                    $this->p->trace[]=['O1',$o->kind,$o->subject?->context?->identity->id];
                    try { $this->p->h->runtime->current(); } catch (\Tenant\Exceptions\ContextTransitionInProgress) { $this->p->trace[]=['reentry-refused']; }
                    throw new \RuntimeException('O1');
                }
            }, new class($probe) implements \Tenant\Contracts\ObservationSink {
                public function __construct(private WorkerProbe $p) {}
                public function observe(\Tenant\Data\RuntimeObservation $o): void { $this->p->trace[]=['O2',$o->kind,$o->subject?->context?->identity->id]; }
            }];
            $diagnostics = new class($probe) implements \Tenant\Contracts\DiagnosticSink {
                public function __construct(private WorkerProbe $p) {}
                public function report(\Tenant\Data\Diagnostic $d): void { $this->p->trace[]=['diagnostic']; throw new \RuntimeException('sink'); }
            };
        }
        $fault=$this->fault;
        $compose = function ($builder, $app) use ($fault): void {
            $factory=new Factory(fn()=>new FaultFacility(new \Tenant\Laravel\Internal\Facilities\DatabaseFacility($app['db'],new \Tenant\Laravel\Internal\FrameworkConfig(fn()=>$app['config']),'central'),$fault,'database'));
            $builder->register(new \Tenant\Data\ParticipantDeclaration('lab:fault-db',1,'resource',[new \Tenant\Data\CapabilityId('tenant:database')],[],['tenant:database/default'],[],[],'tenant:database',$factory));
        };
        $this->h=new LabHost($manifest,$session,$compose,$decorated?fn($r)=>new RuntimeDecorator($r):null,$observers,$diagnostics,function(LabHost $h):void {
            $h->app['config']->set('queue',[
                'default'=>'database', 'connections'=>[
                    'sync'=>['driver'=>'sync','after_commit'=>false],
                    'database'=>['driver'=>'database','connection'=>'central','table'=>'jobs','queue'=>'default','retry_after'=>90,'after_commit'=>false],
                ],'failed'=>['driver'=>'database-uuids','database'=>'central','table'=>'failed_jobs'],
            ]);
            $h->app->register(\Illuminate\Queue\QueueServiceProvider::class);
            $key=hash('sha256',file_get_contents($this->manifest['credentials']));
            $h->app->instance(QueueRegistry::class,new QueueRegistry(['lab'],['laravel-pg-database'=>['version'=>1,'modes'=>['tenant','central']]],['lab:product'=>['version'=>1,'refs'=>['selection']]],['lab:handler'=>[
                'producer'=>'lab:producer','purposes'=>['lab:test'],'extensions'=>['lab:product/1'],
                'admit'=>function(array $data,Purpose $purpose) use ($h):void {
                    if($this->revoked)throw new \Tenant\Exceptions\ContextDenied();
                    $state=$h->runtime->current();
                    $this->trace[]=['admit',$state->kind,$state->context?->identity->id];
                    if(($data['expect']??null)!==($state->context?->identity->id??'central'))throw new \RuntimeException('admission wrong target');
                },
                'handle'=>fn(array $data,Job $job)=>$this->handle($data,$job),
                'failed'=>function(array $data,\Throwable $error)use($h):void {
                    $this->trace[]=['failed-handler',$h->runtime->current()->context?->identity->id,$error->getMessage()];
                    $this->check($this->hydrate()===$data['expect'],'failed callback hydration under target');
                },
            ]],$key,new ProfileRef('laravel-pg-database',1),[TrustedCentralProbe::class.'@fire']));
        });
        $h=$this->h;
        \Illuminate\Support\Facades\Facade::clearResolvedInstances();
        \Illuminate\Support\Facades\Facade::setFacadeApplication($h->app);
        \Illuminate\Database\Eloquent\Model::setConnectionResolver($h->app['db']);
        \Illuminate\Database\Eloquent\Model::setEventDispatcher($h->app['events']);
        foreach ([\Illuminate\Queue\Events\JobProcessed::class,\Illuminate\Queue\Events\JobExceptionOccurred::class,\Illuminate\Queue\Events\JobFailed::class,\Illuminate\Queue\Events\JobReleasedAfterException::class] as $event) {
            $h->app['events']->listen($event,function($e)use($h,$event):void {
                $state=$h->runtime->current(); $this->trace[]=[class_basename($event),$state->kind,$state->context?->identity->id];
                if($event===\Illuminate\Queue\Events\JobFailed::class)$h->app['queue.failer']->log($e->connectionName,$e->job->getQueue(),$e->job->getRawBody(),$e->exception);
            });
        }
        $h->app['events']->listen(\Illuminate\Queue\Events\JobAttempted::class,function($e)use($h):void { $this->trace[]=['completed',$h->runtime->current()->kind,$h->runtime->current()->context?->identity->id]; });
        $h->app->singleton(\Illuminate\Contracts\Debug\ExceptionHandler::class,WorkerErrors::class);
    }
    public function check(bool $ok,string $message): void { if(!$ok)throw new \RuntimeException('Assertion: '.$message);$this->assertions++;$this->trace[]=['assert',$message]; }
    public function refuse(\Closure $call): void { try{$call();}catch(\Throwable $e){$this->assertions++;$this->trace[]=['refused',get_class($e),$e->getMessage()];return;}throw new \RuntimeException('Expected refusal'); }
    public function reference(string $id='a',array $extensions=[]): ContextReference
    {
        return new ContextReference(['schema'=>'tenant.context/1','mode'=>'tenant','namespace'=>'lab','isolationId'=>$id,'profileId'=>'laravel-pg-database','profileVersion'=>1,'resourceRef'=>'resource:'.$id,'incarnation'=>'incarnation:'.$id,'routeRevision'=>'route:1','extensions'=>$extensions]);
    }
    public function dispatch(string $op='success',string $target='a',string $connection='database',array $data=[]): void
    {
        $d=$this->h->app->make(ContextDispatcher::class);
        $intent=$target==='central'?$d->freezeCentral($this->h->purpose()):$d->freezeTenant($this->reference($target),$this->h->purpose());
        $d->enqueue($intent,new ScalarJob('lab:producer','lab:handler',['operation'=>$op,'expect'=>$target,...$data],$connection,$this->queue));
    }
    public function worker(int $tries=1): void
    {
        $h=$this->h;$worker=new Worker($h->app['queue'],$h->app['events'],$h->app->make(\Illuminate\Contracts\Debug\ExceptionHandler::class),fn()=>false);
        $worker->setCache($h->app['cache']->store('central'));
        $worker->runNextJob('database',$this->queue,new WorkerOptions(sleep:0,maxTries:$tries,backoff:0));
    }
    private function hydrate(): string
    {
        $id=$this->h->runtime->current()->context?->identity->id;
        if($id===null)return 'central';
        $row=HydratedProbe::query()->findOrFail('identity');$this->trace[]=['hydrate',$row->value,$this->h->db()->getDatabaseName()];return $row->value;
    }
    public function handle(array $data,Job $job): void
    {
        $h=$this->h;$this->check($this->hydrate()===$data['expect'],'hydrate after verified target');
        foreach(['central','identity','billing','broker','jobs','failed_jobs'] as $name){$db=$h->app['db']->connection($name)->selectOne('select current_database() db')->db;$this->check($db===$this->manifest['databases']['central'],'named central '.$name);}
        $op=$data['operation'];$this->trace[]=['handle',$op,$data['expect'],$job->attempts()];
        if($op==='throw')throw new \RuntimeException('E1');
        if($op==='release'&&$job->attempts()===1){$job->release(0);return;}
        if($op==='retry-once'){
            $marker=$this->manifest['root'].'/'.$this->queue.'-retry';
            if(!file_exists($marker)){file_put_contents($marker,'1');throw new \RuntimeException('retry-first');}
        }
        if($op==='nested'){
            $this->dispatch('success','b','sync');$this->check($h->runtime->current()->context->identity->id==='a','sync returns A');
            $this->refuse(fn()=>$this->dispatch('throw','b','sync'));$this->check($h->runtime->current()->context->identity->id==='a','failed sync returns A');
            $this->dispatch('success','central','sync');$this->check($h->runtime->current()->context->identity->id==='a','central sync returns A');
        }
        if($op==='continuation')$this->dispatch('success','a');
        if($op==='v2'){
            $scope=json_decode($data['v2'],true,flags:JSON_THROW_ON_ERROR);$this->validateV2($scope);
            $this->check($scope['account']==='a'&&$scope['workspace']==='w'&&$scope['actor']==='u'&&$scope['restoreEpoch']===str_repeat('a',64)&&$scope['boxed']===false,'v2 scalar fields preserved');
            $this->check($scope['connection']==='pgsql:'.$h->db()->getDatabaseName(),'v2 exact connection identity');
        }
        if($op==='observe'){
            $h->runtime->run($h->selection('a'),$h->purpose(),function()use($h):void {
                try{$h->runtime->run($h->selection('b'),$h->purpose(),fn()=>throw new \RuntimeException('callback-E1'));}catch(\RuntimeException $e){$this->check($e->getMessage()==='callback-E1','observer failures preserve original callback error');}
                $this->check($h->runtime->current()->context->identity->id==='a','observer failure restores A');
            });
            $this->fault->activate='database';$this->refuse(fn()=>$h->runtime->run($h->selection('b'),$h->purpose(),fn()=>null));$this->fault->activate=null;
        }
        if($op==='poison'){
            $this->fault->restore='database';
            try{$h->runtime->run($h->selection('b'),$h->purpose(),fn()=>throw new \RuntimeException('E1'));}catch(\Tenant\Exceptions\ContextRestoreFailed $e){
                $messages=array_map(fn($x)=>$x->getMessage(),$e->causes());
                $this->check(in_array('E1',$messages,true)&&in_array('E2:database',$messages,true),'poison preserves E1 and E2');
                foreach([fn()=>$h->db()->select('select 1'),fn()=>$h->cache()->get('x'),fn()=>$h->storage()->disk()->get('x')]as $io)$this->refuse($io);
                $this->flush('poison-caught');
            }
        }
        if(in_array($op,['stream','stream-throw'],true)){$h->storage()->disk()->put('leaked','bytes');$stream=$h->storage()->disk()->readStream('leaked');$this->control[]=$stream;if($op==='stream-throw')throw new \RuntimeException('E1-with-stream');}
        if($op==='leak'){$h->runtime->enter($h->selection('b'),$h->purpose());}
        if($op==='sentinel')$h->app['db']->connection('central')->statement('insert into probes(name,value) values (?,?)',[$this->queue.'-sentinel',(string)getmypid()]);
    }
    public function armRestoreFault(): void { $this->fault->restore='database'; }
    public function validateV2(array $scope): void
    {
        if(array_keys($scope)!==['version','account','workspace','connection','actor','restoreEpoch','boxed']||$scope['version']!==2||!is_string($scope['account'])||(!is_string($scope['workspace'])&&$scope['workspace']!==null)||!is_string($scope['connection'])||!is_string($scope['actor'])||!is_string($scope['restoreEpoch'])||!preg_match('/^[a-f0-9]{64}$/D',$scope['restoreEpoch'])||!is_bool($scope['boxed']))throw new \InvalidArgumentException('Invalid v2');
        if($scope['connection']!=='pgsql:'.$this->manifest['databases']['a'])throw new \RuntimeException('v2 connection mismatch');
    }
    public function flush(string $label): void
    {
        $dir=getenv('TENANT_OBSERVATIONS');if(!is_dir($dir))mkdir($dir,0700,true);
        file_put_contents($dir.'/'.$label.'.json',json_encode(['pid'=>getmypid(),'queue'=>$this->queue,'assertions'=>$this->assertions,'trace'=>$this->trace],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
    }
    public function execute(string $fixture): array
    {
        $h=$this->h;$d=$h->app->make(ContextDispatcher::class);$codec=new JsonContextCodec();
        if(in_array($fixture,['QUEUE-01','STATE-06','CENTRAL-01'],true)){
            $this->check($h->app->make(QueueContextBoundary::class)===$h->app->make(QueueContextBoundary::class),'one boundary per application');
            $manager=$h->app['queue'];$manager->pause('database',$this->queue);$this->check($manager->isPaused('database',$this->queue),'central pause works before any tenant frame');$manager->resume('database',$this->queue);$this->check(!$manager->isPaused('database',$this->queue),'central resume works before any tenant frame');
            $manager->connection('database')->push(TrustedCentralProbe::class.'@fire',[],$this->queue);$this->worker();$this->check($h->runtime->current()->kind==='central','explicit allowlisted unstamped framework job is Central');
            $outer=$h->runtime->enter($h->selection('a'),$h->purpose());
            $this->dispatch('nested','a','sync');$this->check($h->runtime->current()->context->identity->id==='a','outer sync A retained');
            $h->runtime->restore($outer);$this->check($h->runtime->current()->kind==='central','outer closes to worker Central');
            $this->dispatch('success','a');$this->worker();$this->check($h->runtime->current()->kind==='central','async ends explicit Central');
            $this->dispatch('success','central');$this->worker();
            $failedBefore=$h->app['db']->connection('central')->table('failed_jobs')->where('queue',$this->queue)->count();
            $releaseStart=count($this->trace);
            $this->dispatch('release','b');$this->worker(2);$this->check($h->app['queue']->size($this->queue)===1,'released pending');$this->worker(2);
            $released=array_values(array_filter($this->trace,fn($r)=>$r[0]==='handle'&&$r[1]==='release'));
            $this->check($released===[['handle','release','b',1],['handle','release','b',2]],'release executes both attempts in original B');
            $callbacks=array_values(array_filter(array_slice($this->trace,$releaseStart),fn($r)=>$r[0]==='JobProcessed'));
            $this->check($callbacks===[['JobProcessed','active','b'],['JobProcessed','active','b']],'both release attempts retain B through final framework callback');
            $this->check($h->app['db']->connection('central')->table('failed_jobs')->where('queue',$this->queue)->count()===$failedBefore,'released retry has no failed entry');
            $this->check($h->runtime->current()->kind==='central'&&$h->app['queue']->size($this->queue)===0,'released retry restores Central and completes');
            $this->admissionFailures();
            $this->dispatch('throw','a');$this->worker();
            $this->dispatch('retry-once','a');$this->worker();
            $retry=$h->app['db']->connection('central')->table('failed_jobs')->where('queue',$this->queue)->orderByDesc('id')->first();
            $this->check($retry!==null,'own failed job persisted centrally');
            $failedPayload=json_decode($retry->payload,true,flags:JSON_THROW_ON_ERROR);
            $this->check(json_decode($failedPayload['data']['wire'],true)['isolationId']==='a','stored retry retains original A');
            $cmd=new \Illuminate\Queue\Console\RetryCommand();$cmd->setLaravel($h->app);$status=$cmd->run(new \Symfony\Component\Console\Input\ArrayInput(['id'=>[$retry->uuid]]),new \Symfony\Component\Console\Output\BufferedOutput());$this->check($status===0,'actual queue:retry');$this->worker();
            $retries=array_values(array_filter($this->trace,fn($r)=>$r[0]==='handle'&&$r[1]==='retry-once'));
            $this->check(count($retries)===2&&$retries[0][2]==='a'&&$retries[1][2]==='a','queue:retry actually re-executes own failed payload in A');
            $this->check(!$h->app['db']->connection('central')->table('failed_jobs')->where('uuid',$retry->uuid)->exists(),'queue:retry removes exact failed entry');
            $boundary=$h->app->make(QueueContextBoundary::class);$foreign=new \Illuminate\Queue\Jobs\SyncJob($h->app,'{}','sync',$this->queue);$boundary->finished($foreign,true);$this->check($h->runtime->current()->kind==='central','foreign completion no pop');
            $a=$h->runtime->enter($h->selection('a'),$h->purpose());$b=$h->runtime->enter($h->selection('b'),$h->purpose());$this->refuse(fn()=>$h->runtime->restore($a));$h->runtime->restore($b);$this->refuse(fn()=>$h->runtime->restore($b));$h->runtime->restore($a);
            $other=new LabHost($this->manifest);$f=$other->runtime->enter($other->selection(),$other->purpose());$this->refuse(fn()=>$h->runtime->restore($f));$other->runtime->restore($f);
            foreach($this->trace as $row)if(in_array($row[0],['JobProcessed','JobFailed','JobExceptionOccurred','JobReleasedAfterException'],true))$this->check($row[1]!=='missing','framework callback still scoped');
            // Rejected native object payload must never hydrate even in Laravel's failed path.
            $native=$h->app['queue']->connection('database');$native->push('Illuminate\\Queue\\CallQueuedHandler@call',['commandName'=>HydrationBomb::class,'command'=>serialize(new HydrationBomb())],$this->queue);$this->worker();$this->check(!file_exists($this->manifest['root'].'/hydration-bomb'),'unmarked native object rejected before failure hydration');
        }elseif($fixture==='QUEUE-02'){
            $intent=$d->freezeTenant($this->reference(),$h->purpose());
            $h->app['db']->connection('central')->beginTransaction();$h->app['db']->connection('central')->afterCommit(fn()=>$d->enqueue($intent,new ScalarJob('lab:producer','lab:handler',['operation'=>'continuation','expect'=>'a'],'database',$this->queue)));
            $b=$h->runtime->enter($h->selection('b'),$h->purpose());$h->app['db']->connection('central')->commit();$h->runtime->restore($b);
            $raw=$h->app['db']->connection('central')->table('jobs')->where('queue',$this->queue)->first();$payload=json_decode($raw->payload,true);$this->check(json_decode($payload['data']['wire'],true)['isolationId']==='a','afterCommit carries captured A while B current');$this->worker();$this->worker();
            foreach(['incarnation'=>'stale:1','routeRevision'=>'route:stale']as$key=>$value){$e=$this->reference()->envelope;$e[$key]=$value;$this->refuse(fn()=>$d->freezeTenant(new ContextReference($e),$h->purpose()));}
            $this->dispatch('success','a');$this->revoked=true;$before=count(array_filter($this->trace,fn($r)=>$r[0]==='handle'));$this->worker();$this->check(count(array_filter($this->trace,fn($r)=>$r[0]==='handle'))===$before,'fresh worker admission denies revoked producer');
        }elseif($fixture==='QUEUE-04'){
            $scope=['version'=>2,'account'=>'a','workspace'=>'w','connection'=>'pgsql:'.$this->manifest['databases']['a'],'actor'=>'u','restoreEpoch'=>str_repeat('a',64),'boxed'=>false];
            foreach([null,'w']as$workspace){$scope['workspace']=$workspace;$this->validateV2($scope);$this->check(array_keys($scope)===['version','account','workspace','connection','actor','restoreEpoch','boxed'],'v2 ordered shape');}$scope['workspace']='w';
            foreach(['version','connection','workspace','boxed']as$key){$bad=$scope;if($key==='version')$bad[$key]=1;elseif($key==='connection')$bad[$key]='wrong';elseif($key==='boxed')$bad[$key]='false';else unset($bad[$key]);$this->refuse(fn()=>$this->validateV2($bad));}
            $this->dispatch('v2','a',data:['v2'=>json_encode($scope)]);$this->worker();
            $e=$this->reference()->envelope;$e['incarnation']='old:instance';$this->refuse(fn()=>$d->freezeTenant(new ContextReference($e),$h->purpose()));
        }elseif(in_array($fixture,['EXT04-EVENTS','EVENT-TRACE-01'],true)){
            $this->dispatch('observe','a');$this->worker();
            $o2=array_values(array_filter($this->trace,fn($r)=>$r[0]==='O2'));$kinds=array_column($o2,1);
            foreach(['entered','callback_failed','restored','activation_failed']as$kind)$this->check(in_array($kind,$kinds,true),'O2 received '.$kind);
            $this->check(count(array_filter($this->trace,fn($r)=>$r[0]==='reentry-refused'))>0,'observer reentry refused');
            $this->check(count(array_filter($this->trace,fn($r)=>$r[0]==='diagnostic'))===count(array_filter($this->trace,fn($r)=>$r[0]==='O1')),'diagnostics isolated per listener');
            $this->check($h->runtime->current()->kind==='central','observed worker restores');
        }elseif(in_array($fixture,['WIRE-01','CODEC-01'],true)){$this->wire($codec,$d);}
        else throw new \LogicException('Unsupported fixture '.$fixture);
        return ['fixture'=>$fixture,'variant'=>$this->decorated?'decorator':'default','pid'=>getmypid(),'assertions'=>$this->assertions,'trace'=>$this->trace,'queue'=>$this->queue];
    }
    private function admissionFailures(): void
    {
        $h=$this->h;
        foreach(['resolve','activate'] as $failure){
            $before=count(array_filter($this->trace,fn($r)=>$r[0]==='handle'));
            $failedBefore=$h->app['db']->connection('central')->table('failed_jobs')->where('queue',$this->queue)->count();
            $this->dispatch('success','b');
            if($failure==='resolve')$h->owner->available=false;else $this->fault->activate='database';
            $this->worker(3);
            $this->check(count(array_filter($this->trace,fn($r)=>$r[0]==='handle'))===$before,'pre-hydration '.$failure.' failure has no handler effect');
            $this->check($h->app['queue']->size($this->queue)===1,'pre-hydration '.$failure.' failure preserves durable job for release');
            $this->check($h->app['db']->connection('central')->table('failed_jobs')->where('queue',$this->queue)->count()===$failedBefore,'retryable failure is not prematurely failed');
            $h->owner->available=true;$this->fault->activate=null;$this->worker(3);
            $last=array_values(array_filter($this->trace,fn($r)=>$r[0]==='handle'));
            $this->check(end($last)===['handle','success','b',2]&&$h->app['queue']->size($this->queue)===0,'recovered '.$failure.' retry performs original B effect at attempt2');
        }
        $this->dispatch('success','b');$this->fault->activate='database';
        $this->worker(2);$this->worker(2);$this->fault->activate=null;
        $failed=$h->app['db']->connection('central')->table('failed_jobs')->where('queue',$this->queue)->orderByDesc('id')->first();
        $this->check($failed!==null&&$h->app['queue']->size($this->queue)===0,'exhausted activation failure persists failed job');
        $payload=json_decode($failed->payload,true,flags:JSON_THROW_ON_ERROR);
        $this->check(json_decode($payload['data']['wire'],true)['isolationId']==='b','failed activation keeps original signed B payload');
        $cmd=new \Illuminate\Queue\Console\RetryCommand();$cmd->setLaravel($h->app);
        $status=$cmd->run(new \Symfony\Component\Console\Input\ArrayInput(['id'=>[$failed->uuid]]),new \Symfony\Component\Console\Output\BufferedOutput());
        $this->check($status===0,'queue:retry accepts failed activation UUID');$this->worker(2);
        $last=array_values(array_filter($this->trace,fn($r)=>$r[0]==='handle'));
        $this->check(end($last)===['handle','success','b',1]&&!$h->app['db']->connection('central')->table('failed_jobs')->where('uuid',$failed->uuid)->exists(),'failed activation retry actually executes in B and removes exact failure');
    }
    private function wire(JsonContextCodec $codec,ContextDispatcher $d): void
    {
        $json=$codec->encode($this->reference());$this->check($codec->encode($codec->decode($json))===$json,'canonical roundtrip');
        $bad=['[]','null','42',str_replace('"mode":"tenant"','"mode":"tenant","mode":"tenant"',$json),str_replace('"mode":"tenant"','"m\\u006fde":"tenant","mode":"tenant"',$json),str_repeat(' ',16385).$json,str_replace('"isolationId":"a"','"isolationId":"e\\u0301"',$json),str_replace('"profileVersion":1','"profileVersion":"1"',$json),str_replace('"isolationId":"a"', '"isolationId":"'.str_repeat('é',49).'"',$json),str_replace('"extensions":[]','"extensions":[[[[[]]]]]',$json),"\xff",str_replace('"extensions":[]','"extensions":{}',$json)];
        $central=$d->freezeCentral($this->h->purpose())->reference->envelope;$central['isolationId']=null;$bad[]=json_encode($central);
        $ext=['type'=>'lab:product','version'=>1,'required'=>true,'refs'=>['selection'=>'w']];$e=$this->reference()->envelope;$e['extensions']=[$ext,$ext];$bad[]=json_encode($e);
        foreach (['profileVersion'=>0,'mode'=>'unknown','isolationId'=>17,'dsn'=>'pgsql://forbidden','profileId'=>'Bad!'] as $key=>$value) { $x=$this->reference()->envelope;$x[$key]=$value;$bad[]=json_encode($x); }
        $x=$this->reference()->envelope;$x['extensions']=[];for($i=0;$i<17;$i++)$x['extensions'][]=['type'=>'vendor:ref'.$i,'version'=>1,'required'=>false,'refs'=>['ref'=>'x']];$bad[]=json_encode($x);
        foreach(['empty','many','nested','long'] as $case){$x=$this->reference()->envelope;$r=['ref'=>'x'];if($case==='empty')$r=[];if($case==='many'){for($i=0;$i<17;$i++)$r['ref'.$i]='x';}if($case==='nested')$r=['ref'=>['nested'=>'x']];if($case==='long')$r=['ref'=>str_repeat('é',65)];$x['extensions']=[['type'=>'vendor:ref','version'=>1,'required'=>false,'refs'=>$r]];$bad[]=json_encode($x);}
        foreach($bad as$wire)$this->refuse(fn()=>$codec->decode($wire));
        $h=$this->h;$registry=$h->app->make(QueueRegistry::class);
        // Every wire vector, including parser/registry negatives, also traverses actual Worker.
        $valid=FrozenIntent::payload($d->freezeTenant($this->reference(),$h->purpose()),new ScalarJob('lab:producer','lab:handler',['operation'=>'success','expect'=>'a']),$codec,$registry);
        foreach($bad as$wire){if(!preg_match('//u',$wire)) { $native=json_encode(['uuid'=>\Illuminate\Support\Str::uuid()->toString(),'job'=>ScalarHandler::class.'@fire','data'=>$valid]);$native=str_replace('tenant.context/1',"tenant.\xff/1",$native);$h->app['queue']->connection('database')->pushRaw($native,$this->queue);$this->worker();$this->check(!file_exists($this->manifest['root'].'/hydration-bomb'),'worker refuses nonUTF8 raw transport');continue; } $p=$valid;$p['wire']=$wire;$p['signature']=$registry->sign(FrozenIntent::bytes($p));$before=count(array_filter($this->trace,fn($r)=>$r[0]==='handle'));$h->app['queue']->connection('database')->push(ScalarHandler::class.'@fire',$p,$this->queue);$this->worker();$this->check(count(array_filter($this->trace,fn($r)=>$r[0]==='handle'))===$before,'worker rejects malformed wire before effect');}
        foreach(['optional','required','known','malformed','retired','profile','namespace','forged','producer','handler','purpose','route','incarnation'] as$case){
            $e=$this->reference()->envelope;$p=$valid;$success=false;
            if($case==='optional'){$e['extensions']=[['type'=>'unknown:optional','version'=>1,'required'=>false,'refs'=>['ref'=>'x']]];$success=true;}
            if($case==='known'){$e['extensions']=[$ext];$success=true;}
            if($case==='required')$e['extensions']=[['type'=>'unknown:required','version'=>1,'required'=>true,'refs'=>['ref'=>'x']]];
            if($case==='malformed'){$ext2=$ext;$ext2['refs']=['password'=>'secret'];$e['extensions']=[$ext2];}
            if($case==='retired'){$ext2=$ext;$ext2['version']=2;$e['extensions']=[$ext2];}
            if($case==='profile')$e['profileId']='unregistered';
            if($case==='namespace')$e['namespace']='foreign';
            if($case==='route')$e['routeRevision']='route:old';
            if($case==='incarnation')$e['incarnation']='incarnation:old';
            $p['wire']=json_encode($e);if($case==='purpose')$p['purpose']='evil:purpose';if($case==='producer')$p['producer']='foreign:producer';if($case==='handler')$p['handler']='foreign:handler';$p['signature']=$registry->sign(FrozenIntent::bytes($p));if($case==='forged')$p['signature']=str_repeat('0',64);
            $before=count(array_filter($this->trace,fn($r)=>$r[0]==='handle'));$h->app['queue']->connection('database')->push(ScalarHandler::class.'@fire',$p,$this->queue);$this->worker();$after=count(array_filter($this->trace,fn($r)=>$r[0]==='handle'));$this->check($after-$before===($success?1:0),'worker wire '.$case);
        }
        $this->dispatch('success','a');$this->revoked=true;$before=count(array_filter($this->trace,fn($r)=>$r[0]==='handle'));$this->worker();$this->check(count(array_filter($this->trace,fn($r)=>$r[0]==='handle'))===$before,'retired membership refuses fresh admission');
    }
}
final class HydrationBomb
{
    public function __wakeup(): void {$m=json_decode(file_get_contents(getenv('TENANT_LAB_MANIFEST')),true);file_put_contents($m['root'].'/hydration-bomb','unsafe');}
}

final class TrustedCentralProbe
{
    public function fire(Job $job,array $data): void
    {
        $app=\Illuminate\Container\Container::getInstance();
        if($app->make(\Tenant\Contracts\TenantRuntime::class)->current()->kind!=='central')throw new \RuntimeException('Allowlisted job inherited tenant');
        $app['db']->connection('central')->select('select current_database()');
    }
}
