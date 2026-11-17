<?php

declare(strict_types=1);

it('guards recreated file and Redis session drivers across frame restoration', function (): void {
    $manifest=json_decode(file_get_contents(getenv('TENANT_LAB_MANIFEST')),true,flags:JSON_THROW_ON_ERROR);
    foreach(['file','redis'] as $driver){
        $h=new \TenantLab\LabHost($manifest,$driver);$manager=$h->app['session'];
        $manager->forgetDrivers();$store=$manager->driver();
        expect($store->getHandler())->toBeInstanceOf(\Tenant\Laravel\Internal\Facilities\GuardedSessionHandler::class);
        expect(fn()=>$store->getHandler()->read(str_repeat('f',40)))->toThrow(\Tenant\Exceptions\Context\ScopeRequired::class);
        $a=$h->runtime->enter($h->selection(),$h->purpose());
        $manager->forgetDrivers();$store=$manager->driver();$held=$store->getHandler();
        $store->put('recreated','A');$store->save();$sessionId=$store->getId();
        $b=$h->runtime->enter($h->selection('b'),$h->purpose());
        expect(fn()=>$held->read($sessionId))->toThrow(\Tenant\Exceptions\Runtime\StaleHandle::class);
        $manager->forgetDrivers();$inB=$manager->driver();$heldB=$inB->getHandler();
        $inB->put('recreated','B');$inB->save();
        $h->runtime->restore($b);
        expect(fn()=>$held->read($sessionId))->toThrow(\Tenant\Exceptions\Runtime\StaleHandle::class);
        expect(fn()=>$heldB->read($sessionId))->toThrow(\Tenant\Exceptions\Runtime\StaleHandle::class);
        $manager->forgetDrivers();$fresh=$manager->driver();
        expect($fresh->getHandler()->read($sessionId))->toContain('A');
        $h->runtime->restore($a);
        $manager->forgetDrivers();
        expect(fn()=>$manager->driver()->getHandler()->read($sessionId))->toThrow(\Tenant\Exceptions\Context\ScopeRequired::class);
        $c=$h->runtime->enter($h->central(),$h->purpose());$manager->forgetDrivers();
        expect(fn()=>$manager->driver()->getHandler()->read($sessionId))->toThrow(\Tenant\Exceptions\Context\ContextDenied::class);
        $h->runtime->restore($c);
    }
});
