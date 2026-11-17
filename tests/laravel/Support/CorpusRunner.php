<?php

declare(strict_types=1);

namespace TenantLab;

use Closure;
use Tenant\Contracts\{Facility, FrameState, PhaseContext, RestoreContext, ParticipantFactory};
use Tenant\Contracts\Runtime\TenantRuntime;
use Tenant\Data\{ParticipantDeclaration, CapabilityId};
use Tenant\Exceptions\TenantFailure;

final class Factory implements ParticipantFactory
{
    public function __construct(private Closure $make) {}
    public function create(): Facility { return ($this->make)(); }
}
final readonly class RuntimeDecorator implements TenantRuntime
{
    public function __construct(public TenantRuntime $delegate) {}
    public function current(): \Tenant\Data\ContextState { return $this->delegate->current(); }
    public function enter(\Tenant\Data\Selection $s, \Tenant\Data\Purpose $p): \Tenant\Data\FrameToken { return $this->delegate->enter($s,$p); }
    public function restore(\Tenant\Data\FrameToken $t): void { $this->delegate->restore($t); }
    public function run(\Tenant\Data\Selection $s, \Tenant\Data\Purpose $p, Closure $c): mixed { return $this->delegate->run($s,$p,$c); }
}
final class FaultFacility implements Facility
{
    public function __construct(private Facility $delegate, private object $control, private string $name) {}
    public function prepare(PhaseContext $c, FrameState $s): void { $this->delegate->prepare($c,$s); $s->put('subject', $c->candidate()->context?->identity->id); }
    public function activate(PhaseContext $c, FrameState $s): void
    {
        $this->delegate->activate($c,$s);
        $this->control->trace[] = 'activate:'.$this->name.':'.$s->get('subject');
        if ($s->get('subject') === 'b' && $this->control->activate === $this->name) throw new \RuntimeException('E1:'.$this->name);
    }
    public function restore(RestoreContext $c, FrameState $s): void
    {
        $this->control->trace[] = 'restore:'.$this->name.':'.$s->get('subject');
        $this->delegate->restore($c,$s);
        if ($s->get('subject') === 'b' && $this->control->restore === $this->name) throw new \RuntimeException('E2:'.$this->name);
    }
    public function verify(RestoreContext $c, FrameState $s): void { $this->delegate->verify($c,$s); }
}
final class ProductFacility implements Facility
{
    public function __construct(private \Illuminate\Contracts\Config\Repository $config, private string $id, private object $control) {}
    public function prepare(PhaseContext $c, FrameState $s): void { $s->put('previous', $this->config->get('lab.'.$this->id)); }
    public function activate(PhaseContext $c, FrameState $s): void
    {
        $context=$c->candidate()->context;
        $selection=$context?->extensions[0]->refs['selection']??$context?->identity->id;
        $this->config->set('lab.'.$this->id,$selection);
        if ($this->control->activate === $this->id) throw new \RuntimeException('E1:'.$this->id);
    }
    public function restore(RestoreContext $c, FrameState $s): void
    {
        $this->control->trace[] = $this->id;
        $this->config->set('lab.'.$this->id,$s->get('previous'));
        if ($this->control->restore === $this->id) throw new \RuntimeException('E2:'.$this->id);
    }
    public function verify(RestoreContext $c, FrameState $s): void { if ($this->config->get('lab.'.$this->id) !== $s->get('previous')) throw new \RuntimeException('snapshot'); }
}

