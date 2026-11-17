<?php

declare(strict_types=1);

it('keeps the transaction lease until commit and releases it before afterCommit callbacks',function():void{
    $m=json_decode(file_get_contents(getenv('TENANT_LAB_MANIFEST')),true,flags:JSON_THROW_ON_ERROR);
    foreach(['manual','helper'] as $mode){
        $h=new \TenantLab\LabHost($m);$marker='tx_'.bin2hex(random_bytes(5));$callbacks=0;
        $h->runtime->run($h->selection('a'),$h->purpose(),function()use($h,$mode,$marker,&$callbacks):void{
            $db=$h->db();
            $body=function()use($db,$h,$marker,&$callbacks):void{
                try{$h->runtime->enter($h->selection('b'),$h->purpose());throw new RuntimeException('Open transaction allowed a switch');}catch(\Tenant\Exceptions\Runtime\OpenResource){}
                $db->afterCommit(function()use($h,$marker,&$callbacks):void{$h->runtime->run($h->selection('b'),$h->purpose(),function()use($h,$marker,&$callbacks):void{$h->db()->table('probes')->insert(['name'=>$marker,'value'=>'callback-b']);$callbacks++;});});
                $db->table('probes')->insert(['name'=>$marker,'value'=>'committed-a']);
            };
            if($mode==='manual'){$db->beginTransaction();$body();$db->commit();}else{$db->transaction($body);}
        });
        expect($callbacks)->toBe(1)->and($h->sql('a',"select value from probes where name='$marker'")[0]->value)->toBe('committed-a')->and($h->sql('b',"select value from probes where name='$marker'")[0]->value)->toBe('callback-b')->and($h->runtime->current()->kind)->toBe('missing');
    }
});

it('retains the outer lease on savepoint rollback and discards rolled back callbacks',function():void{
    $m=json_decode(file_get_contents(getenv('TENANT_LAB_MANIFEST')),true,flags:JSON_THROW_ON_ERROR);$h=new \TenantLab\LabHost($m);$callbacks=0;
    $h->runtime->run($h->selection('a'),$h->purpose(),function()use($h,&$callbacks):void{
        $db=$h->db();$db->beginTransaction();$db->beginTransaction();$db->afterCommit(function()use(&$callbacks):void{$callbacks++;});$db->rollBack();
        try{$h->runtime->enter($h->selection('b'),$h->purpose());throw new RuntimeException('Savepoint rollback released outer lease');}catch(\Tenant\Exceptions\Runtime\OpenResource){}
        $db->rollBack();$h->runtime->run($h->selection('b'),$h->purpose(),fn()=>null);
    });
    expect($callbacks)->toBe(0)->and($h->runtime->current()->kind)->toBe('missing');
});

it('releases the committed lease before callbacks on nested transaction records', function (): void {
    $manifest = json_decode(file_get_contents(getenv('TENANT_LAB_MANIFEST')), true, flags: JSON_THROW_ON_ERROR);
    foreach (['manual', 'helper'] as $mode) {
        $host = new \TenantLab\LabHost($manifest);
        $calls = 0;
        $host->runtime->run($host->selection('a'), $host->purpose(), function () use ($host, $mode, &$calls): void {
            $connection = $host->db();
            $body = function () use ($connection, $host, &$calls): void {
                $connection->transaction(function () use ($connection, $host, &$calls): void {
                    $connection->afterCommit(function () use ($host, &$calls): void {
                        $host->runtime->run($host->selection('b'), $host->purpose(), function () use ($host, &$calls): void {
                            expect($host->db()->table('probes')->where('name', 'identity')->value('value'))->toBe('b');
                            $calls++;
                        });
                    });
                });
                expect(fn () => $host->runtime->enter($host->selection('b'), $host->purpose()))
                    ->toThrow(\Tenant\Exceptions\Runtime\OpenResource::class);
            };
            if ($mode === 'manual') {
                $connection->beginTransaction();
                $body();
                $connection->commit();
            } else {
                $connection->transaction($body);
            }
        });
        expect($calls)->toBe(1)->and($host->runtime->current()->kind)->toBe('missing');
    }
});
