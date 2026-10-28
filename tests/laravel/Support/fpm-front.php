<?php
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/HttpScenarios.php';
$manifest=json_decode(file_get_contents(getenv('TENANT_LAB_MANIFEST')),true,flags:JSON_THROW_ON_ERROR);
$probe=new TenantLab\WorkerProbe($manifest,getenv('TENANT_DECORATED')==='1',session:$_SERVER['HTTP_X_SESSION_DRIVER']??'file');$h=$probe->h;
class TenantFpmTerminateProbe
{
    public function handle($request, $next) { return $next($request); }
    public function terminate($request,$response): void {
        $app=Illuminate\Container\Container::getInstance();
        file_put_contents(getenv('TENANT_FPM_ROOT').'/terminated-'.getmypid().'.json',json_encode(['id'=>$app->make(Tenant\Contracts\TenantRuntime::class)->current()->context?->identity->id]));
    }
}
$kernel=TenantLab\HttpScenarios::kernel($h);
$kernel->prependMiddleware(TenantFpmTerminateProbe::class);
$h->app['router']->get('/pid',fn()=>new Illuminate\Http\JsonResponse(['pid'=>getmypid()]));
$held=[];$op=null;
foreach(['poison','stream','leak']as$mode){
 $h->app['router']->get('/'.$mode,function()use($probe,$h,$mode,&$held,&$op){
    $op=$mode;
    if($mode==='poison'){
        $probe->armRestoreFault();
        try{$h->runtime->run($h->selection('b'),$h->purpose(),fn()=>throw new RuntimeException('E1'));}catch(Tenant\Exceptions\ContextRestoreFailed){
            try{$h->db()->select('select 1');throw new RuntimeException('IO escaped');}catch(Tenant\Exceptions\RuntimePoisoned){}
        }
    }elseif($mode==='stream'){$h->storage()->disk()->put('fpm-leak','data');$held[]=$h->storage()->disk()->readStream('fpm-leak');}
    else $h->runtime->enter($h->selection('b'),$h->purpose());
    file_put_contents(getenv('TENANT_FPM_ROOT').'/'.$mode.'.json',json_encode(['pid'=>getmypid(),'afterEffect'=>false]));
    return new Illuminate\Http\Response('must not be sent');
 });
}
$response=TenantLab\HttpScenarios::boundary($h)->handle(Illuminate\Http\Request::capture(),$kernel);
$response->headers->set('X-Tenant-Pid',(string)getmypid());$response->send();
if($op!==null)file_put_contents(getenv('TENANT_FPM_ROOT').'/unexpected-effect','unsafe');
