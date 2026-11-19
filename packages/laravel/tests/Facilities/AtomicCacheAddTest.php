<?php

declare(strict_types=1);

it('preserves atomic Redis add across independent guarded processes and rejects stale claims', function (): void {
    $m=json_decode(file_get_contents(getenv('TENANT_LAB_MANIFEST')),true,flags:JSON_THROW_ON_ERROR);
    $h=new \TenantLab\LabHost($m);$key='claim-'.bin2hex(random_bytes(8));$processes=[];
    foreach(['one','two'] as $claimant){
        $p=new \Symfony\Component\Process\Process([PHP_BINARY,TENANT_SOURCE_ROOT.'/tests/laravel/Support/cache-add-process.php',$key,$claimant],TENANT_SOURCE_ROOT,timeout:30);
        $p->start();$processes[]=$p;
    }
    $deadline=microtime(true)+15;
    while(!file_exists($m['root'].'/'.$key.'.readyone')||!file_exists($m['root'].'/'.$key.'.readytwo')){
        if(microtime(true)>$deadline)throw new \RuntimeException('claimants not ready');usleep(1000);
    }
    file_put_contents($m['root'].'/'.$key.'.go','go');$results=[];
    foreach($processes as $p){$p->wait();expect($p->isSuccessful())->toBeTrue($p->getErrorOutput());$results[]=json_decode($p->getOutput(),true,flags:JSON_THROW_ON_ERROR);}
    expect(array_sum(array_column($results,'claimed')))->toBe(1);
    expect($results[0]['pid'])->not->toBe($results[1]['pid']);
    $a=$h->runtime->enter($h->selection(),$h->purpose());$held=$h->cache();
    $winner=array_values(array_filter($results,fn($r)=>$r['claimed']))[0];
    expect($held->get($key))->toBe($winner['claimant']);
    $b=$h->runtime->enter($h->selection('b'),$h->purpose());$h->runtime->restore($b);
    expect(fn()=>$held->add($key,'stale',60))->toThrow(\Tenant\Exceptions\Runtime\StaleHandle::class);
    expect($h->cache()->add($key,'duplicate',60))->toBeFalse();
    expect($h->cache()->get($key))->toBe($winner['claimant']);$h->cache()->forget($key);$h->runtime->restore($a);
    file_put_contents(getenv('TENANT_OBSERVATIONS').'/atomic-add.json',json_encode($results,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
});
