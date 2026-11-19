<?php

declare(strict_types=1);

it('CENTRAL-01 actual framework worker default and decorator', function (): void {
    foreach ([false,true] as $decorated) {
        $process = new \Symfony\Component\Process\Process([PHP_BINARY, TENANT_SOURCE_ROOT.'/tests/laravel/Support/worker-front.php', 'CENTRAL-01', $decorated?'1':'0'], TENANT_SOURCE_ROOT, timeout:120);
        $process->mustRun();
        $result=json_decode($process->getOutput(),true,flags:JSON_THROW_ON_ERROR);
        $dir=getenv('TENANT_OBSERVATIONS');if(!is_dir($dir))mkdir($dir,0700,true);
        file_put_contents($dir.'/CENTRAL-01-'.($decorated?'decorator':'default').'.log',$process->getOutput().$process->getErrorOutput());
        file_put_contents($dir.'/CENTRAL-01-'.($decorated?'decorator':'default').'.json',json_encode($result,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
        expect($result['assertions'])->toBeGreaterThan(0);
    }
});
