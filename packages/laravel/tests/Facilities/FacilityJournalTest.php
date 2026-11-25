<?php

declare(strict_types=1);

it('EXT03-SNAPSHOTS actual IO default and decorator', function (): void {
    foreach ([false,true] as $decorated) {
        $runner = new \TenantLab\CorpusRunner($decorated);
        $result = $runner->execute('EXT03-SNAPSHOTS');
        $dir = getenv('TENANT_OBSERVATIONS');
        if (!is_dir($dir)) mkdir($dir,0700,true);
        file_put_contents($dir.'/EXT03-SNAPSHOTS-'.($decorated?'decorator':'default').'.json',json_encode($result,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
        expect($runner->assertions)->toBeGreaterThan(0);
    }
});

it('STATE-03 actual IO default and decorator', function (): void {
    foreach ([false,true] as $decorated) {
        $runner = new \TenantLab\CorpusRunner($decorated);
        $result = $runner->execute('STATE-03');
        $dir = getenv('TENANT_OBSERVATIONS');
        if (!is_dir($dir)) mkdir($dir,0700,true);
        file_put_contents($dir.'/STATE-03-'.($decorated?'decorator':'default').'.json',json_encode($result,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
        expect($runner->assertions)->toBeGreaterThan(0);
    }
});

it('STATE-04 actual IO default and decorator', function (): void {
    foreach ([false,true] as $decorated) {
        $runner = new \TenantLab\CorpusRunner($decorated);
        $result = $runner->execute('STATE-04');
        $dir = getenv('TENANT_OBSERVATIONS');
        if (!is_dir($dir)) mkdir($dir,0700,true);
        file_put_contents($dir.'/STATE-04-'.($decorated?'decorator':'default').'.json',json_encode($result,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
        expect($runner->assertions)->toBeGreaterThan(0);
    }
});

