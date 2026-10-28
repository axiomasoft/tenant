<?php

declare(strict_types=1);

it('EVENT-TRACE-01 actual IO default and decorator', function (): void {
    foreach ([false,true] as $decorated) {
        $runner = new \TenantLab\CorpusRunner($decorated);
        $result = $runner->execute('EVENT-TRACE-01');
        $dir = getenv('TENANT_OBSERVATIONS');
        if (!is_dir($dir)) mkdir($dir,0700,true);
        file_put_contents($dir.'/EVENT-TRACE-01-'.($decorated?'decorator':'default').'.json',json_encode($result,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
        expect($runner->assertions)->toBeGreaterThan(0);
    }
});

it('EXT04-EVENTS actual IO default and decorator', function (): void {
    foreach ([false,true] as $decorated) {
        $runner = new \TenantLab\CorpusRunner($decorated);
        $result = $runner->execute('EXT04-EVENTS');
        $dir = getenv('TENANT_OBSERVATIONS');
        if (!is_dir($dir)) mkdir($dir,0700,true);
        file_put_contents($dir.'/EXT04-EVENTS-'.($decorated?'decorator':'default').'.json',json_encode($result,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
        expect($runner->assertions)->toBeGreaterThan(0);
    }
});

