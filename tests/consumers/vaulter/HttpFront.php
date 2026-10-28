<?php

declare(strict_types=1);
require __DIR__.'/Host.php';

final class VaulterHttpKernel extends Illuminate\Foundation\Http\Kernel
{
    protected $bootstrappers=[];
    protected $middleware=[Illuminate\Session\Middleware\StartSession::class,Tenant\Laravel\Http\ScopeSessionToResource::class,VaulterHttpTerminate::class];
}
final class VaulterHttpTerminate
{
    public function handle($request, Closure $next){return $next($request);}
    public function terminate($request,$response):void
    {
        $context=app(Tenant\Contracts\TenantRuntime::class)->current()->context;
        if($context===null)throw new RuntimeException('HTTP terminate outside target');
        $request->session()->put('terminated',$context->identity->namespace);$request->session()->save();
    }
}
$m=json_decode(file_get_contents(getenv('TENANT_LAB_MANIFEST')),true,flags:JSON_THROW_ON_ERROR);
$h=new TenantVaulter\Host($m,session:getenv('TENANT_SESSION_DRIVER')?:'file');
$h->app['auth']->extend('lab',fn()=>new Illuminate\Auth\RequestGuard(fn()=>null,Illuminate\Http\Request::create('/')));
$h->app['router']->get('/vault',function(Illuminate\Http\Request $request){
    $ns=app(Tenant\Contracts\TenantRuntime::class)->current()->context->identity->namespace;
    $request->session()->put('login',$ns);
    $drive=Vaulter\Drive\Facades\Vaulter::drive(TenantVaulter\HostRecord::query()->findOrFail($ns==='alpha'?'owner-a':'owner-b'))->asSystem('http fixture')->model();
    return new Illuminate\Http\JsonResponse(['namespace'=>$ns,'drive'=>(string)$drive->id,'tenant'=>$drive->tenant_id,'user'=>app('auth')->guard()->user()?->getAuthIdentifier()]);
});
$h->app['router']->get('/session',fn(Illuminate\Http\Request $request)=>new Illuminate\Http\JsonResponse(['login'=>$request->session()->get('login'),'terminated'=>$request->session()->get('terminated'),'user'=>app('auth')->guard()->user()?->getAuthIdentifier()]));
$kernel=new VaulterHttpKernel($h->app,$h->app['router']);
$boundary=new Tenant\Laravel\Http\ContextRequestBoundary($h->runtime,$h->app,function($request)use($h){
    return $h->selection(match($request->getHost()){'a.example.test'=>'alpha','b.example.test'=>'beta',default=>throw new Tenant\Exceptions\ContextDenied});
});
try{$response=$boundary->handle(Illuminate\Http\Request::capture(),$kernel);$response->send();}
catch(Throwable $e){http_response_code(403);echo json_encode(['refused'=>$e::class]);}
