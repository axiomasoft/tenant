<?php

declare(strict_types=1);

require __DIR__.'/bootstrap.php';

use Tenant\Data\{BindingExpectation,ContextState,FacetBindings,IsolationContext,IsolationIdentity,ProfileRef,Purpose,ResolvedIsolation,Selection};

$manifest=$p54Manifest;$root=$manifest['root'].'/session-facility-'.bin2hex(random_bytes(5));
if (!mkdir($root,0700)) throw new RuntimeException('Isolated session root unavailable');
$count=0;
function sessionCheck(bool $ok,string $message):void { global $count; if (!$ok) throw new RuntimeException($message);$count++;echo 'PASS F2 '.$message.PHP_EOL; }
foreach (['file','redis'] as $driver) {
    $app=new Illuminate\Container\Container();$app->instance('config',new Illuminate\Config\Repository([
        'session'=>['driver'=>$driver,'files'=>$root.'/baseline','store'=>$driver==='file'?null:'sessions','connection'=>'default','cookie'=>'facility_test','lifetime'=>120,'lottery'=>[0,100],'serialization'=>'php'],
        'cache'=>['default'=>'sessions','stores'=>['sessions'=>['driver'=>'redis','connection'=>'default','prefix'=>'baseline:']]],
    ]));
    $app->instance('files',new Illuminate\Filesystem\Filesystem());
    $app->instance('redis',new Illuminate\Redis\RedisManager($app,'predis',['default'=>['host'=>'127.0.0.1','port'=>$manifest['ports']['redis'],'database'=>0]]));
    $sessions=new Illuminate\Session\SessionManager($app);$cache=new Illuminate\Cache\CacheManager($app);$app->instance('session',$sessions);$app->instance('cache',$cache);
    $config=new Tenant\Laravel\Internal\FrameworkConfig(static fn()=>$app['config']);$runtime=null;
    $generation=new Tenant\Laravel\Internal\Facilities\SessionGeneration(static function()use(&$runtime){return $runtime;});
    $factory=new class($sessions,$cache,$app,$config,$generation) implements Tenant\Contracts\ParticipantFactory {
        public function __construct(private $sessions,private $cache,private $app,private $config,private $generation){}
        public function create():Tenant\Contracts\Facility { return new Tenant\Laravel\Internal\Facilities\SessionFacility($this->sessions,$this->cache,$this->app,$this->config,$this->generation); }
    };
    $resolver=new class($root,$driver) implements Tenant\Contracts\ContextResolver {
        public function __construct(private string $root,private string $driver){}
        public function resolve(Selection $s,Purpose $p):ResolvedIsolation {
            $id=$s->identity->id;$inc=$s->expectedBinding?->incarnation??'i1';
            return new ResolvedIsolation(ContextState::active(new IsolationContext('tenant',$s->identity,$s->expectedProfile,new BindingExpectation('resource:'.$id,$inc,'r1'))),new FacetBindings([
                'tenant:database'=>new Tenant\Laravel\Data\ResourceDescriptor(['driver'=>'pgsql','database'=>'unavailable_test'],'facility:'.basename($this->root).':'.$this->driver.':'.$id.':'.$inc.':',$this->root.'/durable',$this->root.'/ephemeral',$this->root.'/'.$this->driver.'/'.$id.'/'.$inc)]));
        }
    };
    $builder=new Tenant\Composition\CompositionBuilder(['session-test'=>['version'=>1,'modes'=>['tenant']]],[]);
    $builder->register(new Tenant\Data\ParticipantDeclaration('test:session',1,'resource',[],[],[],[],[],null,$factory));
    $runtime=new Tenant\Runtime\SafeTenantRuntime($resolver,$builder->compile());
    $select=static fn(string $id,string $inc='i1')=>Selection::tenant(new IsolationIdentity('test',$id),new ProfileRef('session-test',1),[],new BindingExpectation('resource:'.$id,$inc,'r1'));
    // This facility-level host supplies an already resolved Store; production
    // late-driver wrapping is separately exercised through the actual provider.
    $sessions->driver();
    $a=$runtime->enter($select('A'),new Purpose('test:session'));$session=$sessions->driver();$session->start();$session->put('login','A');$session->put('_token','csrf');$id=$session->getId();$held=$session->getHandler();
    $nested=$runtime->enter($select('A'),new Purpose('test:session'));
    sessionCheck($session->get('login')==='A'&&$session->get('_token')==='csrf'&&$session->getId()===$id,'same binding retains live session '.$driver);
    try{$held->read($id);throw new LogicException('Stale handler usable');}catch(Tenant\Exceptions\StaleHandle){}
    $session->put('nested','saved');$session->flash('notice','flash');$runtime->restore($nested);
    sessionCheck($session->get('nested')==='saved'&&$session->get('notice')==='flash','same binding returns child mutations '.$driver);
    foreach ([$select('B'),$select('A','i2')] as $different) {
        $child=$runtime->enter($different,new Purpose('test:session'));
        sessionCheck($session->get('login')===null&&$session->getId()!==$id,'different identity/incarnation clears live attributes '.$driver);
        $session->put('foreign','denied');$runtime->restore($child);
        sessionCheck($session->get('login')==='A'&&$session->get('foreign')===null,'different identity/incarnation cannot hand back child writes '.$driver);
    }
    $runtime->restore($a);sessionCheck($runtime->current()->kind==='missing','facility frame closes '.$driver);
}
echo json_encode(['status'=>'passed','assertions'=>$count,'skipped'=>0,'php'=>PHP_VERSION,'laravel'=>Composer\InstalledVersions::getPrettyVersion('laravel/framework'),'fixture'=>'Trusted session-only resolver, no database configured or physical owner claim; actual file and isolated Redis IO'],JSON_THROW_ON_ERROR),PHP_EOL;
