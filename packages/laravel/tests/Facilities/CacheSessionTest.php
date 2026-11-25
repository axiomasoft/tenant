<?php

declare(strict_types=1);

it('CACHE-PROCESS actual IO default and decorator', function (): void {
    foreach ([false,true] as $decorated) {
        $runner = new \TenantLab\CorpusRunner($decorated);
        $result = $runner->execute('CACHE-PROCESS');
        $dir = getenv('TENANT_OBSERVATIONS');
        if (!is_dir($dir)) mkdir($dir,0700,true);
        file_put_contents($dir.'/CACHE-PROCESS-'.($decorated?'decorator':'default').'.json',json_encode($result,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
        expect($runner->assertions)->toBeGreaterThan(0);
    }
});

