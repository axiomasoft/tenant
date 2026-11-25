<?php

declare(strict_types=1);

it('EXT01-PLUGIN actual IO default and decorator', function (): void {
    foreach ([false,true] as $decorated) {
        $runner = new \TenantLab\CorpusRunner($decorated);
        $result = $runner->execute('EXT01-PLUGIN');
        $dir = getenv('TENANT_OBSERVATIONS');
        if (!is_dir($dir)) mkdir($dir,0700,true);
        file_put_contents($dir.'/EXT01-PLUGIN-'.($decorated?'decorator':'default').'.json',json_encode($result,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
        expect($runner->assertions)->toBeGreaterThan(0);
    }
});

it('EXT02-NEGATIVE actual IO default and decorator', function (): void {
    foreach ([false,true] as $decorated) {
        $runner = new \TenantLab\CorpusRunner($decorated);
        $result = $runner->execute('EXT02-NEGATIVE');
        $dir = getenv('TENANT_OBSERVATIONS');
        if (!is_dir($dir)) mkdir($dir,0700,true);
        file_put_contents($dir.'/EXT02-NEGATIVE-'.($decorated?'decorator':'default').'.json',json_encode($result,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
        expect($runner->assertions)->toBeGreaterThan(0);
    }
});

it('EXT02-ORDER actual IO default and decorator', function (): void {
    foreach ([false,true] as $decorated) {
        $runner = new \TenantLab\CorpusRunner($decorated);
        $result = $runner->execute('EXT02-ORDER');
        $dir = getenv('TENANT_OBSERVATIONS');
        if (!is_dir($dir)) mkdir($dir,0700,true);
        file_put_contents($dir.'/EXT02-ORDER-'.($decorated?'decorator':'default').'.json',json_encode($result,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
        expect($runner->assertions)->toBeGreaterThan(0);
    }
});

