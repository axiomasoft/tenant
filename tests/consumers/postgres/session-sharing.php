<?php

declare(strict_types=1);

putenv('COREX_PROBE_HOST_ONLY=1');
require getenv('COREX_CONSUMER_CHECKOUT').'/packages/tenancy/tests/Pg/TenantRuntimeConsumerProbe.php';

foreach (['file','redis'] as $driver) {
    $h=corexProbeHost($m,$driver);$app=$h->app;$manager=$app->make(CoreX\Context\Contracts\TenancyManager::class);
    $a=new CoreX\Context\Data\AccountRef('00000000-0000-4000-8000-000000000001','a','active',[],[]);
    $b=new CoreX\Context\Data\AccountRef('00000000-0000-4000-8000-000000000002','b','active',[],[]);
    $w=new CoreX\Context\Data\WorkspaceRef('w','w','team','/w',null);
    $manager->runFor($a,null,function()use($app,$manager,$a,$b,$w,$driver):void {
        $session=$app['session']->driver();$session->start();$session->put('login','A');$session->put('_token','original-csrf');
        $id=$session->getId();$held=$session->getHandler();$childHandler=null;
        $manager->runFor($a,$w,function()use($manager,$session,$held,$id,&$childHandler,$driver):void {
            check($session->get('login')==='A'&&$session->get('_token')==='original-csrf'&&$session->getId()===$id,'F2 live same-resource session/CSRF/id '.$driver);
            try{$held->read($id);throw new LogicException('Old handler usable');}catch(Tenant\Exceptions\StaleHandle){}
            $session->put('child','committed');$session->flash('notice','workspace-flash');$childHandler=$session->getHandler();
        });
        check($session->get('child')==='committed'&&$session->get('notice')==='workspace-flash','F2 child session writes hand back to same-resource parent '.$driver);
        try{$childHandler->read($id);throw new LogicException('Child handler usable after restoration');}catch(Tenant\Exceptions\StaleHandle){}
        check(is_string($session->getHandler()->read($id)),'F2 restored parent handler has fresh guarded generation '.$driver);
        $manager->runFor($b,null,function()use($session,$id,$driver):void{
            check($session->get('login')===null&&$session->get('child')===null&&$session->getId()!==$id,'F2 different account still flushes session namespace '.$driver);
            $session->put('login','B');$session->put('foreign','B-only');$session->save();
        });
        check($session->get('login')==='A'&&$session->get('child')==='committed'&&$session->get('foreign')===null,'F2 cross-account restore keeps A and discards B mutations '.$driver);
        $policy=$app->make('corex.test.policy');$policy->workspace=false;
        try {
            try{$manager->runFor($a,$w,fn()=>throw new LogicException('Denied callback ran'));throw new LogicException('Workspace admission bypassed');}
            catch(Tenant\Exceptions\SelectionLost){}
            check($session->get('login')==='A'&&$session->get('child')==='committed','F2 failed fresh workspace admission preserves parent session '.$driver);
        } finally {$policy->workspace=true;}
        $manager->setWorkspace($w);$manager->setWorkspace(null);
        check($session->get('login')==='A'&&$session->get('child')==='committed','F1 imperative select/clear preserves live account session '.$driver);
        $session->save();
    });
    check($h->runtime->current()->kind==='missing','F1 no leaked token after session callback '.$driver);
}
echo json_encode(['status'=>'passed','skipped'=>0,'drivers'=>['file','redis'],'php'=>PHP_VERSION],JSON_THROW_ON_ERROR),PHP_EOL;
