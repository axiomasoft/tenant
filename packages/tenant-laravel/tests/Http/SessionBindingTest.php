<?php

declare(strict_types=1);

it('scopes a live session Store and authenticates only after resource binding', function (): void {
    $runner=new \TenantLab\CorpusRunner();
    $h=$runner->host();
    $h->runtime->run($h->selection(),$h->purpose(),function()use($h,$runner){
        $store=$h->app['session']->driver();$store->start();$store->put('login','central');$store->regenerateToken();$old=$store->token();
        $request=\Illuminate\Http\Request::create('/');$request->setLaravelSession($store);
        $scope=new \Tenant\Laravel\Http\ScopeSessionToResource($h->runtime);
        $scope->handle($request,function($request)use($runner,$old){$runner->check($request->session()->get('login')===null&&$request->session()->token()!==$old,'first binding cleared auth/token before admission');return new \Illuminate\Http\Response('ok');});
        $store->put('login','user-a');$scope->handle($request,fn()=>new \Illuminate\Http\Response('ok'));
        expect($store->get('login'))->toBe('user-a');
        $store->put(\Tenant\Laravel\Http\ScopeSessionToResource::KEY,['lab','resource:b','incarnation:b']);
        try{$scope->handle($request,fn()=>new \Illuminate\Http\Response('unexpected'));throw new \LogicException('accepted');}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){expect($e->getStatusCode())->toBe(403);}
        expect($store->get('login'))->toBeNull();
    });
});
