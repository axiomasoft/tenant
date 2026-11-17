<?php

declare(strict_types=1);

namespace TenantLab;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Tenant\Laravel\Http\{ContextRequestBoundary, ScopeSessionToResource};
use Symfony\Component\Process\Process;

final class HttpKernel extends \Illuminate\Foundation\Http\Kernel
{
    protected $bootstrappers = [];
    protected $middleware = [\Illuminate\Session\Middleware\StartSession::class, ScopeSessionToResource::class, TerminateProbe::class];
}
final class HttpErrors extends \Illuminate\Foundation\Exceptions\Handler
{
    public function report(\Throwable $e) {}
    public function render($request, \Throwable $e) { return new Response('failure', $e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface ? $e->getStatusCode() : 500); }
}
final class TerminateProbe
{
    public function handle(Request $request, \Closure $next) { return $next($request); }
    public function terminate(Request $request, $response): void
    {
        $app=\Illuminate\Container\Container::getInstance();
        $context=$app->make(\Tenant\Contracts\Runtime\TenantRuntime::class)->current()->context;
        if($context===null)throw new \RuntimeException('terminate outside frame');
        $request->session()->put('terminated',$context->identity->id);
        $request->session()->save();
        $app['db']->connection()->statement("INSERT INTO probes (name,value) VALUES ('http-terminate',?) ON CONFLICT(name) DO UPDATE SET value=EXCLUDED.value",[$context->identity->id]);
    }
}
final class HttpScenarios
{
    public function __construct(private CorpusRunner $checks) {}
    public static function kernel(LabHost $h): HttpKernel
    {
        $app=$h->app;
        \Illuminate\Support\Facades\Facade::clearResolvedInstances();
        \Illuminate\Support\Facades\Facade::setFacadeApplication($app);
        $app->singleton(\Illuminate\Contracts\Debug\ExceptionHandler::class,HttpErrors::class);
        $app['router']->get('/put/{value}',function(Request $request,string $value){$request->session()->put('login',$value);return new \Illuminate\Http\JsonResponse(['login'=>$value,'bound'=>$request->session()->get(ScopeSessionToResource::KEY)]);});
        $app['router']->get('/get',fn(Request $request)=>new \Illuminate\Http\JsonResponse(['login'=>$request->session()->get('login'),'bound'=>$request->session()->get(ScopeSessionToResource::KEY),'terminated'=>$request->session()->get('terminated')]));
        $app['router']->get('/boom',function(Request $request){$request->session()->put('error','before error');throw new \RuntimeException('downstream');});
        $app->bind(\Illuminate\Contracts\Http\Kernel::class,fn()=>new HttpKernel($app,$app['router']));
        return $app->make(\Illuminate\Contracts\Http\Kernel::class);
    }
    public static function boundary(LabHost $h): ContextRequestBoundary
    {
        return new ContextRequestBoundary($h->runtime,$h->app,function(Request $request)use($h){
            $id=match($request->getHost()){'a.example.test'=>'a','b.example.test'=>'b',default=>throw new \Tenant\Exceptions\ContextDenied()};return $h->selection($id);
        });
    }
    public function run(array $manifest,bool $decorated): void
    {
        foreach(['file','redis'] as $driver){
            $h=$this->checks->host(null,$driver);
            $h->app['auth']->extend('lab',fn()=>new \Illuminate\Auth\RequestGuard(fn()=>null,Request::create('/')));
            $oldGuard=$h->app['auth']->guard();$oldGuard->setUser(new \Illuminate\Auth\GenericUser(['id'=>'central-user']));
            $kernel=self::kernel($h);$boundary=self::boundary($h);
            $route=$h->app['router']->get('/priority',fn()=>new Response('ok'))->middleware([\Illuminate\View\Middleware\ShareErrorsFromSession::class,ScopeSessionToResource::class,\Illuminate\Session\Middleware\StartSession::class]);
            $order=$h->app['router']->gatherRouteMiddleware($route);
            $this->checks->check(array_search(\Illuminate\Session\Middleware\StartSession::class,$order,true)<array_search(ScopeSessionToResource::class,$order,true)&&array_search(ScopeSessionToResource::class,$order,true)<array_search(\Illuminate\View\Middleware\ShareErrorsFromSession::class,$order,true),'assembled route order session then binding then errors/auth');
            $visit=function(string $host,string $path,?string $cookie=null)use($boundary,$kernel){
                $request=Request::create('http://'.$host.$path,'GET',cookies:$cookie===null?[]:['tenant_session'=>$cookie]);
                $response=$boundary->handle($request,$kernel);$cookies=$response->headers->getCookies();
                return [$response->getStatusCode(),isset($cookies[0])?$cookies[0]->getValue():null,json_decode($response->getContent(),true)];
            };
            [$status,$id]=$visit('a.example.test','/put/user-a');
            $this->checks->check($h->app['auth']->guard()!==$oldGuard&&$h->app['auth']->guard()->user()===null,'resident auth guards flushed on boundary '.$driver);$this->checks->check($status===200&&is_string($id),'actual '.$driver.' HTTP kernel writes session');
            [$status,,$body]=$visit('a.example.test','/get',$id);$this->checks->check($status===200&&$body['login']==='user-a'&&$body['terminated']==='a','same resource login + terminate persisted '.$driver);
            [$status,,$body]=$visit('b.example.test','/get',$id);$this->checks->check($status===200&&$body['login']===null&&$body['bound'][1]==='resource:b','foreign cookie cannot authenticate B '.$driver);
            // Copy the real A payload into B: marker, not cookie ID alone, refuses it.
            $a=$h->owner->descriptor('a');$b=$h->owner->descriptor('b');
            if($driver==='file'){
                $this->checks->check(is_file($a->sessionRoot.'/'.$id)&&(fileperms($a->sessionRoot)&0777)===0700&&!is_file($manifest['root'].'/central-sessions/'.$id),'file session path0700 + central untouched');
                copy($a->sessionRoot.'/'.$id,$b->sessionRoot.'/'.$id);
            }else{
                $redis=$h->app['redis']->connection();$payload=$redis->get($a->prefix.'session:'.$id);$this->checks->check(is_string($payload),'real Redis session payload');$redis->setex($b->prefix.'session:'.$id,120,$payload);
            }
            [$status]=$visit('b.example.test','/get',$id);$this->checks->check($status===403,'copied foreign payload denied '.$driver);
            [$status]=$visit('a.example.test','/boom');$this->checks->check($status===500&&$h->runtime->current()->kind==='missing','error terminates and closes frame '.$driver);
            [$status,,$body]=$visit('b.example.test','/get');$this->checks->check($status===200&&$body['login']===null,'resident store attributes cleared '.$driver);
            $this->checks->denied(fn()=>$visit('unknown.example.test','/get'),'denied');
            // Direct middleware first-bind drops preexisting auth attributes/token.
            $h->runtime->run($h->selection(),$h->purpose(),function()use($h){
                $session=$h->app['session']->driver();$session->flush();$session->put('login','central-user');$session->regenerateToken();$old=$session->token();$request=Request::create('/');$request->setLaravelSession($session);
                (new ScopeSessionToResource($h->runtime))->handle($request,fn()=>new Response('ok'));
                $this->checks->check($session->get('login')===null&&$old!==$session->token(),'first binding invalidates attrs + rotates CSRF');
            });
            $this->socket($manifest,$driver,$decorated);
        }
    }
    private function socket(array $manifest,string $driver,bool $decorated): void
    {
        $socket=stream_socket_server('tcp://127.0.0.1:0');$port=(int)substr(strrchr(stream_socket_get_name($socket,false),':'),1);fclose($socket);
        $process=new Process([PHP_BINARY,'-S','127.0.0.1:'.$port,TENANT_SOURCE_ROOT.'/tests/laravel/Support/http-front.php'],TENANT_SOURCE_ROOT,['TENANT_LAB_MANIFEST'=>getenv('TENANT_LAB_MANIFEST'),'TENANT_LANE_AUTOLOAD'=>TENANT_LANE_AUTOLOAD,'TENANT_SESSION_DRIVER'=>$driver,'TENANT_DECORATED'=>$decorated?'1':'0','TENANT_USE_SOURCE'=>getenv('TENANT_USE_SOURCE')?:'0']);
        $process->start();
        try{
            for($i=0;$i<100;$i++){if(!$process->isRunning())throw new \RuntimeException($process->getErrorOutput());if(str_contains($process->getErrorOutput(),'Development Server'))break;usleep(20000);}
            $fetch=function(string $path,?string $cookie=null)use($port){$headers="Host: a.example.test\r\n".($cookie===null?'':"Cookie: tenant_session=$cookie\r\n");$body=file_get_contents('http://127.0.0.1:'.$port.$path,false,stream_context_create(['http'=>['header'=>$headers,'ignore_errors'=>true]]));return [$body,http_get_last_response_headers()];};
            [$body,$headers]=$fetch('/put/socket-user');$id=null;foreach($headers as $header)if(preg_match('/^Set-Cookie: tenant_session=([^;]+)/i',$header,$m))$id=urldecode($m[1]);
            $this->checks->check($id!==null&&json_decode($body,true)['login']==='socket-user','real socket HTTP writes '.$driver.' pid '.$process->getPid());
            [$body]=$fetch('/get',$id);$v=json_decode($body,true);$this->checks->check($v['login']==='socket-user'&&$v['terminated']==='a','real socket HTTP read + terminate '.$driver);
        }finally{$process->stop();}
    }
}