final class CorpusRunner
{
    public array $observations = [];
    public int $assertions = 0;
    private array $manifest;
    public function __construct(private bool $decorated = false)
    {
        $this->manifest = json_decode(file_get_contents(getenv('TENANT_LAB_MANIFEST')),true,flags:JSON_THROW_ON_ERROR);
    }
    public function check(bool $condition, string $message): void
    {
        $this->assertions++;
        if (!$condition) throw new \RuntimeException('Assertion failed: '.$message);
        $this->observations[] = $message;
    }
    public function denied(Closure $callback, string $reason): \Throwable
    {
        try { $callback(); } catch (\Throwable $e) {
            $this->check($e instanceof TenantFailure ? $e->reason() === $reason : str_contains($e->getMessage(),$reason), 'refused '.$reason.' actual '.get_class($e).':'.$e->getMessage());
            return $e;
        }
        throw new \RuntimeException('Expected refusal: '.$reason);
    }
    public function host(?Closure $compose = null, string $session = 'file', array $observers = [], ?\Tenant\Contracts\DiagnosticSink $diagnostics = null): LabHost
    {
        return new LabHost($this->manifest,$session,$compose,$this->decorated ? fn ($runtime) => new RuntimeDecorator($runtime) : null,$observers,$diagnostics);
    }
    private function identity(LabHost $h, string $id): void
    {
        $rows=$h->db()->select("select current_database() as db, current_user as role, value from probes where name='identity'");
        $this->check($rows[0]->value === $id && $rows[0]->db === $this->manifest['databases'][$id] && $rows[0]->role === $this->manifest['runtime'],'actual PG16 '.$id.' restricted role');
    }
    private function declaration(string $id, Closure $make, array $caps = [], array $slots = [], array $after = [], ?string $replaces = null, string $stage = 'resource', int $spi = 1, array $requires = []): ParticipantDeclaration
    {
        return new ParticipantDeclaration($id,$spi,$stage,array_map(fn ($id) => new CapabilityId($id),$caps),array_map(fn ($id) => new CapabilityId($id),$requires),$slots,[],$after,$replaces,new Factory($make));
    }
    public function execute(string $fixture): array
    {
        match ($fixture) {
            'DB-01' => $this->database(),
            'CACHE-PROCESS' => $this->cacheProcesses(),
            'STATE-05' => $this->lifetime(),
            'STATE-03' => $this->journal(false),
            'STATE-04' => $this->journal(true),
            'STATE-07' => $this->configuration(),
            'CENTRAL-01' => $this->central(),
            'STORAGE-01' => $this->storage(),
            'EXT06-STREAM' => $this->stream(),
            'SESSION-01' => $this->session(),
            'EXT01-PLUGIN' => $this->plugin(),
            'EXT02-ORDER' => $this->order(),
            'EXT02-NEGATIVE' => $this->negative(),
            'EXT03-SNAPSHOTS' => $this->snapshots(),
            'EXT04-EVENTS', 'EVENT-TRACE-01' => $this->events(),
            'EXT05-OWNER' => $this->provider(),
            default => throw new \RuntimeException('Undiscovered fixture '.$fixture),
        };
        return ['fixture'=>$fixture,'variant'=>$this->decorated?'decorator':'default','assertions'=>$this->assertions,'observations'=>$this->observations,'php'=>PHP_VERSION,'pid'=>getmypid(),'pendingProcessProof'=>'P3.2 owns final worker/FPM stop; P3.1 proves IO refusal only'];
    }
    private function database(): void
    {
        $h=$this->host();
        $this->denied(fn ()=>$h->db()->select('select 1'),'missing');
        $h->runtime->run($h->selection(),$h->purpose(),function()use($h){
            $this->identity($h,'a'); $db=$h->db();
            foreach(['transaction','cursor'] as $resource){
                if($resource==='transaction')$db->beginTransaction();else{$cursor=$db->cursor('select * from probes');$cursor->rewind();}
                $this->denied(fn()=>$h->runtime->enter($h->selection('b'),$h->purpose()),'open_resource');$this->identity($h,'a');
                if($resource==='transaction')$db->rollBack();else { while($cursor->valid()) $cursor->next(); unset($cursor); }
            }
            $h->runtime->run($h->selection('b'),$h->purpose(),fn()=>$this->identity($h,'b'));
            $this->identity($h,'a');
            $h->app['db']->connection('central')->beginTransaction();
            $h->runtime->run($h->selection('b'),$h->purpose(),fn()=>$this->identity($h,'b'));
            $h->app['db']->connection('central')->rollBack();$this->check(true,'distinct named central transaction unaffected');
            $h->db()->transaction(fn()=>$this->identity($h,'a'));
        });
        foreach(['url','driver','host','port','database','username','read','write'] as $key){
            try {new \Tenant\Laravel\Data\ResourceDescriptor($h->owner->route('a'),'p:','/tmp/a','/tmp/b','/tmp/c',[$key=>'evil']);throw new \RuntimeException('not denied');}
            catch(\InvalidArgumentException){$this->check(true,'routing option denied '.$key);}
        }
        $central=$h->app['config']->get('database.connections.central');
        $url='postgresql://'.rawurlencode($central['username']).':'.rawurlencode($central['password']).'@127.0.0.1:'.$central['port'].'/'.$central['database'].'?application_name=url-central';
        $h->app['config']->set('database.connections.central',['driver'=>'pgsql','url'=>$url,'read'=>['host'=>'bad'],'write'=>['host'=>'bad']]);
        $h->runtime->run($h->selection(),$h->purpose(),function()use($h){$this->identity($h,'a');$cfg=$h->app['config']->get('database.connections.tenant');$this->check(!isset($cfg['url'],$cfg['read'],$cfg['write'])&&$cfg['application_name']==='url-central','URL options preserved, replicas stripped');});
    }
    private function cacheProcesses(): void
    {
        $h=$this->host();$key='cross-process-'.bin2hex(random_bytes(6));
        $h->runtime->run($h->selection(),$h->purpose(),function()use($h,$key){
            $h->cache()->put($key,'A',60);
            if(method_exists(\Illuminate\Contracts\Cache\Store::class,'touch'))$this->check($h->cache()->touch($key,60),'Laravel13 guarded Redis touch');else $this->denied(fn()=>$h->cache()->touch($key,60),'unsupported_capability');
            $lock=$h->cache()->lock($key.':lock',15);$this->check($lock->get(),'parent Redis A lock acquired');
            try {
                foreach(['a','b'] as $id){
                    $process=new \Symfony\Component\Process\Process([PHP_BINARY,TENANT_SOURCE_ROOT.'/tests/laravel/Support/cache-process.php',$id,$key],TENANT_SOURCE_ROOT,['TENANT_LAB_MANIFEST'=>getenv('TENANT_LAB_MANIFEST'),'TENANT_LANE_AUTOLOAD'=>TENANT_LANE_AUTOLOAD,'TENANT_USE_SOURCE'=>getenv('TENANT_USE_SOURCE')?:'0']);
                    $process->mustRun();$v=json_decode($process->getOutput(),true,flags:JSON_THROW_ON_ERROR);
                    $this->check($v['pid']!==getmypid()&&$v['value']===($id==='a'?'A':null)&&$v['lockAcquired']===($id==='b'),'independent Redis process '.$id.' value/lock isolation pid '.$v['pid']);
                    $this->observations[]=$v;
                }
            }finally{$lock->release();$h->cache()->forget($key);}
            $session=$h->app['session']->driver();$session->put('outer','A');$id=$session->getId();
            $h->runtime->run($h->selection('b'),$h->purpose(),function()use($h){$this->check($h->app['session']->driver()->get('outer')===null,'B live Store starts without A attributes');$h->app['session']->driver()->put('inner','B');});
            $this->check($session->get('outer')==='A'&&$session->get('inner')===null&&$session->getId()===$id,'A live Store attributes and ID restored per frame');
        });
    }
    private function lifetime(): void
    {
        $h=$this->host();
        $h->runtime->run($h->selection(),$h->purpose(),function()use($h){
            $db=$h->db();$cache=$h->cache();$storage=$h->storage()->disk();$session=$h->app['session']->driver()->getHandler();
            $storage->put('lifetime.txt','a');$cache->put('lifetime','a',60);
            $h->runtime->run($h->selection('b'),$h->purpose(),fn()=>$this->identity($h,'b'));
            foreach([fn()=>$db->select('select 1'),fn()=>$cache->get('lifetime'),fn()=>$storage->get('lifetime.txt'),fn()=>$session->read(str_repeat('a',40))] as $call)$this->denied($call,'stale_handle');
            $this->identity($h,'a');$this->check($h->cache()->get('lifetime')==='a'&&$h->storage()->disk()->get('lifetime.txt')==='a','fresh A services work; held services never revive');
        });
    }
    private function faultComposition(object $control): Closure
    {
        return function($builder,$app)use($control){
            $config=new \Tenant\Laravel\Internal\FrameworkConfig(fn()=>$app['config']);
            $defs=[
                'database'=>[fn()=>new \Tenant\Laravel\Internal\Facilities\DatabaseFacility($app['db'],$config,'central'),'tenant:database'],
                'cache'=>[fn()=>new \Tenant\Laravel\Internal\Facilities\CacheFacility($app['cache'],$config,['tenant']),'tenant:cache'],
                'session'=>[fn()=>new \Tenant\Laravel\Internal\Facilities\SessionFacility($app['session'],$app['cache'],$app,$config,$app->make(\Tenant\Laravel\Internal\Facilities\SessionGeneration::class)),'tenant:session'],
                'scope'=>[fn()=>new \Tenant\Laravel\Internal\Facilities\ScopeGenerationFacility($app),'tenant:scope-generation'],
            ];
            foreach($defs as $name=>[$make,$cap]) $builder->register($this->declaration('lab:'.$name,fn()=>new FaultFacility($make(),$control,$name),[$cap],['tenant:'.$name.'/default'],$name==='database'?[]:['tenant:database'],'tenant:'.$name));
        };
    }
    private function journal(bool $poison): void
    {
        foreach(['database','cache','scope','session'] as $name){
            $control=(object)['activate'=>null,'restore'=>null,'trace'=>[]];$h=$this->host($this->faultComposition($control));
            $outer=$h->runtime->enter($h->selection(),$h->purpose());$this->identity($h,'a');$baseline=$h->app['config']->all();
            if($poison){
                $control->restore=$name;$held=$h->db();$cache=$h->cache();$storage=$h->storage()->disk();$handler=$h->app['session']->driver()->getHandler();
                $failure=$this->denied(fn()=>$h->runtime->run($h->selection('b'),$h->purpose(),fn()=>throw new \RuntimeException('E1:callback')),'restore_failed');
                $causes=array_map(fn($e)=>$e->getMessage(),$failure->causes());
                $this->check(in_array('E1:callback',$causes,true)&&in_array('E2:'.$name,$causes,true),'original and cleanup cause retained '.$name);
                foreach([fn()=>$held->select('select 1'),fn()=>$cache->get('k'),fn()=>$storage->get('lifetime.txt'),fn()=>$handler->read(str_repeat('a',40))] as $call)$this->denied($call,'runtime_poisoned');
            }else{
                $control->activate=$name;$this->denied(fn()=>$h->runtime->enter($h->selection('b'),$h->purpose()),'switch_failed');
                $this->identity($h,'a');$this->check($baseline===$h->app['config']->all(),'exact framework config after partial '.$name);
                $started=array_values(array_filter($control->trace,fn($x)=>str_starts_with($x,'activate:')&&str_ends_with($x,':b')));
                $restored=array_values(array_filter($control->trace,fn($x)=>str_starts_with($x,'restore:')&&str_ends_with($x,':b')));
                $this->check(array_map(fn($x)=>str_replace('activate:','restore:',$x),array_reverse($started))===$restored,'reverse includes throwing participant '.$name);
                $control->activate=null;try{$h->runtime->run($h->selection('b'),$h->purpose(),function()use($h){$this->identity($h,'b');throw new \LogicException('callback');});}catch(\LogicException $e){$this->check($e->getMessage()==='callback','callback rethrown');}$this->identity($h,'a');$h->runtime->restore($outer);
            }
        }
    }
    private function configuration(): void
    {
        $h=$this->host();$h->app['config']->set('cache.stores.tenant.driver','redis');$cfg=clone $h->app['config'];
        $cfg->set('tenant.facilities.cache',false);$this->denied(fn()=>(new \Tenant\Laravel\Internal\Facilities\FacilitiesValidator($cfg))->assertValid(),'facility_configuration');
        $cfg->set('tenant.shared.cache',['owner'=>'host:cache','purpose'=>'host:metadata']);(new \Tenant\Laravel\Internal\Facilities\FacilitiesValidator($cfg))->assertValid();$this->check(true,'explicit shared cache owner/purpose permitted');
        $cfg=clone $h->app['config'];$cfg->set('cache.stores.tenant.driver','file');$this->denied(fn()=>(new \Tenant\Laravel\Internal\Facilities\FacilitiesValidator($cfg))->assertValid(),'facility_configuration');
        foreach(['cookie','database','memcached'] as $driver){$cfg=clone $h->app['config'];$cfg->set('cache.stores.tenant.driver','redis');$cfg->set('session.driver',$driver);$this->denied(fn()=>(new \Tenant\Laravel\Internal\Facilities\FacilitiesValidator($cfg))->assertValid(),'facility_configuration');}
        foreach([
            ['tenant.cache_stores'=>['ghost']],
            ['tenant.storage_driver'=>'s3'],
            ['cache.default'=>'central'],
            ['session.files'=>'relative'],
            ['session.driver'=>'redis','session.store'=>null],
            ['session.driver'=>'redis','session.store'=>'tenant'],
            ['session.driver'=>'redis','session.store'=>'sessions','cache.stores.sessions.driver'=>'array'],
            ['tenant.central_cache_stores'=>['tenant']],
        ] as $changes){$cfg=clone $h->app['config'];foreach($changes as $key=>$value)$cfg->set($key,$value);$this->denied(fn()=>(new \Tenant\Laravel\Internal\Facilities\FacilitiesValidator($cfg))->assertValid(),'facility_configuration');}
        $h->runtime->run($h->selection(),$h->purpose(),fn()=>$this->identity($h,'a'));
    }
    private function central(): void
    {
        $h=$this->host();$before=$h->app['config']->get('database.connections');
        $h->runtime->run($h->selection(),$h->purpose(),function()use($h){
            foreach(['identity','billing','broker','jobs','failed_jobs'] as $name)$this->check($h->app['db']->connection($name)->select("select value from probes where name='identity'")[0]->value==='central','named '.$name.' stays central');
            $h->runtime->run($h->central(),$h->purpose(),function()use($h){$this->check($h->runtime->current()->kind==='central','explicit clean Central frame');$this->denied(fn()=>$h->db()->select('select 1'),'denied');$this->denied(fn()=>$h->cache()->get('k'),'denied');$this->denied(fn()=>$h->storage(),'denied');});
            $this->identity($h,'a');
        });
        $this->check($before===$h->app['config']->get('database.connections'),'named connection configs byte exact after close; actual dispatch is P3.2');
    }
    private function storage(): void
    {
        $h=$this->host();$h->runtime->run($h->selection(),$h->purpose(),function()use($h){
            $d=$h->storage()->disk();$d->makeDirectory('archive/nested');$d->put('archive/nested/x','document');$d->prepend('archive/nested/x','first');$d->append('archive/nested/x','last');
            $this->check($d->get('archive/nested/x')==="first\ndocument\nlast"&&$d->size('archive/nested/x')===19,'local read/write/prepend/append/size');
            $this->check(is_int($d->lastModified('archive/nested/x'))&&$d->setVisibility('archive/nested/x','private')&&$d->getVisibility('archive/nested/x')==='private','local timestamps/visibility');
            $this->check(in_array('archive/nested/x',$d->files(null,true),true)&&in_array('archive/nested',$d->allDirectories(),true)&&in_array('archive',$d->directories(),true),'null/recursive filesystem lists');
            $d->putFileAs('uploads',__FILE__,'source.php');$this->check($d->copy('archive/nested/x','copy')&&$d->move('copy','moved'),'upload/copy/move');
            $h->storage()->ephemeralDisk()->put('same','ephemeral');$d->put('same','durable');$this->check($d->get('same')==='durable'&&$h->storage()->ephemeralDisk()->get('same')==='ephemeral'&&$h->storage()->usageBytes()>0,'distinct durable/ephemeral roots and usage');
            foreach(['../escape','%252e%252e/escape','/absolute','%2fabsolute','C:escape','%43%3aescape','\\escape','%255cescape',"nul\0",'nul%2500'] as $path){
                foreach([fn()=>$d->delete(['same',$path]),fn()=>$d->copy('same',$path),fn()=>$d->move($path,'same'),fn()=>$d->putFileAs('uploads',__FILE__,$path)] as $call){try{$call();throw new \LogicException('path not refused');}catch(\RuntimeException $e){$this->check(!$e instanceof \LogicException,'path refused before mutation');}}
                $this->check($d->get('same')==='durable','batch validated before mutation');
            }
            $root=$h->owner->descriptor('a')->durableRoot;$link=$root.'/escape';if(is_link($link))unlink($link);symlink($this->manifest['root'],$link);
            try{$this->denied(fn()=>$d->put('escape/out','unsafe'),'symbolic');}finally{unlink($link);}
            $h->runtime->run($h->selection('b'),$h->purpose(),function()use($h,$d){$this->check(!$h->storage()->disk()->exists('same'),'B cannot see A local file');$this->denied(fn()=>$d->get('same'),'stale_handle');});
            $this->denied(fn()=>$d->get('same'),'stale_handle');
        });
    }
    private function stream(): void
    {
        $h=$this->host();$h->runtime->run($h->selection(),$h->purpose(),function()use($h){
            $d=$h->storage()->disk();$d->put('stream','stream-content');$s=$d->readStream('stream');$this->check($s->read(6)==='stream','actual tracked read');
            $this->denied(fn()=>$h->runtime->enter($h->selection('b'),$h->purpose()),'open_resource');$this->identity($h,'a');$s->close();$s->close();
            $h->runtime->run($h->selection('b'),$h->purpose(),fn()=>$this->identity($h,'b'));$this->denied(fn()=>$d->get('stream'),'stale_handle');$this->denied(fn()=>$h->storage()->disk()->path('stream'),'unsupported_capability');
        });
        $h=$this->host();$leaked=null;$this->denied(function()use($h,&$leaked){$h->runtime->run($h->selection(),$h->purpose(),function()use($h,&$leaked){$leaked=$h->storage()->disk()->readStream('stream');});},'frame_leak');
        $this->denied(fn()=>$leaked->read(1),'runtime_poisoned');$leaked->close();$this->check(true,'leak is fatal; process sentinel qualification remains P3.2');
    }
    private function session(): void { (new HttpScenarios($this))->run($this->manifest,$this->decorated); }
    private function plugin(): void
    {
        $control=(object)['activate'=>null,'restore'=>null,'trace'=>[]];
        $h=$this->host(function($builder,$app)use($control){
            $delegate=$app->make(\Tenant\Contracts\Context\ContextResolver::class);
            $app->instance(\Tenant\Contracts\Context\ContextResolver::class,new class($delegate) implements \Tenant\Contracts\ContextResolver {
                public function __construct(public \Tenant\Contracts\ContextResolver $delegate) {}
                public function resolve(\Tenant\Data\Selection $s,\Tenant\Data\Purpose $p): \Tenant\Data\ResolvedIsolation { return $this->delegate->resolve($s,$p); }
            });
            $config=new \Tenant\Laravel\Internal\FrameworkConfig(fn()=>$app['config']);
            $builder->register($this->declaration('acme:database',fn()=>new \Tenant\Laravel\Internal\Facilities\DatabaseFacility($app['db'],$config,'central'),['tenant:database','acme:feature'],['tenant:database/default'],[],'tenant:database'));
        });
        $h->runtime->run($h->selection(),$h->purpose(),function()use($h){$this->identity($h,'a');$this->check($h->storage()->disk() instanceof \Tenant\Laravel\Internal\Storage\GuardedFilesystem,'external capability + replacement actual IO');});
    }
    private function order(): void
    {
        $orders=[];
        foreach([['a','b','c'],['a','c','b'],['b','a','c'],['b','c','a'],['c','a','b'],['c','b','a']] as $ids){
            $control=(object)['activate'=>null,'restore'=>null,'trace'=>[]];
            $h=$this->host(function($builder,$app)use($ids,$control){foreach($ids as $id)$builder->register($this->declaration('acme:'.$id,fn()=>new ProductFacility($app['config'],$id,$control),[],[], $id==='b'?['acme:a']:[],null,$id==='c'?'product':'resource'));});
            $h->runtime->run($h->selection(),$h->purpose(),fn()=>$this->identity($h,'a'));$orders[]=$h->app->make('tenant.composition')->orderedIds;
        }
        $this->check(count(array_unique(array_map('json_encode',$orders)))===1,'all six permutations compile identically before actual IO');
    }
    private function negative(): void
    {
        $noop=fn()=>new class implements Facility {public function prepare(PhaseContext $c,FrameState $s):void{}public function activate(PhaseContext $c,FrameState $s):void{}public function restore(RestoreContext $c,FrameState $s):void{}public function verify(RestoreContext $c,FrameState $s):void{}};
        $cases=[
            'exclusive_slot_conflict'=>[$this->declaration('acme:x',$noop,[],['tenant:database/default'])],
            'missing_dependency'=>[$this->declaration('acme:x',$noop,[],[],['acme:absent'])],
            'unsupported_capability'=>[$this->declaration('acme:x',$noop,[],[],[],null,'resource',1,['acme:absent'])],
            'dependency_cycle'=>[$this->declaration('acme:x',$noop,[],[],['acme:y']),$this->declaration('acme:y',$noop,[],[],['acme:x'])],
            'incompatible_spi'=>[$this->declaration('acme:x',$noop,[],[],[],null,'resource',2)],
            'replacement_target_missing'=>[$this->declaration('acme:x',$noop,[],[],[],'acme:absent')],
        ];
        foreach($cases as $reason=>$decls)$this->denied(fn()=>$this->host(function($builder)use($decls){foreach($decls as $d)$builder->register($d);}),$reason);
        $builder=new \Tenant\Composition\CompositionBuilder();$builder->compile();$this->denied(fn()=>$builder->register($this->declaration('acme:x',$noop)),'composition_frozen');
        $h=$this->host();$h->runtime->run($h->selection(),$h->purpose(),fn()=>$this->identity($h,'a'));
    }
    private function snapshots(): void
    {
        $control=(object)['activate'=>null,'restore'=>null,'trace'=>[]];
        $h=$this->host(function($builder,$app)use($control){
            foreach(['h1','h2'] as $id)$builder->register($this->declaration('acme:'.$id,fn()=>new ProductFacility($app['config'],$id,$control),[],[],[],null,'product'));
        });
        $outer=$h->runtime->enter($h->selection(),$h->purpose());
        foreach(['b','a'] as $id)$h->runtime->run($h->selection($id),$h->purpose(),function()use($h,$id){$this->identity($h,$id);$this->check($h->app['config']->get('lab.h1')===$id&&$h->app['config']->get('lab.h2')===$id,'independent product snapshots '.$id);});
        $product=\Tenant\Data\Selection::tenant(new \Tenant\Data\IsolationIdentity('lab','a'),new \Tenant\Data\ProfileRef('laravel-pg-database',1),[new \Tenant\Data\ExtensionRef('lab:product',1,true,['selection'=>'c2'])]);
        $h->runtime->run($product,$h->purpose(),function()use($h){$this->identity($h,'a');$this->check($h->app['config']->get('lab.h1')==='c2'&&$h->app['config']->get('lab.h2')==='c2','same-resource product-only selection c2');});
        $this->check($h->app['config']->get('lab.h1')==='a'&&$h->app['config']->get('lab.h2')==='a','outer snapshots survive product-only nesting');
        $control->activate='h2';$this->denied(fn()=>$h->runtime->enter($h->selection('b'),$h->purpose()),'switch_failed');$this->check(array_slice($control->trace,-2)===['h2','h1'],'throwing h2 reverse journal');$this->identity($h,'a');
        $control->activate=null;$control->restore='h2';$this->denied(fn()=>$h->runtime->run($h->selection('b'),$h->purpose(),fn()=>null),'restore_failed');$this->denied(fn()=>$h->db()->select('select 1'),'runtime_poisoned');
    }
    private function events(): void
    {
        $trace=[];$h=null;
        $o1=new class($trace) implements \Tenant\Contracts\ObservationSink {public function __construct(private array &$trace){}public function observe(\Tenant\Data\RuntimeObservation $o):void{$this->trace[]='O1:'.$o->kind;throw new \RuntimeException('observer');}};
        $o2=new class($trace,$h) implements \Tenant\Contracts\ObservationSink {public function __construct(private array &$trace,private mixed &$host){}public function observe(\Tenant\Data\RuntimeObservation $o):void{$this->trace[]='O2:'.$o->kind.':'.($o->subject?->context?->identity->id??'central');if($this->host!==null){try{$this->host->runtime->enter($this->host->selection(),$this->host->purpose());throw new \LogicException('reentry accepted');}catch(\Tenant\Exceptions\ContextTransitionInProgress){$this->trace[]='reentry_refused';}}}};
        $diag=new class($trace) implements \Tenant\Contracts\DiagnosticSink {public function __construct(private array &$trace){}public function report(\Tenant\Data\Diagnostic $d):void{$this->trace[]='diagnostic_failed';throw new \RuntimeException('diagnostic');}};
        $h=$this->host(null,'file',[$o1,$o2],$diag);$outer=$h->runtime->enter($h->selection(),$h->purpose());$old=$h->db();
        try{$h->runtime->run($h->selection('b'),$h->purpose(),function()use($h,&$trace){$this->identity($h,'b');$trace[]='callback';throw new \LogicException('callback E1');});throw new \RuntimeException('no exception');}catch(\LogicException $e){$this->check($e->getMessage()==='callback E1','original callback unaffected by observers');}
        $this->identity($h,'a');$this->denied(fn()=>$old->select('select 1'),'stale_handle');
        $this->check(in_array('O2:entered:b',$trace,true)&&in_array('O2:callback_failed:b',$trace,true)&&in_array('O2:restored:a',$trace,true),'later observers see B enter/failure/A restore');$this->observations[]=$trace;$h->runtime->restore($outer);
    }
    private function provider(): void
    {
        $h=$this->host();$owner=$h->app->make(\Tenant\Runtime\SafeTenantRuntime::class);$this->check(($h->runtime instanceof RuntimeDecorator?$h->runtime->delegate:$h->runtime)===$owner,'provider/decorator one frame owner');
        $this->denied(fn()=>$h->app->register(\Tenant\Laravel\TenantServiceProvider::class,true),'exclusive_slot_conflict');
        $this->denied(fn()=>$h->app->instance(TenantRuntime::class,new RuntimeDecorator($owner)),'composition_frozen');$this->check($h->app->make(TenantRuntime::class)===$h->runtime,'late binding refusal retains owner');
        $a=$h->app->make(\Tenant\Tenant::class)->enter($h->selection(),$h->purpose());$this->check($h->app->make(\Tenant\Tenant::class)->current()->context->identity->id==='a','facade uses provider owner');$b=$h->runtime->enter($h->selection('b'),$h->purpose());$this->denied(fn()=>$h->runtime->restore($a),'invalid_frame_token');$h->runtime->restore($b);$this->identity($h,'a');$h->runtime->restore($a);
    }
}
